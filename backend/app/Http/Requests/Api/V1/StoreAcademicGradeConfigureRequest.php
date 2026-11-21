<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAcademicGradeConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicGradeConfigure::class) ?? false;
    }

    /**
     * A cleared Sort Order input sends literal null, which the
     * `unsignedSmallInteger('sort_order')->default(0)` column rejects at
     * the database level (a default only applies when the column is
     * omitted entirely, not when null is explicitly sent). Coerce a
     * present-but-null sort_order to 0 before validation runs.
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
            'min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_percent'],
            'grade_name' => ['required', 'string', 'max:50'],
            'bn_grade_name' => ['nullable', 'string', 'max:50'],
            'grade_point' => ['required', 'numeric', 'min:0', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * No two active rows' [min_percent, max_percent] ranges may overlap —
     * AcademicGradeConfigure::forPercent() assumes exactly one active row
     * matches any given percentage. Coverage GAPS between active ranges
     * are deliberately NOT validated here: an earlier attempt enforced
     * full 0-100 coverage per-row, which made a multi-row scale
     * impossible to edit at all (every intermediate state of a
     * legitimate multi-step edit violates a whole-set invariant checked
     * one row at a time). A gap is tolerated instead — forPercent()
     * returns null for a percent inside one, and every caller is
     * null-safe (see MarksheetBuilder's use of it).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            if ($this->boolean('is_active', true) && $this->overlapsAnotherActiveRow()) {
                $validator->errors()->add('min_percent', 'This range overlaps another active grade configuration.');
            }
        });
    }

    private function overlapsAnotherActiveRow(): bool
    {
        return AcademicGradeConfigure::where('is_active', true)
            ->where('min_percent', '<=', $this->input('max_percent'))
            ->where('max_percent', '>=', $this->input('min_percent'))
            ->exists();
    }
}
