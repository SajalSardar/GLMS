<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Guardian;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Guardian::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('guardian_users', 'id')->withoutTrashed(),
                Rule::unique('guardians', 'user_id')->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('guardians', 'phone')->withoutTrashed()],
            'email' => ['nullable', 'email', 'max:255'],
            'nid_number' => ['nullable', 'string', 'max:50', Rule::unique('guardians', 'nid_number')->withoutTrashed()],
            'occupation' => ['nullable', 'string', 'max:255'],
            'present_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
