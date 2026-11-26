<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    /**
     * Always true — this request only ever targets the authenticated
     * actor's own record ($this->user()), never one supplied by request
     * input, so there is nothing to authorize beyond being logged in.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Works across all four login-table models (User, TeacherUser,
     * StudentUser, GuardianUser) — the table name and whether bn_name
     * exists are both read from the actor's own model rather than
     * hardcoded, since MeController is the one route all four share.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $table = $user->getTable();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique($table, 'email')->ignore($user->id)->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique($table, 'phone')->ignore($user->id)->withoutTrashed()],
        ];

        if (in_array('bn_name', $user->getFillable(), true)) {
            $rules['bn_name'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
