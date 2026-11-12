<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TeacherUser::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('teacher_users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('teacher_users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:8'],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
