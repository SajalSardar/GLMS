<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use App\Models\StudentUser;
use Illuminate\Foundation\Http\FormRequest;

class SubmitExamAttemptRequest extends FormRequest
{
    /**
     * Any authenticated student may submit their own attempt — same
     * convention and same StudentUser-type guard as
     * SaveExamAttemptAnswerRequest.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt
            && $this->user() instanceof StudentUser
            && $this->user()->id === $attempt->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
