<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicYearRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_year')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * school_id is immutable after creation — moving an academic year to a
     * different school isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $academicYear = $this->route('academic_year');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('academic_years', 'name')->ignore($academicYear),
            ],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date'],
            'is_current' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Compare the *effective* start/end dates (submitted value, falling
     * back to the existing record) rather than relying on `after:` against
     * a field that may be absent on a partial update.
     */
    public function withValidator(Validator $validator): void
    {
        $academicYear = $this->route('academic_year');

        $validator->after(function (Validator $validator) use ($academicYear) {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = Carbon::parse($this->input('start_date', $academicYear->start_date->toDateString()));
            $end = Carbon::parse($this->input('end_date', $academicYear->end_date->toDateString()));

            if ($end->lessThanOrEqualTo($start)) {
                $validator->errors()->add('end_date', 'The end date must be after the start date.');
            }
        });
    }
}
