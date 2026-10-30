<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('section')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * shift_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('sections', 'name')->ignore($section)->where('shift_id', $section->shift_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'class_teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
            'default_classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'is_active' => ['boolean'],
        ];
    }
}
