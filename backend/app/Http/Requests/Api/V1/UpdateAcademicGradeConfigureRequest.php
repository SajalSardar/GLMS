<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicGradeConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_grade_configure')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'gte:min_percent'],
            'grade_name' => ['sometimes', 'required', 'string', 'max:50'],
            'bn_grade_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_point' => ['sometimes', 'required', 'numeric', 'min:0', 'max:5'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same overlap guard as the store request, comparing the *effective*
     * range (submitted value, falling back to the existing row) and
     * excluding this row itself.
     */
    public function withValidator(Validator $validator): void
    {
        $configure = $this->route('academic_grade_configure');

        $validator->after(function (Validator $validator) use ($configure) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            $isActive = $this->boolean('is_active', $configure->is_active);
            if (! $isActive) {
                return;
            }

            $min = $this->input('min_percent', $configure->min_percent);
            $max = $this->input('max_percent', $configure->max_percent);

            $overlaps = AcademicGradeConfigure::where('is_active', true)
                ->whereKeyNot($configure->id)
                ->where('min_percent', '<=', $max)
                ->where('max_percent', '>=', $min)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('min_percent', 'This range overlaps another active grade configuration.');
            }
        });
    }
}
