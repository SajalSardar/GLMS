<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectTeacherAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = Section::find($this->integer('section_id'))?->school;

        return $school !== null && ($this->user()?->can('create', [SubjectTeacherAssignment::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'teacher_id' => ['required', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
        ];
    }
}
