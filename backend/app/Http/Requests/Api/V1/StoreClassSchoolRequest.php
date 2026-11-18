<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassSchoolRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchool::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'school_id' => [
                'required', 'integer', Rule::exists('schools', 'id')->withoutTrashed(),
                Rule::unique('class_schools', 'school_id')->where(fn ($query) => $query->where('class_id', $this->integer('class_id'))),
            ],
        ];
    }
}
