<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use App\Models\TeacherUser;
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
        return $this->user()?->hasPermission('chapter_materials.create') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'section_id' => [
                Rule::requiredIf(fn () => $this->user() instanceof TeacherUser),
                'nullable', 'integer', Rule::exists('sections', 'id')->withoutTrashed(),
            ],
            'type' => ['required', 'string', Rule::in(['primary', 'supplementary'])],
            'title' => ['required_if:type,supplementary', 'nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived'])],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }

    /**
     * Reject a second active 'primary' material for the same chapter —
     * scoped by section_id, since materials are now scoped per-section:
     * a per-section primary (teacher upload) only clashes with another
     * active primary in that SAME section, while a null section_id
     * (unscoped/staff upload) still clashes chapter-wide, same as
     * before this scoping existed. A material explicitly created as
     * 'archived' never clashes — the "one active primary" rule is about
     * active rows only, and new uploads default to active when status
     * is omitted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== 'primary' || ! $this->input('chapter_id')) {
                return;
            }

            if ($this->input('status', 'active') !== 'active') {
                return;
            }

            $chapter = Chapter::find($this->input('chapter_id'));

            if ($chapter && $chapter->materials()
                ->where('type', 'primary')
                ->where('status', 'active')
                ->where('section_id', $this->input('section_id') ?: null)
                ->exists()) {
                $validator->errors()->add('type', 'This chapter already has an active primary material — update or archive it instead of creating a new one.');
            }
        });
    }
}
