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
            'items.*.academic_question_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_questions', 'id')->withoutTrashed(),
            ],
            'items.*.marks_override' => ['nullable', 'integer', 'min:1'],
            'items.*.serial_label' => ['nullable', 'string', 'max:20'],
            'items.*.text_content' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if ($type === 'question' && empty($item['academic_question_id'])) {
                    $validator->errors()->add("items.$index.academic_question_id", 'A question item requires academic_question_id.');
                }

                if (in_array($type, ['heading', 'text'], true) && empty($item['text_content'])) {
                    $validator->errors()->add("items.$index.text_content", "A {$type} item requires text_content.");
                }
            }
        });
    }
}
