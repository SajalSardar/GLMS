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
