<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestionChapter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicQuestionChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicQuestionChapter::class) ?? false;
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
        ];
    }
}
