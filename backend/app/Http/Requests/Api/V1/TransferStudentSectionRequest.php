<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStudentSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('transferSection', $this->route('student_profile')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to_section_id' => ['required', 'integer', Rule::exists('sections', 'id')->withoutTrashed()],
            'reason' => ['nullable', 'string', 'max:255'],
            // Bypasses the section-capacity check — requires the actor to
            // also hold `sections.override_capacity`, checked in the
            // controller rather than here (that's an authorization
            // decision, not a shape-of-input one).
            'override_capacity' => ['boolean'],
        ];
    }
}
