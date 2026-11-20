<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicSubjectMarkConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicSubjectMarkConfigure::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => [
                'required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed(),
            ],
            'subject_id' => [
                'required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed(),
                Rule::unique('academic_subject_mark_configures')->withoutTrashed()->where(fn ($query) => $query->where('class_id', $this->input('class_id'))),
            ],
            'cq_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'mcq_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'practical_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * At least one component must be configured, or every downstream
     * percentage calculation (full marks = 0) would divide by zero.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('cq_full_marks') && ! $this->filled('mcq_full_marks') && ! $this->filled('practical_full_marks')) {
                $validator->errors()->add('cq_full_marks', 'At least one of CQ, MCQ, or Practical full marks must be set.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_id.unique' => 'This class/subject pair is already configured.',
        ];
    }
}
