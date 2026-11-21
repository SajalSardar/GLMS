<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamPublication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UnpublishAcademicExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', AcademicExamPublication::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_exam_term_id' => ['required', 'integer', 'exists:academic_exam_terms,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ];
    }
}
