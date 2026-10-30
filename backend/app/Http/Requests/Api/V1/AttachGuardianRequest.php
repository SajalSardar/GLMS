<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachGuardianRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Linking a guardian to a student is a student-profile update.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student_profile')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'guardian_id' => ['required', 'integer', Rule::exists('guardians', 'id')->withoutTrashed()],
            'relation' => ['required', 'string', Rule::in(['father', 'mother', 'guardian', 'other'])],
            'is_primary' => ['boolean'],
        ];
    }
}
