<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTimetableEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A published entry requires the same `update` ability as a draft —
     * TimetableEntryController additionally blocks edits to a published
     * entry unless the actor also holds `timetable_entries.publish`.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('timetable_entry')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * section_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entry = $this->route('timetable_entry');

        return [
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'teacher_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'day_of_week' => [
                'sometimes', 'required', 'string', Rule::in(ClassRoutine::DAYS_OF_WEEK),
                Rule::unique('timetable_entries', 'day_of_week')->ignore($entry)->where(function ($query) use ($entry) {
                    $query->where('section_id', $entry->section_id)
                        ->where('period_no', $this->integer('period_no', $entry->period_no));
                }),
            ],
            'period_no' => ['sometimes', 'required', 'integer', 'min:1', 'max:20'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
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

    /**
     * Compare the *effective* start/end times (submitted value, falling
     * back to the existing record) rather than relying on `after:` against
     * a field that may be absent on a partial update.
     */
    public function withValidator(Validator $validator): void
    {
        $entry = $this->route('timetable_entry');

        $validator->after(function (Validator $validator) use ($entry) {
            if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                return;
            }

            $start = $this->input('start_time', $entry->start_time?->format('H:i'));
            $end = $this->input('end_time', $entry->end_time?->format('H:i'));

            if ($start !== null && $end !== null && Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            }
        });
    }
}
