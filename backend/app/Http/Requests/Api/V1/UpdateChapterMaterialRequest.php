<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChapterMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter_material')) ?? false;
    }

    /**
     * chapter_id and type are immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'order_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived'])],
            'pdf' => ['sometimes', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }
}
