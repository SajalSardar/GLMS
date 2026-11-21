<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamMark;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordAcademicExamMarkRequest extends FormRequest
{
    /**
     * section_id/subject_id are resolved here (not route-bound — this is
     * a bulk write, not a single-model route), same pattern as
     * RecordAttendanceRequest.
     */
    public function authorize(): bool
    {
        $section = Section::find($this->integer('section_id'));

        return $section !== null
            && ($this->user()?->can('recordSection', [AcademicExamMark::class, $section, $this->integer('subject_id')]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'academic_exam_term_id' => ['required', 'integer', 'exists:academic_exam_terms,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'integer'],
            'records.*.cq_marks_obtained' => ['nullable', 'integer', 'min:0'],
            'records.*.mcq_marks_obtained' => ['nullable', 'integer', 'min:0'],
            'records.*.practical_marks_obtained' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Rejected outright if (class_id, subject_id) has no
     * academic_subject_mark_configures row — the "entry-time validation"
     * MarksheetBuilder's own doc comment relies on (added in a later
     * phase), so that "marks exist but no config" state is never
     * reachable. Each component's obtained value is then checked against
     * that same config's full marks: null full marks + a non-null
     * obtained value is rejected (nothing configured to grade against); a
     * non-null full marks value caps the obtained value at that ceiling.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $section = Section::find($this->integer('section_id'));
            $classId = $section?->shift?->classVersion?->class_id;

            $configure = AcademicSubjectMarkConfigure::where('class_id', $classId)
                ->where('subject_id', $this->integer('subject_id'))
                ->first();

            if ($configure === null) {
                $validator->errors()->add('subject_id', 'No full marks are configured for this class and subject yet.');

                return;
            }

            $components = [
                'cq_marks_obtained' => $configure->cq_full_marks,
                'mcq_marks_obtained' => $configure->mcq_full_marks,
                'practical_marks_obtained' => $configure->practical_full_marks,
            ];

            foreach ($this->array('records') as $index => $record) {
                foreach ($components as $field => $fullMarks) {
                    $obtained = $record[$field] ?? null;

                    if ($obtained === null) {
                        continue;
                    }

                    if ($fullMarks === null) {
                        $validator->errors()->add("records.{$index}.{$field}", 'This component is not configured for this subject.');
                    } elseif ($obtained > $fullMarks) {
                        $validator->errors()->add("records.{$index}.{$field}", "Obtained marks cannot exceed the full marks ({$fullMarks}).");
                    }
                }
            }
        });
    }
}
