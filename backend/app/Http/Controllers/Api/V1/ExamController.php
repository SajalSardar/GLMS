<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExamRequest;
use App\Http\Requests\Api\V1\UpdateExamRequest;
use App\Http\Resources\Api\V1\ExamResource;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\Exam;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExamController extends Controller
{
    /**
     * Shared eager-load list — Task 4's setQuestions()/randomize() reuse
     * this by the same name so the response shape stays consistent
     * across every action.
     */
    private const WITH = ['classVersion', 'subject', 'academicYear', 'questions.options', 'randomChapters'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Exam::class);

        $exams = Exam::query()
            ->with(self::WITH)
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->orderBy('name')
            ->get();

        return ExamResource::collection($exams);
    }

    public function show(Exam $exam): ExamResource
    {
        $this->authorize('view', $exam);

        return new ExamResource($exam->load(self::WITH));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $this->authorize('create', Exam::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
        Subject::findOrFail($request->integer('subject_id'));
        AcademicYear::findOrFail($request->integer('academic_year_id'));

        $data = $request->validated();
        $data['school_id'] = $classVersion->school_id;
        $data['district_id'] = $classVersion->district_id;

        $exam = Exam::create($data);

        return (new ExamResource($exam->load(self::WITH)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateExamRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        $exam->update($request->validated());

        return new ExamResource($exam->load(self::WITH));
    }

    public function destroy(Exam $exam): Response
    {
        $this->authorize('delete', $exam);

        $exam->delete();

        return response()->noContent();
    }
}
