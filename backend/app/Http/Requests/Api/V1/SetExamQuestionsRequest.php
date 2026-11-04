<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetExamQuestionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', Rule::exists('questions', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Rejected for per_attempt_random exams (which never use
     * exam_questions), and every given question must belong to a chapter
     * reachable through a book under this exam's (class_version_id,
     * subject_id) pair — a plain Rule::exists can't express that
     * relational constraint.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $exam = $this->route('exam');

            if ($exam->selection_mode === 'per_attempt_random') {
                $validator->errors()->add('question_ids', 'This exam uses per_attempt_random selection and does not accept a manual question list.');

                return;
            }

            $ids = $this->input('question_ids', []);

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Question::query()
                ->whereIn('id', $ids)
                ->whereHas('chapter.book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
                ->count();

            if ($matching !== count(array_unique($ids))) {
                $validator->errors()->add('question_ids', "One or more questions do not belong to a chapter under this exam's subject and class version.");
            }
        });
    }
}
