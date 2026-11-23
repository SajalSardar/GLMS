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
            'version_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('versions', 'id')->where('is_active', true),
                Rule::unique('class_versions', 'version_id')->ignore($classVersion)
                    ->where('class_id', $classVersion->class_id)
                    ->where('school_id', $classVersion->school_id),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
