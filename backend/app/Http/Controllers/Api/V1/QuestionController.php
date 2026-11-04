<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreQuestionRequest;
use App\Http\Requests\Api\V1\UpdateQuestionRequest;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $data = $request->safe()->except(['image', 'options']);
        $data['school_id'] = $chapter->school_id;
        $data['district_id'] = $chapter->district_id;

        if ($request->hasFile('image')) {
            $disk = config('content.question_image_disk');
            $file = $request->file('image');
            $data['image_path'] = $file->storeAs("questions/{$chapter->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), $disk);
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
            $data['image_path'] = $file->storeAs("questions/{$question->chapter_id}", Str::uuid().'.'.$file->getClientOriginalExtension(), $disk);
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
