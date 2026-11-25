<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicQuestion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'chapter_number' => ['required', 'integer', 'min:1'],
            'chapter_name' => ['required', 'string', 'max:255'],
            'question_type' => ['required', Rule::in(AcademicQuestion::TYPES)],
            'question_text' => ['required', 'string'],
            'options' => ['nullable', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500'],
            'marks' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * MCQ requires exactly four options; CQ must not carry any — enforced
     * here rather than as a declarative rule because both directions
     * depend on question_type's own value, not a fixed field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('question_type');
            $hasOptions = $this->filled('options');

            if ($type === 'mcq' && ! $hasOptions) {
                $validator->errors()->add('options', 'MCQ questions require four options.');
            }

            if ($type === 'cq' && $hasOptions) {
                $validator->errors()->add('options', 'Options are only applicable to MCQ questions.');
            }
        });
    }
}
