<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicSubjectMarkConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_subject_mark_configure')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['sometimes', 'required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'cq_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'mcq_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'practical_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * The class/subject uniqueness check and the at-least-one-component
     * guard both live here, not in `rules()`, and both use *effective*
     * values (submitted, falling back to the existing row) — not just for
     * the component guard, but for uniqueness too. A `Rule::unique(...)`
     * attached only to `class_id`'s own rule entry would never run on a
     * PATCH that submits only `subject_id` (a `sometimes` rule simply
     * skips validating a field that's absent from the request), silently
     * letting that request move the row onto a (class_id, subject_id)
     * pair that's already configured elsewhere. Computing both effective
     * values here checks the real resulting pair regardless of which
     * field(s) were actually submitted.
     */
    public function withValidator(Validator $validator): void
    {
        $configure = $this->route('academic_subject_mark_configure');

        $validator->after(function (Validator $validator) use ($configure) {
            if ($validator->errors()->hasAny(['class_id', 'subject_id'])) {
                return;
            }

            $classId = $this->input('class_id', $configure->class_id);
            $subjectId = $this->input('subject_id', $configure->subject_id);

            $duplicate = AcademicSubjectMarkConfigure::withTrashed()
                ->where('class_id', $classId)
                ->where('subject_id', $subjectId)
                ->whereKeyNot($configure->id)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('class_id', 'This class/subject pair is already configured.');
            }

            $cq = $this->has('cq_full_marks') ? $this->input('cq_full_marks') : $configure->cq_full_marks;
            $mcq = $this->has('mcq_full_marks') ? $this->input('mcq_full_marks') : $configure->mcq_full_marks;
            $practical = $this->has('practical_full_marks') ? $this->input('practical_full_marks') : $configure->practical_full_marks;

            if ($cq === null && $mcq === null && $practical === null) {
                $validator->errors()->add('cq_full_marks', 'At least one of CQ, MCQ, or Practical full marks must be set.');
            }
        });
    }
}
