<?php

namespace App\Http\Requests\Api\V1;

use App\Models\QuestionPaper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionPaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QuestionPaper::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'time_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'full_marks' => ['nullable', 'integer', 'min:1'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['question', 'heading'])],
            'items.*.academic_question_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_questions', 'id')->where(fn ($query) => $query->where('is_active', true))->withoutTrashed(),
            ],
            'items.*.heading_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Each item must carry exactly the field its own type needs — the
     * declarative rules above can't express "required only when the SAME
     * array index's type is X", so that cross-field check runs here
     * instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if ($type === 'question' && empty($item['academic_question_id'])) {
                    $validator->errors()->add("items.$index.academic_question_id", 'A question item requires academic_question_id.');
                }

                if ($type === 'heading' && empty($item['heading_text'])) {
                    $validator->errors()->add("items.$index.heading_text", 'A heading item requires heading_text.');
                }
            }
        });
    }
}
