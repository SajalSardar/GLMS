<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportQuestionsRequest;
use App\Http\Requests\Api\V1\ReorderQuestionsRequest;
use App\Http\Requests\Api\V1\StoreQuestionRequest;
use App\Http\Requests\Api\V1\UpdateQuestionRequest;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\Chapter;
use App\Models\Question;
use App\Support\QuestionCsvImporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = Question::query()
            ->with(['chapter', 'options'])
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->when(
                ! $request->user()?->hasPermission('questions.update'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return QuestionResource::collection($questions);
    }

    public function reorder(ReorderQuestionsRequest $request): JsonResponse
    {
        $this->authorize('reorder', Question::class);

        DB::transaction(function () use ($request) {
            foreach ($request->validated()['ordered_ids'] as $index => $id) {
                Question::where('id', $id)->where('chapter_id', $request->validated()['chapter_id'])->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    public function import(ImportQuestionsRequest $request): JsonResponse
    {
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if ($header !== QuestionCsvImporter::HEADERS) {
            fclose($handle);

            return response()->json([
                'message' => 'The CSV header row does not match the expected column order.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $imported = 0;
        $rows = [];
        $rowNumber = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($line) !== count($header)) {
                $rows[] = ['row' => $rowNumber, 'status' => 'error', 'message' => 'Row has a different number of columns than the header.'];

                continue;
            }

            $row = array_combine($header, $line);

            try {
                $parsed = QuestionCsvImporter::parseRow($row);
                $chapter = Chapter::find($parsed['question']['chapter_id']);
                $classVersion = $chapter?->book->classVersion;

                if (! $classVersion) {
                    // Either the chapter doesn't exist, or (now that Chapter
                    // is national content and no longer tenant-scoped) it
                    // belongs to a class version outside this actor's own
                    // tenant — ClassVersion is still TenantScoped, so the
                    // relation resolves to null for that case instead of
                    // Chapter::find() itself returning null as it used to.
                    throw new InvalidArgumentException('chapter_id does not exist or is not accessible.');
                }

                $question = DB::transaction(function () use ($parsed, $classVersion) {
                    $question = Question::create([
                        ...$parsed['question'],
                        'school_id' => $classVersion->school_id,
                        'district_id' => $classVersion->district_id,
                    ]);

                    foreach ($parsed['options'] as $option) {
                        $question->options()->create($option);
                    }

                    return $question;
                });

                $imported++;
                $rows[] = ['row' => $rowNumber, 'status' => 'imported', 'question_id' => $question->id];
            } catch (InvalidArgumentException|QueryException $e) {
                $message = $e instanceof QueryException
                    ? 'Could not save this row: '.$e->getMessage()
                    : $e->getMessage();

                $rows[] = ['row' => $rowNumber, 'status' => 'error', 'message' => $message];
            }
        }

        fclose($handle);

        return response()->json([
            'imported' => $imported,
            'failed' => count($rows) - $imported,
            'rows' => $rows,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Question::class);

        $questions = Question::query()
            ->with('options')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->when(
                ! $request->user()?->hasPermission('questions.update'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return response()->streamDownload(
            function () use ($questions) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, QuestionCsvImporter::HEADERS);

                foreach ($questions as $question) {
                    fputcsv($handle, QuestionCsvImporter::toRow($question));
                }

                fclose($handle);
            },
            'questions-export.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function show(Question $question): QuestionResource
    {
        if (! $question->is_active && ! request()->user()?->hasPermission('questions.update')) {
            abort(404);
        }

        return new QuestionResource($question->load(['chapter', 'options']));
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $this->authorize('create', Question::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        // ClassVersion is still TenantScoped, so this resolves to null
        // (not an exception) for a chapter outside this actor's own
        // tenant — Chapter itself is national content and no longer
        // tenant-scoped, so that check can no longer happen implicitly
        // via Chapter::findOrFail() the way it used to.
        $classVersion = $chapter->book->classVersion ?? abort(404);
        $data = $request->safe()->except(['image', 'options']);
        $data['school_id'] = $classVersion->school_id;
        $data['district_id'] = $classVersion->district_id;

        if ($request->hasFile('image')) {
            $disk = config('content.question_image_disk');
            $file = $request->file('image');
            $data['image_path'] = $file->storeAs("questions/{$chapter->id}", Str::uuid().'.'.$this->imageExtension($file), $disk);
            $data['image_disk'] = $disk;
        }

        $question = Question::create($data);
        $this->syncOptions($question, $request->input('options'));

        return (new QuestionResource($question->load(['chapter', 'options'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateQuestionRequest $request, Question $question): QuestionResource
    {
        $this->authorize('update', $question);

        $data = $request->safe()->except(['image', 'options']);

        if ($request->hasFile('image')) {
            if ($question->image_path) {
                Storage::disk($question->image_disk)->delete($question->image_path);
            }

            $disk = config('content.question_image_disk');
            $file = $request->file('image');
            $data['image_path'] = $file->storeAs("questions/{$question->chapter_id}", Str::uuid().'.'.$this->imageExtension($file), $disk);
            $data['image_disk'] = $disk;
        }

        $question->update($data);

        if ($request->has('options')) {
            $this->syncOptions($question, $request->input('options'));
        }

        return new QuestionResource($question->load(['chapter', 'options']));
    }

    public function destroy(Question $question): Response
    {
        $this->authorize('delete', $question);

        if ($question->image_path) {
            Storage::disk($question->image_disk)->delete($question->image_path);
        }

        $question->delete();

        return response()->noContent();
    }

    /**
     * Derive the stored file extension from the validated MIME type rather
     * than the client-supplied original filename — the original filename
     * is attacker-controlled, so trusting its extension (e.g. "shell.php")
     * could get a non-.jpg/.png file saved on the public disk under an
     * executable extension even though its content passed image
     * validation. The default branch is defensive only: the
     * mimes:jpg,jpeg,png rule on the request already guarantees one of
     * these two MIME types by the time this method runs.
     */
    private function imageExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => abort(422, 'Unsupported image type'),
        };
    }

    /**
     * Replace a question's full option set transactionally — simplest
     * correct approach for a list this small (never more than 6 options),
     * avoiding a diffing algorithm for add/remove/reorder.
     *
     * @param  array<int, array{option_text: string, bn_option_text?: ?string, is_correct?: bool, order_index?: int}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        DB::transaction(function () use ($question, $options) {
            $question->options()->delete();

            foreach ($options as $index => $option) {
                $question->options()->create([
                    'option_text' => $option['option_text'],
                    'bn_option_text' => $option['bn_option_text'] ?? null,
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'order_index' => $option['order_index'] ?? $index,
                ]);
            }
        });
    }
}
