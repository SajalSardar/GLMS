<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherAttendance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectTeacherAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', TeacherAttendance::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'teacher_profile_id' => ['required', 'integer', Rule::exists('teacher_profiles', 'id')->withoutTrashed()],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(TeacherAttendance::STATUSES)],
            'correction_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
