<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subject')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('subjects', 'name')->ignore($this->route('subject')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('subjects', 'code')->ignore($this->route('subject')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
