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
     * matches any given percentage.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            if ($this->boolean('is_active', true) && $this->overlapsAnotherActiveRow()) {
                $validator->errors()->add('min_percent', 'This range overlaps another active grade configuration.');

                return;
            }

            if ($this->boolean('is_active', true) && $this->leavesGap()) {
                $validator->errors()->add('min_percent', 'This range leaves a gap in grade coverage (0% to 100% must be fully covered with no gaps).');
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

    /**
     * Beyond pairwise overlap (above), the full ACTIVE set — including this
     * new row — must leave no gap: every percent from 0 to 100 (two-decimal
     * granularity) must fall inside exactly one active row's [min, max], or
     * AcademicGradeConfigure::forPercent() returns null for a gap percent,
     * which MarksheetBuilder's GPA average then silently folds in as 0.0.
     */
    private function leavesGap(): bool
    {
        $ranges = AcademicGradeConfigure::where('is_active', true)
            ->get(['min_percent', 'max_percent'])
            ->push((object) ['min_percent' => $this->input('min_percent'), 'max_percent' => $this->input('max_percent')])
            ->map(fn ($row) => ['min' => (float) $row->min_percent, 'max' => (float) $row->max_percent])
            ->sortBy('min')
            ->values();

        if ($ranges->first()['min'] !== 0.0 || $ranges->last()['max'] !== 100.0) {
            return true;
        }

        for ($i = 0; $i < $ranges->count() - 1; $i++) {
            if (round($ranges[$i + 1]['min'] - $ranges[$i]['max'], 2) > 0.01) {
                return true;
            }
        }

        return false;
    }
}
