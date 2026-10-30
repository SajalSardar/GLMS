<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchoolRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('school')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $divisionId = $this->integer('division_id') ?: $this->route('school')->division_id;
        $districtId = $this->integer('district_id') ?: $this->route('school')->district_id;

        return [
            'division_id' => ['sometimes', 'required', 'integer', Rule::exists('divisions', 'id')->withoutTrashed()],
            'district_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('districts', 'id')->withoutTrashed()->where('division_id', $divisionId),
            ],
            'upazila_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('upazilas', 'id')->withoutTrashed()->where('district_id', $districtId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'eiin' => ['nullable', 'string', 'max:50', Rule::unique('schools', 'eiin')->ignore($this->route('school'))],
            'type' => ['sometimes', 'required', Rule::in(['government', 'non_government', 'private'])],
            'level' => ['sometimes', 'required', Rule::in(['primary', 'secondary', 'higher_secondary', 'college'])],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
        ];
    }
}
