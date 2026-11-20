<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Attendance;
use App\Models\Section;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAttendanceRequest extends FormRequest
{
    /**
     * section_id is resolved here (not route-bound — this is a bulk
     * write, not a single-model route) and checked against
     * AttendancePolicy::recordSection(), same pattern as
     * ReviewStudentLeaveApplicationRequest resolving its route-bound
     * model. A missing/out-of-tenant-scope section fails closed.
     */
    public function authorize(): bool
    {
        $section = Section::find($this->integer('section_id'));

        return $section !== null && ($this->user()?->can('recordSection', [Attendance::class, $section]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(Attendance::STATUSES)],
        ];
    }
}
