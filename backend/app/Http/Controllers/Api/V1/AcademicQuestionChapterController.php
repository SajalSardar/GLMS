<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicQuestionChapterRequest;
use App\Http\Requests\Api\V1\UpdateAcademicQuestionChapterRequest;
use App\Http\Resources\Api\V1\AcademicQuestionChapterResource;
use App\Models\AcademicQuestionChapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicQuestionChapterController extends Controller
{
    private const WITH = ['schoolClass', 'subject'];

    /**
     * The global chapter list backing the question bank's chapter picker,
     * filtered by the class/subject a caller is narrowing a form to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AcademicQuestionChapter::class);

        $chapters = AcademicQuestionChapter::query()
            ->with(self::WITH)
            ->when($request->integer('school_class_id'), fn ($query, $id) => $query->where('school_class_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->orderBy('chapter_number')
            ->get();

        return AcademicQuestionChapterResource::collection($chapters);
    }

    public function store(StoreAcademicQuestionChapterRequest $request): JsonResponse
    {
        $chapter = AcademicQuestionChapter::create([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
        ]);

        return (new AcademicQuestionChapterResource($chapter->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAcademicQuestionChapterRequest $request, AcademicQuestionChapter $academic_question_chapter): AcademicQuestionChapterResource
    {
        $academic_question_chapter->update([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
        ]);

        return new AcademicQuestionChapterResource($academic_question_chapter->load(self::WITH));
    }

    public function destroy(AcademicQuestionChapter $academic_question_chapter): JsonResponse
    {
        $this->authorize('delete', $academic_question_chapter);

        $academic_question_chapter->delete();

        return response()->json(null, 204);
    }
}
