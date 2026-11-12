<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('teacher_user')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('teacher_users', 'email')->ignore($this->route('teacher_user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('teacher_users', 'phone')->ignore($this->route('teacher_user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }
}
