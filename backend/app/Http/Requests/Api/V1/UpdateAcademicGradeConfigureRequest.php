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
     * A cleared Sort Order input sends literal null, which the
     * `unsignedSmallInteger('sort_order')->default(0)` column rejects at
     * the database level (a default only applies when the column is
     * omitted entirely, not when null is explicitly sent). Coerce a
     * present-but-null sort_order to 0 before validation runs — guarded
     * on has() so a PATCH that never mentions sort_order at all doesn't
     * get one injected and silently reset the row's existing value.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('sort_order') && $this->input('sort_order') === null) {
            $this->merge(['sort_order' => 0]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'grade_name' => ['sometimes', 'required', 'string', 'max:50'],
            'bn_grade_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_point' => ['sometimes', 'required', 'numeric', 'min:0', 'max:5'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validates range consistency and overlap. Uses effective values (submitted
     * or existing) to handle partial PATCH requests correctly. Checks min <= max
     * first to prevent data-integrity bugs before proceeding to overlap checks.
     */
    public function withValidator(Validator $validator): void
    {
        $configure = $this->route('academic_grade_configure');

        $validator->after(function (Validator $validator) use ($configure) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            $min = $this->input('min_percent', $configure->min_percent);
            $max = $this->input('max_percent', $configure->max_percent);

            if ((float) $min > (float) $max) {
                $validator->errors()->add('max_percent', 'The max percent must be greater than or equal to min percent.');

                return;
            }

            $isActive = $this->boolean('is_active', $configure->is_active);
            if (! $isActive) {
                return;
            }

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
