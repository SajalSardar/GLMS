<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Question::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'question_type' => ['required', 'string', Rule::in(['mcq_single', 'mcq_multiple', 'true_false'])],
            'question_text' => ['required', 'string'],
            'bn_question_text' => ['nullable', 'string'],
            'marks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'difficulty' => ['sometimes', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'order_index' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.config('content.max_image_size_kb')],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string', 'max:500'],
            'options.*.bn_option_text' => ['nullable', 'string', 'max:500'],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Option-count and correctness rules are per question_type and span
     * the whole `options` array — a plain field rule can't express them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('question_type');
            $options = $this->input('options');

            if (! is_array($options) || ! in_array($type, ['mcq_single', 'mcq_multiple', 'true_false'], true)) {
                return;
            }

            $correctCount = count(array_filter($options, fn ($option) => (bool) ($option['is_correct'] ?? false)));

            if ($type === 'true_false' && count($options) !== 2) {
                $validator->errors()->add('options', 'A true_false question must have exactly 2 options.');
            }

            if (in_array($type, ['mcq_single', 'true_false'], true) && $correctCount !== 1) {
                $validator->errors()->add('options', "A {$type} question must have exactly 1 correct option.");
            }

            if ($type === 'mcq_multiple' && $correctCount < 1) {
                $validator->errors()->add('options', 'An mcq_multiple question must have at least 1 correct option.');
            }
        });
    }
}
