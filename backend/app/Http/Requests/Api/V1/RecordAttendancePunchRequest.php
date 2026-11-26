<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAttendancePunchRequest extends FormRequest
{
    /**
     * Authentication here is the kiosk device token, already checked by
     * the `kiosk.auth` middleware before this FormRequest even runs —
     * there is no portal user on this request at all, so there is nothing
     * further to authorize.
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
        return [
            'identifier' => ['required', 'string', 'max:64'],
            'method' => ['required', Rule::in(['id_card', 'face'])],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}
