<?php

namespace App\Http\Requests\Api\V1;

use App\Models\KioskDevice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKioskDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', KioskDevice::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actor = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            // A school-scoped actor (school_admin) always gets their own
            // school_id in the controller — this field is only meaningful
            // for an unscoped actor (national_admin) provisioning a device
            // for a specific school, so it's required exactly when the
            // actor has none of their own.
            'school_id' => [
                $actor?->school_id === null ? 'required' : 'nullable',
                'integer',
                Rule::exists('schools', 'id'),
            ],
        ];
    }
}
