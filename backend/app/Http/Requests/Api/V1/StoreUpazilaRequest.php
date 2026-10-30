<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Upazila;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUpazilaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Upazila::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255'],
        ];
    }
}
