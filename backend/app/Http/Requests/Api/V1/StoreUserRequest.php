<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', $this->isStudentRole() ? 'min:4' : 'min:8'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->withoutTrashed()],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }

    /**
     * A student account's password is a short PIN, not a full password —
     * see docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
     * "Login endpoints" for why.
     */
    private function isStudentRole(): bool
    {
        $roleId = $this->integer('role_id') ?: null;

        return $roleId !== null && Role::find($roleId)?->name === 'student';
    }
}
