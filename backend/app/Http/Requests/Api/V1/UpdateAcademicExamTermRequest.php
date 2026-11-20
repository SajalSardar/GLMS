<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicExamTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_exam_term')) ?? false;
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_annual' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same single-annual-term guard as the store request, excluding this
     * row itself (re-saving the already-annual term must not self-reject).
     */
    public function withValidator(Validator $validator): void
    {
        $term = $this->route('academic_exam_term');

        $validator->after(function (Validator $validator) use ($term) {
            if ($this->boolean('is_annual') && AcademicExamTerm::where('is_annual', true)->whereKeyNot($term->id)->exists()) {
                $validator->errors()->add('is_annual', 'Another exam term is already marked as the annual/final term.');
            }
        });
    }
}
