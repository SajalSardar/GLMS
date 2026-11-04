<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    /**
     * class_version_id, subject_id, academic_year_id, and selection_mode
     * are immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $exam = $this->route('exam');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('exams', 'name')->ignore($exam)
                    ->where('class_version_id', $exam->class_version_id)
                    ->where('subject_id', $exam->subject_id)
                    ->where('academic_year_id', $exam->academic_year_id),
            ],
            'bn_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'passing_score_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'available_until' => ['sometimes', 'nullable', 'date', 'after:available_from'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
            'random_question_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'random_difficulty' => ['sometimes', 'nullable', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'marks_per_question' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Publishing an exam requires it to actually be takeable: a
     * manual/fixed_random exam needs at least one attached question; a
     * per_attempt_random exam needs its random criteria set and an
     * eligible pool large enough to satisfy random_question_count.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('status') !== 'published') {
                return;
            }

            $exam = $this->route('exam');

            if (in_array($exam->selection_mode, ['manual', 'fixed_random'], true)) {
                if ($exam->questions()->count() === 0) {
                    $validator->errors()->add('status', 'This exam has no questions yet and cannot be published.');
                }

                return;
            }

            // per_attempt_random
            $count = $this->input('random_question_count') ?? $exam->random_question_count;
            $difficulty = $this->input('random_difficulty') ?? $exam->random_difficulty;

            if (! $count) {
                $validator->errors()->add('status', 'This exam has no random_question_count set and cannot be published.');

                return;
            }

            $chapterIds = Chapter::query()
                ->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
                ->pluck('id');

            $allowedChapterIds = $exam->randomChapters()->pluck('chapters.id');

            if ($allowedChapterIds->isNotEmpty()) {
                $chapterIds = $chapterIds->intersect($allowedChapterIds);
            }

            $poolSize = Question::query()
                ->whereIn('chapter_id', $chapterIds)
                ->when($difficulty, fn ($query, $value) => $query->where('difficulty', $value))
                ->count();

            if ($poolSize < $count) {
                $validator->errors()->add('status', "Only {$poolSize} eligible question(s) match this exam's random criteria, but random_question_count is {$count}.");
            }
        });
    }
}
