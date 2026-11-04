<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Exam;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Exam::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('exams', 'name')->withoutTrashed()
                    ->where('class_version_id', $this->integer('class_version_id'))
                    ->where('subject_id', $this->integer('subject_id'))
                    ->where('academic_year_id', $this->integer('academic_year_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'selection_mode' => ['required', 'string', Rule::in(['manual', 'fixed_random', 'per_attempt_random'])],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'passing_score_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],
            'random_question_count' => ['required_if:selection_mode,fixed_random,per_attempt_random', 'nullable', 'integer', 'min:1', 'max:200'],
            'random_difficulty' => ['nullable', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'marks_per_question' => ['required_if:selection_mode,per_attempt_random', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
