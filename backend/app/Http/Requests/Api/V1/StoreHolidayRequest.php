<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Holiday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Holiday::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'type' => ['required', 'string', Rule::in(Holiday::TYPES)],
            'day_of_week' => [
                'required_if:type,weekly', 'prohibited_unless:type,weekly', 'nullable', 'string',
                Rule::in(Holiday::DAYS_OF_WEEK),
                Rule::unique('holidays', 'day_of_week')->withoutTrashed()->where(fn ($query) => $query->where('type', 'weekly')),
            ],
            'start_date' => ['required_unless:type,weekly', 'prohibited_if:type,weekly', 'nullable', 'date'],
            'end_date' => ['required_unless:type,weekly', 'prohibited_if:type,weekly', 'nullable', 'date', 'after_or_equal:start_date'],
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
