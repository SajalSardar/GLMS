<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ChapterMaterial;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'file' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }

    /**
     * Reject re-activating a 'primary' material if the chapter already has
     * a different active primary material — scoped by the material's OWN
     * section_id (section_id can't be changed on update), matching
     * StoreChapterMaterialRequest's section-scoped clash check: a
     * per-section primary only clashes with another active primary in
     * that SAME section, while a null section_id (unscoped/staff upload)
     * still clashes chapter-wide.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $material = $this->route('chapter_material');

            if ($this->input('status') !== 'active' || $material->type !== 'primary') {
                return;
            }

            $clash = ChapterMaterial::where('chapter_id', $material->chapter_id)
                ->where('type', 'primary')
                ->where('status', 'active')
                ->where('section_id', $material->section_id ?: null)
                ->whereKeyNot($material->getKey())
                ->exists();

            if ($clash) {
                $validator->errors()->add('status', 'This chapter already has an active primary material — archive it before re-activating this one.');
            }
        });
    }
}
