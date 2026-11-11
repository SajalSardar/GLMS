<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectTeacherAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subject_teacher_assignment')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * section_id/subject_id are immutable after creation — reassign by
     * deleting and creating a new assignment instead.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'teacher_id' => ['required', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
        ];
    }
}
