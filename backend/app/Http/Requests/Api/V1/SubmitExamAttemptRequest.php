<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;

class SubmitExamAttemptRequest extends FormRequest
{
    /**
     * Any authenticated user may submit their own attempt — same
     * convention as SaveExamAttemptAnswerRequest.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt && $this->user()?->id === $attempt->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
