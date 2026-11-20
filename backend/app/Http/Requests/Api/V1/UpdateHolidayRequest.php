<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Holiday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHolidayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holiday')) ?? false;
    }

    /**
     * type is deliberately immutable here — switching a row between
     * weekly/dated would need a different set of fields entirely; delete
     * and recreate instead.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'academic_year_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'day_of_week' => [
                'sometimes', $holiday->type === 'weekly' ? 'required' : 'prohibited', 'nullable', 'string',
                Rule::in(Holiday::DAYS_OF_WEEK),
                Rule::unique('holidays', 'day_of_week')->withoutTrashed()->ignore($holiday->id)
                    ->where(fn ($query) => $query->where('type', 'weekly')),
            ],
            'start_date' => ['sometimes', $holiday->type === 'weekly' ? 'prohibited' : 'required', 'nullable', 'date'],
            'end_date' => ['sometimes', $holiday->type === 'weekly' ? 'prohibited' : 'required', 'nullable', 'date', 'after_or_equal:start_date'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Compare the *effective* start/end dates (submitted value, falling
     * back to the existing record) — same reasoning as
     * UpdateClassRoutineRequest's own withValidator().
     */
    public function withValidator(Validator $validator): void
    {
        $holiday = $this->route('holiday');

        $validator->after(function (Validator $validator) use ($holiday) {
            if ($holiday->type === 'weekly' || $validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = $this->input('start_date', $holiday->start_date?->toDateString());
            $end = $this->input('end_date', $holiday->end_date?->toDateString());

            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.unique' => 'A weekly holiday for this day already exists.',
        ];
    }
}
