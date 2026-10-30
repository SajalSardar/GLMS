<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassVersion;
use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = SchoolClass::find($this->integer('class_id'))?->school;

        return $school !== null && ($this->user()?->can('create', [ClassVersion::class, $school]) ?? false);
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
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('class_versions', 'name')->withoutTrashed()->where('class_id', $this->integer('class_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
