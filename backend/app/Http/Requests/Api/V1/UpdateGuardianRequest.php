<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuardianRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('guardian')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guardian = $this->route('guardian');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('guardians', 'phone')->ignore($guardian)],
            'email' => ['nullable', 'email', 'max:255'],
            'nid_number' => ['nullable', 'string', 'max:50', Rule::unique('guardians', 'nid_number')->ignore($guardian)],
            'occupation' => ['nullable', 'string', 'max:255'],
            'present_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
