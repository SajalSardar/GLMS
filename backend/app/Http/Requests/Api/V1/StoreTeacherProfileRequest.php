<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', TeacherProfile::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * school_id/district_id are not accepted here — they're derived
     * server-side from the target user's own scope.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->withoutTrashed(),
                Rule::unique('teacher_profiles', 'user_id')->withoutTrashed(),
            ],
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('teacher_profiles', 'employee_id')->withoutTrashed()],
            'designation' => ['nullable', 'string', 'max:255'],
            'subject_specialization' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'nid_number' => ['nullable', 'string', 'max:50', Rule::unique('teacher_profiles', 'nid_number')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20'],
            'blood_group' => ['nullable', 'string', 'max:5'],
            'present_address' => ['nullable', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
