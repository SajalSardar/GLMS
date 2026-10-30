<?php

namespace App\Http\Requests\Api\V1;

use App\Models\School;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', School::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'division_id' => ['required', 'integer', Rule::exists('divisions', 'id')->withoutTrashed()],
            'district_id' => [
                'required', 'integer',
                Rule::exists('districts', 'id')->withoutTrashed()->where('division_id', $this->integer('division_id')),
            ],
            'upazila_id' => [
                'required', 'integer',
                Rule::exists('upazilas', 'id')->withoutTrashed()->where('district_id', $this->integer('district_id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'eiin' => ['nullable', 'string', 'max:50', Rule::unique('schools', 'eiin')->withoutTrashed()],
            'type' => ['required', Rule::in(['government', 'non_government', 'private'])],
            'level' => ['required', Rule::in(['primary', 'secondary', 'higher_secondary', 'college'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
        ];
    }
}
