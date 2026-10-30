<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use App\Models\Section;
use App\Models\TimetableEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimetableEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = Section::find($this->integer('section_id'))?->school;

        return $school !== null && ($this->user()?->can('create', [TimetableEntry::class, $school]) ?? false);
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
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'day_of_week' => [
                'required', 'string', Rule::in(ClassRoutine::DAYS_OF_WEEK),
                Rule::unique('timetable_entries', 'day_of_week')->withoutTrashed()->where(function ($query) {
                    $query->where('section_id', $this->integer('section_id'))
                        ->where('period_no', $this->integer('period_no'));
                }),
            ],
            'period_no' => ['required', 'integer', 'min:1', 'max:20'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.unique' => 'This section already has a subject scheduled in this period on this day.',
        ];
    }
}
