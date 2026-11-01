<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use App\Models\ChapterMaterial;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChapterMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChapterMaterial::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'type' => ['required', 'string', Rule::in(['primary', 'supplementary'])],
            'title' => ['required_if:type,supplementary', 'nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }

    /**
     * Reject a second active 'primary' material for the same chapter.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== 'primary' || ! $this->input('chapter_id')) {
                return;
            }

            $chapter = Chapter::find($this->input('chapter_id'));

            if ($chapter && $chapter->materials()->where('type', 'primary')->where('status', 'active')->exists()) {
                $validator->errors()->add('type', 'This chapter already has an active primary material — update or archive it instead of creating a new one.');
            }
        });
    }
}
