<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * subject_id is immutable after creation — moving a chapter to a
     * different subject isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $chapter = $this->route('chapter');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('chapters', 'name')->ignore($chapter)->where('subject_id', $chapter->subject_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'chapter_number' => ['nullable', 'string', 'max:50'],
            'bn_chapter_number' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
