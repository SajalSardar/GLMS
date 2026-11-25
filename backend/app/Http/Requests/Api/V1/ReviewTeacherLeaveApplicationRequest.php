<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherLeaveApplication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTeacherLeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('teacher_leave_application')) ?? false;
    }

    /**
     * 'pending' is deliberately not a valid target — only store() sets
     * it; review() only ever moves an application forward.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_diff(TeacherLeaveApplication::STATUSES, ['pending']))],
            'admin_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
