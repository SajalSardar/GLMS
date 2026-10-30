<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Subject::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('subjects', 'name')->withoutTrashed()],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('subjects', 'code')->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
