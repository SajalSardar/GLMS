<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveExamAttemptAnswerRequest;
use App\Http\Requests\Api\V1\SubmitExamAttemptRequest;
use App\Http\Resources\Api\V1\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Support\TargetStudentProfileResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptController extends Controller
{
    public function __construct(private readonly TargetStudentProfileResolver $targetStudentProfileResolver) {}

    /**
     * Shared eager-load list — Task 4's saveAnswer()/submit() reuse this
     * by the same name so the response shape stays consistent.
     */
    private const WITH = ['attemptQuestions.question.options', 'answers'];

    public function start(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('create', ExamAttempt::class);

        if (! $exam->isPubliclyTakeable()) {
            throw ValidationException::withMessages(['exam' => ['This exam is not available to attempt right now.']]);
        }

        $user = $request->user();
        $studentProfile = $user->profile;
        $classVersionId = $studentProfile?->section?->shift?->class_version_id;
        $academicYearId = $studentProfile?->section?->shift?->academic_year_id;

        if ($classVersionId !== $exam->class_version_id || $academicYearId !== $exam->academic_year_id) {
            throw ValidationException::withMessages(['exam' => ['You are not enrolled in the class version/academic year this exam belongs to.']]);
        }

        $attemptNumber = (int) ExamAttempt::where('exam_id', $exam->id)->where('user_id', $user->id)->max('attempt_number') + 1;

        if ($exam->max_attempts && $attemptNumber > $exam->max_attempts) {
            throw ValidationException::withMessages(['exam' => ['You have used all allowed attempts for this exam.']]);
        }

        if ($exam->selection_mode === 'per_attempt_random') {
            $eligible = $exam->eligibleRandomQuestionIds();

            if ($eligible->count() < $exam->random_question_count) {
                throw ValidationException::withMessages(['exam' => ["Only {$eligible->count()} eligible question(s) are available, but this exam requires {$exam->random_question_count}."]]);
            }

            $questionIds = $eligible->shuffle()->take($exam->random_question_count)->values();
        } else {
            $questionIds = $exam->questions()->pluck('questions.id');

            if ($questionIds->isEmpty()) {
                throw ValidationException::withMessages(['exam' => ['This exam has no questions configured.']]);
            }
        }

        $questions = Question::whereIn('id', $questionIds)->get()->keyBy('id');

        $attempt = DB::transaction(function () use ($exam, $user, $attemptNumber, $questionIds, $questions) {
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'school_id' => $exam->school_id,
                'district_id' => $exam->district_id,
                'attempt_number' => $attemptNumber,
                'started_at' => now(),
            ]);

            foreach ($questionIds->values() as $index => $questionId) {
                ExamAttemptQuestion::create([
                    'exam_attempt_id' => $attempt->id,
                    'question_id' => $questionId,
                    'order_index' => $index,
                    'marks' => $exam->selection_mode === 'per_attempt_random' ? $exam->marks_per_question : $questions[$questionId]->marks,
                ]);
            }

            return $attempt;
        });

        return (new ExamAttemptResource($attempt->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExamAttempt $exam_attempt): ExamAttemptResource
    {
        $this->authorize('view', $exam_attempt);

        $this->finalizeSafely($exam_attempt, fn () => $exam_attempt->finalizeIfExpired());

        return new ExamAttemptResource($exam_attempt->load(self::WITH));
    }

    public function index(Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExamAttempt::class);

        $attempts = $exam->attempts()->orderBy('user_id')->orderBy('attempt_number')->get();

        return ExamAttemptResource::collection($attempts);
    }

    /**
     * The current student's own submitted practice-exam attempts (score
     * history) — no flat permission is required, same "own record"
     * precedent as ExamAttemptPolicy::view(). Unlike index() above, this
     * is never exposed for an exam_id the caller doesn't already know, so
     * there's no ExamAttemptPolicy check to apply; the user_id filter IS
     * the authorization. A guardian may also call this, for one of their
     * linked children — exam_attempts.user_id is a StudentUser id, so
     * once TargetStudentProfileResolver resolves the (student's own, or
     * the guardian's chosen child's) profile, the filter reads through
     * profile->user_id rather than the caller's own id directly.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $profile = $this->targetStudentProfileResolver->resolve($request);

        if ($profile === null || $profile->user_id === null) {
            return ExamAttemptResource::collection(
                ExamAttempt::query()->whereRaw('1 = 0')->paginate($request->integer('per_page', 15))
            );
        }

        $attempts = ExamAttempt::query()
            ->where('user_id', $profile->user_id)
            ->where('status', 'submitted')
            ->whereHas('exam', fn ($query) => $query->where('is_practice', true))
            ->with(['exam.subject'])
            ->orderByDesc('submitted_at')
            ->paginate($request->integer('per_page', 15));

        return ExamAttemptResource::collection($attempts);
    }

    public function saveAnswer(SaveExamAttemptAnswerRequest $request, ExamAttempt $exam_attempt, Question $question): JsonResponse
    {
        $this->finalizeSafely($exam_attempt, fn () => $exam_attempt->finalizeIfExpired());

        if ($exam_attempt->status !== 'in_progress') {
            throw ValidationException::withMessages(['exam_attempt' => ['This attempt is no longer in progress.']]);
        }

        if (! $exam_attempt->attemptQuestions()->where('question_id', $question->id)->exists()) {
            abort(404);
        }

        $answer = ExamAttemptAnswer::updateOrCreate(
            ['exam_attempt_id' => $exam_attempt->id, 'question_id' => $question->id],
            ['selected_option_ids' => $request->validated()['selected_option_ids']]
        );

        return response()->json([
            'question_id' => $question->id,
            'selected_option_ids' => $answer->selected_option_ids,
            'saved_at' => $answer->updated_at,
        ]);
    }

    public function submit(SubmitExamAttemptRequest $request, ExamAttempt $exam_attempt): ExamAttemptResource
    {
        if ($exam_attempt->status === 'in_progress') {
            $this->finalizeSafely($exam_attempt, fn () => $exam_attempt->finalizeIfExpired() || $exam_attempt->finalize());
        }

        return new ExamAttemptResource($exam_attempt->load(self::WITH));
    }

    /**
     * Runs $callback (expected to call finalizeIfExpired()/finalize() on
     * $exam_attempt), swallowing the unique-constraint race where another
     * near-simultaneous request (a double-click submit, or submit() racing
     * a show()-triggered finalizeIfExpired() right at the expiry boundary)
     * already finalized this attempt first. Same detection pattern as
     * ChapterVideoController::updateProgress() (commit d138b9d) — on that
     * race we just refresh to the now-submitted state instead of
     * re-attempting the write or propagating the exception.
     */
    private function finalizeSafely(ExamAttempt $exam_attempt, callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'UNIQUE constraint') && $e->getCode() !== '23000') {
                throw $e;
            }

            $exam_attempt->refresh();
        }
    }
}
