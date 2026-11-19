<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePracticeExamRequest extends FormRequest
{
    /**
     * Public — a guest must be able to provision a practice exam, same
     * as the public /exams/{exam}/random-draw and /guest-submissions
     * routes.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'chapter_ids' => ['required', 'array', 'min:1', 'max:50'],
            'chapter_ids.*' => ['distinct', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Every chapter must belong to a book with the submitted subject_id
     * — unlike SetExamRandomChaptersRequest's equivalent check, there is
     * deliberately no class_version_id constraint here: a practice
     * exam's chapters are pooled across every school's version of the
     * student's chosen class.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ids = $this->input('chapter_ids', []);

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Chapter::query()
                ->whereIn('id', $ids)
                ->whereHas('book', fn ($query) => $query->where('subject_id', $this->integer('subject_id')))
                ->count();

            if ($matching !== count(array_unique($ids))) {
                $validator->errors()->add('chapter_ids', 'One or more chapters do not belong to a book under the submitted subject.');
            }
        });
    }
}
