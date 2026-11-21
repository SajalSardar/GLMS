<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Section;
use App\Models\StudentClassPromotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewStudentPromotionRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $section = Section::find($this->integer('section_id'));
            $sectionAcademicYearId = $section?->shift?->academic_year_id;

            if ($sectionAcademicYearId !== null && $sectionAcademicYearId !== $this->integer('academic_year_id')) {
                $validator->errors()->add('academic_year_id', "This doesn't match the section's own academic year.");
            }
        });
    }
}
