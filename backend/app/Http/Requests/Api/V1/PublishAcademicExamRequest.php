<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishAcademicExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicExamPublication::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_exam_term_id' => ['required', 'integer', 'exists:academic_exam_terms,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ];
    }

    /**
     * Two gates before a publish is accepted, same convention as
     * RecordAcademicExamMarkRequest's own year guard: (1) the submitted
     * academic_year_id must match the section's own shift year; (2)
     * every subject configured for this class must have a mark row (any
     * non-null component) for every student in the section — the same
     * "entered" definition status() already reports to the admin BEFORE
     * they publish, now also enforced so an incomplete publish can't
     * happen by a missed click.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $section = Section::find($this->integer('section_id'));
            $sectionAcademicYearId = $section?->shift?->academic_year_id;

            if ($sectionAcademicYearId !== null && $sectionAcademicYearId !== $this->integer('academic_year_id')) {
                $validator->errors()->add('academic_year_id', "This doesn't match the section's own academic year.");

                return;
            }

            $classId = $section?->shift?->classVersion?->class_id;
            $termId = $this->integer('academic_exam_term_id');
            $yearId = $this->integer('academic_year_id');
            $rosterSize = StudentProfile::where('section_id', $section->id)->count();

            $incompleteSubjectNames = AcademicSubjectMarkConfigure::where('class_id', $classId)->with('subject')->get()
                ->filter(function (AcademicSubjectMarkConfigure $configure) use ($section, $termId, $yearId, $rosterSize) {
                    $enteredCount = AcademicExamMark::where('section_id', $section->id)
                        ->where('subject_id', $configure->subject_id)
                        ->where('academic_exam_term_id', $termId)
                        ->where('academic_year_id', $yearId)
                        ->where(fn ($query) => $query->whereNotNull('cq_marks_obtained')
                            ->orWhereNotNull('mcq_marks_obtained')
                            ->orWhereNotNull('practical_marks_obtained'))
                        ->count();

                    return $enteredCount < $rosterSize;
                })
                ->map(fn (AcademicSubjectMarkConfigure $configure) => $configure->subject?->bn_name ?? $configure->subject?->name)
                ->filter()
                ->values();

            if ($incompleteSubjectNames->isNotEmpty()) {
                $validator->errors()->add('section_id', 'Marks are not fully entered for: '.$incompleteSubjectNames->implode(', ').'.');
            }
        });
    }
}
