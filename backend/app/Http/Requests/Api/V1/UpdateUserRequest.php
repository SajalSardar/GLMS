<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Role/school/district changes go through `PATCH /users/{user}/role`
     * instead, where the privilege-escalation and scope checks live.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'required', 'string', $this->isStudentRole() ? 'min:4' : 'min:8'],
        ];
    }

    /**
     * A student account's password is a short PIN, not a full password.
     * Unlike store(), role isn't part of this request's payload (role
     * changes go through assignRole instead), so this checks the
     * route-bound user's CURRENT role.
     */
    private function isStudentRole(): bool
    {
        return $this->route('user')?->role?->name === 'student';
    }
}
