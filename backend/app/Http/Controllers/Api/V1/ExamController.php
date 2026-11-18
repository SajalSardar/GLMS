<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetExamQuestionsRequest;
use App\Http\Requests\Api\V1\SetExamRandomChaptersRequest;
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

    /**
     * index() lists potentially every published exam nationally with no
     * pagination; it must not also carry each one's full question/option
     * payload. Full detail stays on show(), via self::WITH.
     */
    private const INDEX_WITH = ['classVersion', 'subject', 'academicYear'];

    public function index(Request $request): AnonymousResourceCollection
    {
        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous requests), so the user is resolved explicitly
        // from the sanctum guard rather than via $request->user().
        $actor = $request->user('sanctum');

        $exams = Exam::query()
            ->with(self::INDEX_WITH)
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->when(! $actor?->hasPermission('exams.view'), fn ($query) => $query
                ->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
                ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now())))
            // TenantScope::apply() reads Auth::user(), which resolves off
            // the default "web" guard. This app never authenticates via
            // that guard (only Sanctum bearer tokens), so the global scope
            // never actually filters on this route — it's always inert
            // here. Reapply its exact branches manually against the
            // sanctum-resolved actor so a privileged (exams.view) viewer
            // doesn't see every school's exams nationally.
            ->when($actor !== null && $actor->school_id !== null, fn ($query) => $query->where('school_id', $actor->school_id))
            ->when($actor !== null && $actor->school_id === null && $actor->district_id !== null, fn ($query) => $query->where('district_id', $actor->district_id))
            ->orderBy('name')
            ->get();

        return ExamResource::collection($exams);
    }

    public function show(Request $request, Exam $exam): ExamResource
    {
        // This route sits outside the auth:sanctum group, so the user is
        // resolved explicitly from the sanctum guard.
        $actor = $request->user('sanctum');

        abort_unless($actor?->hasPermission('exams.view') || $exam->isPubliclyTakeable(), 404);

        // Mirrors TenantScope::apply(), reapplied explicitly for the
        // privileged (exams.view) branch only: that global scope reads
        // Auth::user() off the default "web" guard, which this app never
        // authenticates against, so it never actually fires on this
        // public route. Without this, a privileged actor could fetch any
        // school's exam by id, draft or not.
        if ($actor?->hasPermission('exams.view')) {
            if ($actor->school_id !== null && $exam->school_id !== $actor->school_id) {
                abort(404);
            }

            if ($actor->school_id === null && $actor->district_id !== null && $exam->district_id !== $actor->district_id) {
                abort(404);
            }
        }

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

        $eligible = $exam->eligibleRandomQuestionIds();

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
