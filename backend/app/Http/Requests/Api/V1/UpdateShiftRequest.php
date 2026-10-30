<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('shift')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * class_version_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shift = $this->route('shift');

        return [
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('shifts', 'name')->ignore($shift)->where('class_version_id', $shift->class_version_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'days_of_week' => ['sometimes', 'required', 'array', 'min:1'],
            'days_of_week.*' => [Rule::in(ClassRoutine::DAYS_OF_WEEK)],
            'period_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.start_time' => ['required', 'date_format:H:i'],
            'breaks.*.end_time' => ['required', 'date_format:H:i', 'after:breaks.*.start_time'],
            'breaks.*.label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Compare the *effective* start/end times (submitted value, falling
     * back to the existing record) rather than relying on `after:` against
     * a field that may be absent on a partial update.
     */
    public function withValidator(Validator $validator): void
    {
        $shift = $this->route('shift');

        $validator->after(function (Validator $validator) use ($shift) {
            if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
                return;
            }

            $start = Carbon::parse($this->input('start_time', $shift->start_time->format('H:i')));
            $end = Carbon::parse($this->input('end_time', $shift->end_time->format('H:i')));

            if ($end->lessThanOrEqualTo($start)) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            }
        });
    }
}
