<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('book')) ?? false;
    }

    /**
     * class_version_id and subject_id are immutable after creation —
     * moving a book to a different version/subject isn't supported by
     * this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $book = $this->route('book');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('books', 'name')->ignore($book)
                    ->where('class_version_id', $book->class_version_id)
                    ->where('subject_id', $book->subject_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
