<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * school_id is immutable after creation — moving a class to a
     * different school isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $class = $this->route('class');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('school_classes', 'name')->ignore($class)->where(function ($query) use ($class) {
                    $query->where('school_id', $class->school_id);

                    $section = $this->filled('section') ? $this->input('section') : $class->section;

                    $section !== null
                        ? $query->where('section', $section)
                        : $query->whereNull('section');
                }),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'numeric_level' => ['nullable', 'integer', 'min:1', 'max:12'],
            'section' => ['nullable', 'string', 'max:10'],
        ];
    }
}
