<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAcademicExamTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicExamTerm::class) ?? false;
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
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_annual' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only one term may be the annual/final one — promotion (a later
     * phase of this feature) reads exactly one row with is_annual=true.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->boolean('is_annual') && AcademicExamTerm::where('is_annual', true)->exists()) {
                $validator->errors()->add('is_annual', 'Another exam term is already marked as the annual/final term.');
            }
        });
    }
}
