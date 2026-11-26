<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class UpdateMyPasswordRequest extends FormRequest
{
    /**
     * Always true — same reasoning as UpdateMeRequest: this only ever
     * changes the authenticated actor's own password.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Students log in with a short PIN-style password (see
        // StoreStudentUserRequest's own min:4), not a full password — kept
        // consistent here so a student can still set their own 4+ char PIN
        // rather than being held to the 8-character staff/guardian minimum.
        $minLength = $this->user() instanceof StudentUser ? 4 : 8;

        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', "min:{$minLength}", 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! Hash::check($this->input('current_password'), $this->user()->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');
            }
        });
    }
}
