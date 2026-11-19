<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Book;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('books', 'name')->withoutTrashed()
                    ->where('class_version_id', $this->integer('class_version_id'))
                    ->where('subject_id', $this->integer('subject_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
