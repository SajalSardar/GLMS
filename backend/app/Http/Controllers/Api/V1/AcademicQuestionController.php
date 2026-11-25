<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicQuestionRequest;
use App\Http\Requests\Api\V1\UpdateAcademicQuestionRequest;
use App\Http\Resources\Api\V1\AcademicQuestionResource;
use App\Models\AcademicQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicQuestionController extends Controller
{
    private const WITH = ['schoolClass', 'subject'];

    /**
     * The national question bank, filtered by the criteria a teacher uses
     * to find relevant questions for a paper. Only active questions are
     * returned for a view-only caller (a teacher); a caller holding
     * academic_questions.update (national_admin, via the management
     * portal's bank CRUD page) sees inactive questions too, since that
     * page is the only place a deactivated question can be reviewed or
     * reactivated. This is a global bank with no TenantScope, so
     * school_class_id/subject_id/chapter_number/question_type/marks are
     * the only narrowing available.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AcademicQuestion::class);

        $questions = AcademicQuestion::query()
            ->with(self::WITH)
            ->when(
                ! $request->user()->hasPermission('academic_questions.update'),
                fn ($query) => $query->where('is_active', true),
            )
            ->when($request->integer('school_class_id'), fn ($query, $id) => $query->where('school_class_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('chapter_number'), fn ($query, $number) => $query->where('chapter_number', $number))
            ->when($request->string('question_type')->toString(), fn ($query, $type) => $query->where('question_type', $type))
            ->when($request->integer('marks'), fn ($query, $marks) => $query->where('marks', $marks))
            ->orderBy('chapter_number')
            ->orderBy('id')
            ->get();

        return AcademicQuestionResource::collection($questions);
    }

    public function store(StoreAcademicQuestionRequest $request): JsonResponse
    {
        $question = AcademicQuestion::create([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
            'question_type' => $request->input('question_type'),
            'question_text' => $request->input('question_text'),
            'options' => $request->input('options'),
            'marks' => $request->input('marks'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new AcademicQuestionResource($question->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAcademicQuestionRequest $request, AcademicQuestion $academic_question): AcademicQuestionResource
    {
        $academic_question->update([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
            'question_type' => $request->input('question_type'),
            'question_text' => $request->input('question_text'),
            'options' => $request->input('options'),
            'marks' => $request->input('marks'),
            'is_active' => $request->boolean('is_active', $academic_question->is_active),
        ]);

        return new AcademicQuestionResource($academic_question->load(self::WITH));
    }

    public function destroy(AcademicQuestion $academic_question): JsonResponse
    {
        $this->authorize('delete', $academic_question);

        $academic_question->delete();

        return response()->json(null, 204);
    }
}
