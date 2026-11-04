<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('question')) ?? false;
    }

    /**
     * chapter_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_type' => ['sometimes', 'string', Rule::in(['mcq_single', 'mcq_multiple', 'true_false'])],
            'question_text' => ['sometimes', 'required', 'string'],
            'bn_question_text' => ['sometimes', 'nullable', 'string'],
            'marks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'difficulty' => ['sometimes', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'order_index' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png', 'max:'.config('content.max_image_size_kb')],
            'options' => ['sometimes', 'array', 'min:2', 'max:6'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:500'],
            'options.*.bn_option_text' => ['nullable', 'string', 'max:500'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
            'options.*.order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Option-count and correctness rules are per the (possibly unchanged)
     * question_type and span the whole `options` array.
     *
     * When `options` is absent but `question_type` is present, the request
     * would otherwise skip these invariants entirely — a PATCH that only
     * changes question_type could silently leave e.g. a true_false
     * question with 4 stored options. In that case we run the same
     * checks against the question's *currently stored* options instead
     * of the request body.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $options = $this->input('options');

            if (is_array($options)) {
                $type = $this->input('question_type') ?? $this->route('question')->question_type;
                $optionCount = count($options);
                $correctCount = count(array_filter($options, fn ($option) => (bool) ($option['is_correct'] ?? false)));

                $this->addOptionInvariantErrors($validator, $type, $optionCount, $correctCount);

                return;
            }

            if (! $this->has('question_type')) {
                return;
            }

            $storedOptions = $this->route('question')->options;
            $type = $this->input('question_type');
            $optionCount = $storedOptions->count();
            $correctCount = $storedOptions->where('is_correct', true)->count();

            $this->addOptionInvariantErrors($validator, $type, $optionCount, $correctCount);
        });
    }

    private function addOptionInvariantErrors(Validator $validator, mixed $type, int $optionCount, int $correctCount): void
    {
        if ($type === 'true_false' && $optionCount !== 2) {
            $validator->errors()->add('options', 'A true_false question must have exactly 2 options.');
        }

        if (in_array($type, ['mcq_single', 'true_false'], true) && $correctCount !== 1) {
            $validator->errors()->add('options', "A {$type} question must have exactly 1 correct option.");
        }

        if ($type === 'mcq_multiple' && $correctCount < 1) {
            $validator->errors()->add('options', 'An mcq_multiple question must have at least 1 correct option.');
        }
    }
}
