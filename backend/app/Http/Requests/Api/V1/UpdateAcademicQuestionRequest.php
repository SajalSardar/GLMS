<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_question')) ?? false;
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
