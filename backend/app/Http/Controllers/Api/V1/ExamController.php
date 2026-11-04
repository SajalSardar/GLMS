<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetExamQuestionsRequest;
use App\Http\Requests\Api\V1\SetExamRandomChaptersRequest;
use App\Http\Requests\Api\V1\StoreExamRequest;
use App\Http\Requests\Api\V1\UpdateExamRequest;
use App\Http\Resources\Api\V1\ExamResource;
use App\Models\AcademicYear;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function setQuestions(SetExamQuestionsRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        DB::transaction(function () use ($exam, $request) {
            $exam->questions()->detach();

            foreach ($request->validated()['question_ids'] as $index => $questionId) {
                $exam->questions()->attach($questionId, ['order_index' => $index]);
            }
        });

        return new ExamResource($exam->load(self::WITH));
    }

    public function setRandomChapters(SetExamRandomChaptersRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        $exam->randomChapters()->sync($request->validated()['chapter_ids'] ?? []);

        return new ExamResource($exam->load(self::WITH));
    }

    public function randomize(Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        if ($exam->selection_mode !== 'fixed_random') {
            throw ValidationException::withMessages([
                'selection_mode' => ['Only fixed_random exams can be randomized.'],
            ]);
        }

        if (! $exam->random_question_count) {
            throw ValidationException::withMessages([
                'random_question_count' => ['This exam has no random_question_count configured.'],
            ]);
        }

        $chapterIds = Chapter::query()
            ->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
            ->pluck('id');

        $allowedChapterIds = $exam->randomChapters()->pluck('chapters.id');

        if ($allowedChapterIds->isNotEmpty()) {
            $chapterIds = $chapterIds->intersect($allowedChapterIds);
        }

        $eligible = Question::query()
            ->whereIn('chapter_id', $chapterIds)
            ->when($exam->random_difficulty, fn ($query, $value) => $query->where('difficulty', $value))
            ->pluck('id');

        if ($eligible->count() < $exam->random_question_count) {
            throw ValidationException::withMessages([
                'random_question_count' => ["Only {$eligible->count()} eligible question(s) are available, but random_question_count is {$exam->random_question_count}."],
            ]);
        }

        $drawn = $eligible->shuffle()->take($exam->random_question_count)->values();

        DB::transaction(function () use ($exam, $drawn) {
            $exam->questions()->detach();

            foreach ($drawn as $index => $questionId) {
                $exam->questions()->attach($questionId, ['order_index' => $index]);
            }
        });

        return new ExamResource($exam->load(self::WITH));
    }
}
