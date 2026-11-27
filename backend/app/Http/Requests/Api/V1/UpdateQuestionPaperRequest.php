<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionPaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('question_paper')) ?? false;
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
            'items.*.type' => ['required', Rule::in(['question', 'heading', 'text'])],
            'items.*.question_type' => ['nullable', Rule::in(['cq', 'mcq'])],
            'items.*.question_text' => ['nullable', 'string', 'max:10000'],
            'items.*.options' => ['nullable', 'array', 'size:4'],
            'items.*.options.*' => ['required', 'string', 'max:500'],
            'items.*.marks' => ['nullable', 'integer', 'min:1'],
            'items.*.serial_label' => ['nullable', 'string', 'max:20'],
            'items.*.indent_level' => ['nullable', 'integer', 'min:0', 'max:5'],
            'items.*.text_content' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * See StoreQuestionPaperRequest's own copy of this check — identical
     * per-type requirements, duplicated rather than shared since these
     * two FormRequests have always been kept as separate, independently
     * editable classes in this codebase.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if ($type === 'question') {
                    foreach (['question_type', 'question_text', 'marks'] as $field) {
                        if (empty($item[$field])) {
                            $validator->errors()->add("items.$index.$field", "A question item requires {$field}.");
                        }
                    }

                    $questionType = $item['question_type'] ?? null;
                    $hasOptions = ! empty($item['options']);

                    if ($questionType === 'mcq' && ! $hasOptions) {
                        $validator->errors()->add("items.$index.options", 'An MCQ question item requires four options.');
                    }

                    if ($questionType === 'cq' && $hasOptions) {
                        $validator->errors()->add("items.$index.options", 'Options are only applicable to MCQ questions.');
                    }
                }

                if (in_array($type, ['heading', 'text'], true) && empty($item['text_content'])) {
                    $validator->errors()->add("items.$index.text_content", "A {$type} item requires text_content.");
                }
            }
        });
    }
}
