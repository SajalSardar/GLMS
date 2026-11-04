<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'user_id' => $this->user_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
            'score' => $this->score,
            'total_possible_marks' => $this->total_possible_marks,
            'passed' => $this->passed,
            // Built directly here rather than nesting QuestionResource/
            // QuestionOptionResource — their is_correct gate is based on
            // the VIEWER's questions.update permission, which is the
            // wrong rule for a graded result: here, is_correct must show
            // once status is 'submitted', regardless of the viewer's
            // role, and must NEVER show while still in_progress.
            'questions' => $this->when(
                $this->relationLoaded('attemptQuestions'),
                function () {
                    $showFeedback = $this->status === 'submitted';

                    return $this->attemptQuestions->map(function ($attemptQuestion) use ($showFeedback) {
                        $question = $attemptQuestion->question;
                        $answer = $this->answers->firstWhere('question_id', $attemptQuestion->question_id);

                        return [
                            'question_id' => $question->id,
                            'question_type' => $question->question_type,
                            'question_text' => $question->question_text,
                            'marks' => $attemptQuestion->marks,
                            'order_index' => $attemptQuestion->order_index,
                            'options' => $question->options->map(fn ($option) => [
                                'id' => $option->id,
                                'option_text' => $option->option_text,
                                'is_correct' => $showFeedback ? $option->is_correct : null,
                            ]),
                            'selected_option_ids' => $answer?->selected_option_ids,
                            'is_correct' => $showFeedback ? $answer?->is_correct : null,
                            'marks_awarded' => $showFeedback ? $answer?->marks_awarded : null,
                        ];
                    });
                }
            ),
        ];
    }
}
