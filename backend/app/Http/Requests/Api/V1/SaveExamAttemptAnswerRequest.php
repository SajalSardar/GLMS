<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use App\Models\StudentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveExamAttemptAnswerRequest extends FormRequest
{
    /**
     * Any authenticated student may save an answer on their own attempt —
     * no flat permission needed, same "own record" convention as
     * UpdateChapterVideoProgressRequest. exam_attempts.user_id is
     * student-only (see migration 2026_11_12_100200), so the owner check
     * must also confirm the actor is a StudentUser — otherwise a
     * same-numeric-id row in a different user table would wrongly match.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt
            && $this->user() instanceof StudentUser
            && $this->user()->id === $attempt->user_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'selected_option_ids' => ['required', 'array'],
            'selected_option_ids.*' => ['integer'],
        ];
    }
}
