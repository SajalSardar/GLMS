<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('teacher_profile')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * user_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $profile = $this->route('teacher_profile');

        return [
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('teacher_profiles', 'employee_id')->ignore($profile)],
            'designation' => ['nullable', 'string', 'max:255'],
            'subject_specialization' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'nid_number' => ['nullable', 'string', 'max:50', Rule::unique('teacher_profiles', 'nid_number')->ignore($profile)],
            'phone' => ['nullable', 'string', 'max:20'],
            'blood_group' => ['nullable', 'string', 'max:5'],
            'present_address' => ['nullable', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
