<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestion;
use App\Models\Chapter;
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
            'version_id' => ['required', 'integer', Rule::exists('versions', 'id')],
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
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

            $this->validateChapterBelongsToSelection($validator);
        });
    }

    /**
     * The chapter picker is narrowed client-side by class/version/subject,
     * but nothing stops a crafted request from sending a chapter_id that
     * doesn't actually belong to the selected class/subject/version — so
     * it's re-checked here via the chapter's own book.
     */
    private function validateChapterBelongsToSelection(Validator $validator): void
    {
        $chapterId = $this->input('chapter_id');
        if ($chapterId === null || ! $this->filled('school_class_id') || ! $this->filled('subject_id') || ! $this->filled('version_id')) {
            return;
        }

        $chapter = Chapter::with('book.classVersion')->find($chapterId);
        $book = $chapter?->book;

        if ($book === null
            || (int) $book->subject_id !== (int) $this->input('subject_id')
            || (int) $book->classVersion?->class_id !== (int) $this->input('school_class_id')
            || (int) $book->classVersion?->version_id !== (int) $this->input('version_id')
        ) {
            $validator->errors()->add('chapter_id', 'The selected chapter does not belong to the selected class, version, and subject.');
        }
    }
}
