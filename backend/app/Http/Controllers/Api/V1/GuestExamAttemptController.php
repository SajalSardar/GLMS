<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitGuestExamAttemptRequest;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use App\Support\ExamGrading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class GuestExamAttemptController extends Controller
{
    /**
     * Draw a fresh random question set for a per_attempt_random exam.
     * Not persisted anywhere — a second call can (and usually will)
     * return a different set.
     */
    public function randomDraw(Exam $exam): AnonymousResourceCollection
    {
        abort_unless($exam->isPubliclyTakeable(), 404);
        abort_unless($exam->is_practice, 403);

        if ($exam->selection_mode !== 'per_attempt_random') {
            throw ValidationException::withMessages(['exam' => ['This exam does not use random question selection.']]);
        }

        $eligible = $exam->eligibleRandomQuestionIds();

        if ($eligible->count() < $exam->random_question_count) {
            throw ValidationException::withMessages(['exam' => ["Only {$eligible->count()} eligible question(s) are available, but this exam requires {$exam->random_question_count}."]]);
        }

        $questionIds = $eligible->shuffle()->take($exam->random_question_count)->values();
        $questions = Question::with('options')->whereIn('id', $questionIds)->get();

        return QuestionResource::collection($questions);
    }

    /**
     * Grade a submitted answer set against real correctness data and
     * return the result. Never creates an ExamAttempt/
     * ExamAttemptQuestion/ExamAttemptAnswer row — nothing here is
     * persisted under any circumstance.
     */
    public function submit(SubmitGuestExamAttemptRequest $request, Exam $exam): JsonResponse
    {
        abort_unless($exam->isPubliclyTakeable(), 404);
        abort_unless($exam->is_practice, 403);

        $answers = collect($request->validated('answers'))->keyBy('question_id');
        $submittedIds = $answers->keys()->values();

        if ($exam->selection_mode === 'per_attempt_random') {
            $eligible = $exam->eligibleRandomQuestionIds();

            if ($submittedIds->diff($eligible)->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => ['One or more submitted questions do not belong to this exam.']]);
            }

            if ($submittedIds->count() > $exam->random_question_count) {
                throw ValidationException::withMessages(['answers' => ["This exam only allows {$exam->random_question_count} question(s) per attempt."]]);
            }

            $gradeIds = $submittedIds;
        } else {
            $allowed = $exam->questions()->pluck('questions.id');

            if ($submittedIds->diff($allowed)->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => ['One or more submitted questions do not belong to this exam.']]);
            }

            $gradeIds = $allowed;
        }

        $questions = Question::with('options')->whereIn('id', $gradeIds)->get()->keyBy('id');

        $score = 0;
        $total = 0;
        $graded = [];

        foreach ($gradeIds as $questionId) {
            $question = $questions->get($questionId);

            if ($question === null) {
                continue;
            }

            $marks = $exam->selection_mode === 'per_attempt_random' ? $exam->marks_per_question : $question->marks;
            $selectedOptionIds = $answers[$questionId]['selected_option_ids'] ?? [];
            $isCorrect = ExamGrading::isCorrect($question, $selectedOptionIds);
            $marksAwarded = $isCorrect ? $marks : 0;

            $total += $marks;
            $score += $marksAwarded;

            $graded[] = [
                'question_id' => $question->id,
                'selected_option_ids' => $selectedOptionIds,
                'is_correct' => $isCorrect,
                'marks_awarded' => $marksAwarded,
                'marks' => $marks,
                'correct_option_ids' => $question->options->where('is_correct', true)->pluck('id')->values()->all(),
            ];
        }

        $passed = ($exam->passing_score_percent !== null && $total > 0)
            ? (($score / $total) * 100) >= $exam->passing_score_percent
            : null;

        return response()->json([
            'exam_id' => $exam->id,
            'score' => $score,
            'total_possible_marks' => $total,
            'passed' => $passed,
            'answers' => $graded,
        ]);
    }
}
