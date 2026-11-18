<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitGuestExamAttemptRequest extends FormRequest
{
    /**
     * Public endpoint, no identity to check against — anyone may submit.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['present', 'array', 'max:50'],
            'answers.*.selected_option_ids.*' => ['integer'],
        ];
    }
}
