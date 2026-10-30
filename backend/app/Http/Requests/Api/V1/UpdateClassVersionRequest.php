<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_version')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * class_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $classVersion = $this->route('class_version');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('class_versions', 'name')->ignore($classVersion)->where('class_id', $classVersion->class_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
