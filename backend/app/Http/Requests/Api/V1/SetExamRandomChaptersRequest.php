<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetExamRandomChaptersRequest extends FormRequest
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
            'chapter_ids' => ['present', 'array'],
            'chapter_ids.*' => ['integer', Rule::exists('chapters', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Rejected for manual exams, which never use exam_random_chapters.
     * An empty array is valid and clears the narrowing (reverting to
     * "any chapter under this exam's subject+class-version is
     * eligible"). Every given chapter must be reachable through a book
     * under this exam's (class_version_id, subject_id) pair.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $exam = $this->route('exam');

            if ($exam->selection_mode === 'manual') {
                $validator->errors()->add('chapter_ids', 'Manual exams do not use random chapter narrowing.');

                return;
            }

            $ids = $this->input('chapter_ids', []);

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Chapter::query()
                ->whereIn('id', $ids)
                ->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
                ->count();

            if ($matching !== count(array_unique($ids))) {
                $validator->errors()->add('chapter_ids', "One or more chapters do not belong to a book under this exam's subject and class version.");
            }
        });
    }
}
