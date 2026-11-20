<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClassRoutineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_routine')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * class_id is immutable after creation — moving a routine entry to a
     * different class isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sections', 'id')->withoutTrashed()],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'day_of_week' => ['sometimes', 'required', 'string', Rule::in(ClassRoutine::DAYS_OF_WEEK)],
            'period_no' => ['sometimes', 'required', 'integer', 'min:1', 'max:20'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Compare the *effective* start/end times (submitted value, falling
     * back to the existing record) rather than relying on `after:` against
     * a field that may be absent on a partial update.
     */
    public function withValidator(Validator $validator): void
    {
        $routine = $this->route('class_routine');

        $validator->after(function (Validator $validator) use ($routine) {
            if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                return;
            }

            $start = $this->input('start_time', $routine->start_time?->format('H:i'));
            $end = $this->input('end_time', $routine->end_time?->format('H:i'));

            if ($start !== null && $end !== null && Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            }
        });
    }
}
