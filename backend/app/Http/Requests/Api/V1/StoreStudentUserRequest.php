<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentUser::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('student_users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('student_users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:4'],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
