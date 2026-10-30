<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student_profile')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * user_id is immutable after creation. class_id must belong to the
     * profile's own school (cross-checked in the controller).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $profile = $this->route('student_profile');

        return [
            'class_id' => ['nullable', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'student_id' => ['nullable', 'string', 'max:50', Rule::unique('student_profiles', 'student_id')->ignore($profile)],
            'roll_number' => ['nullable', 'string', 'max:20'],
            'admission_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'blood_group' => ['nullable', 'string', 'max:5'],
            'present_address' => ['nullable', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
