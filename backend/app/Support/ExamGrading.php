<?php

namespace App\Support;

use App\Models\Question;

class ExamGrading
{
    /**
     * The one correctness rule for every question type in this app: the
     * selected-option-id set must exactly equal the correct-option-id
     * set. Already right for mcq_single and true_false, since their
     * correct set always has exactly one member. Shared by the tracked
     * (ExamAttempt::finalize()) and untracked (GuestExamAttemptController)
     * grading paths — this must not be duplicated a second time.
     *
     * @param  array<int, int>  $selectedOptionIds
     */
    public static function isCorrect(Question $question, array $selectedOptionIds): bool
    {
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selectedIds = collect($selectedOptionIds)->sort()->values()->all();

        return $correctIds === $selectedIds;
    }
}
