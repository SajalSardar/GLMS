<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveExamAttemptAnswerRequest extends FormRequest
{
    /**
     * Any authenticated user may save an answer on their own attempt —
     * no flat permission needed, same "own record" convention as
     * UpdateChapterVideoProgressRequest.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt && $this->user()?->id === $attempt->user_id;
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
