<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVersionSubjectsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Configuring a version's subject list is as sensitive as updating
     * the version itself.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_version')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subjects' => ['required', 'array'],
            'subjects.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'subjects.*.required_weekly_periods' => ['nullable', 'integer', 'min:1', 'max:40'],
        ];
    }
}
