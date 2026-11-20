<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentLeaveApplication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewStudentLeaveApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('student_leave_application')) ?? false;
    }

    /**
     * 'pending' is deliberately not a valid target here — only the
     * initial store() sets it; review() only ever moves an application
     * forward to a decided state.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_diff(StudentLeaveApplication::STATUSES, ['pending']))],
            'teacher_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
