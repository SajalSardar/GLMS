<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreQuestionPaperRequest;
use App\Http\Requests\Api\V1\UpdateQuestionPaperRequest;
use App\Http\Resources\Api\V1\QuestionPaperResource;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class QuestionPaperController extends Controller
{
    private const WITH = ['teacher', 'schoolClass', 'subject', 'items.academicQuestion'];

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuestionPaper::class);

        $papers = QuestionPaper::query()
            ->with(self::WITH)
            ->orderByDesc('created_at')
            ->get();

        return QuestionPaperResource::collection($papers);
    }

    public function show(QuestionPaper $question_paper): QuestionPaperResource
    {
        $this->authorize('view', $question_paper);

        return new QuestionPaperResource($question_paper->load(self::WITH));
    }

    /**
     * Creates the paper and its ordered items in one transaction — a
     * paper with no items (or items that failed to insert) would be a
     * broken, half-saved builder result.
     */
    public function store(StoreQuestionPaperRequest $request): JsonResponse
    {
        $teacher = $request->user();

        $paper = DB::transaction(function () use ($request, $teacher) {
            $paper = QuestionPaper::create([
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'district_id' => $teacher->district_id,
                'title' => $request->input('title'),
                'school_class_id' => $request->input('school_class_id'),
                'subject_id' => $request->input('subject_id'),
                'time_duration_minutes' => $request->input('time_duration_minutes'),
                'full_marks' => $request->input('full_marks'),
                'instructions' => $request->input('instructions'),
            ]);

            $this->replaceItems($paper, $request->input('items'));

            return $paper;
        });

        return (new QuestionPaperResource($paper->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Full replace semantics: the entire item set is deleted and
     * recreated from the payload — the simplest correct way to persist a
     * drag-and-drop canvas, which has no stable notion of "patch just
     * this one item" from the client's perspective.
     */
    public function update(UpdateQuestionPaperRequest $request, QuestionPaper $question_paper): QuestionPaperResource
    {
        DB::transaction(function () use ($request, $question_paper) {
            $question_paper->update([
                'title' => $request->input('title'),
                'school_class_id' => $request->input('school_class_id'),
                'subject_id' => $request->input('subject_id'),
                'time_duration_minutes' => $request->input('time_duration_minutes'),
                'full_marks' => $request->input('full_marks'),
                'instructions' => $request->input('instructions'),
            ]);

            $question_paper->items()->delete();
            $this->replaceItems($question_paper, $request->input('items'));
        });

        return new QuestionPaperResource($question_paper->load(self::WITH));
    }

    public function destroy(QuestionPaper $question_paper): JsonResponse
    {
        $this->authorize('delete', $question_paper);

        $question_paper->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  array<int, array{type: string, academic_question_id?: int|null, heading_text?: string|null}>  $items
     */
    private function replaceItems(QuestionPaper $paper, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            QuestionPaperItem::create([
                'question_paper_id' => $paper->id,
                'item_type' => $item['type'],
                'academic_question_id' => $item['type'] === 'question' ? $item['academic_question_id'] : null,
                'heading_text' => $item['type'] === 'heading' ? $item['heading_text'] : null,
                'order_index' => $index,
            ]);
        }
    }
}
