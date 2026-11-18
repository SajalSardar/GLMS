<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student_user')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('student_users', 'email')->ignore($this->route('student_user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('student_users', 'phone')->ignore($this->route('student_user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:4'],
        ];
    }
}
