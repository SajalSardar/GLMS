<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentClassPromotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentClassPromotion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'to_section_id' => ['required', 'integer', 'exists:sections,id'],
            'override_capacity' => ['sometimes', 'boolean'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'integer'],
            'records.*.promote' => ['required', 'boolean'],
        ];
    }
}
