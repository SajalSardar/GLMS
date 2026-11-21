<?php

namespace App\Support;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicExamTerm;
use App\Models\AcademicGradeConfigure;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * Computes a student's marksheet for one exam term/academic year — grade
 * and grade point per subject, overall GPA, overall Pass/Fail, and
 * section-wise/class-wise position. Nothing here is cached or stored;
 * every call recomputes from current data, same convention as
 * Exam::totalMarks()'s own live computation.
 */
class MarksheetBuilder
{
    /**
     * @return array{
     *   student_profile_id: int,
     *   subjects: list<array{subject_id:int, subject_name:string,
     *     cq:?int, cq_full:?int, mcq:?int, mcq_full:?int,
     *     practical:?int, practical_full:?int,
     *     total_obtained:int, total_full:int, percent:float,
     *     grade_name:?string, grade_point:?float, passed:bool}>,
     *   gpa: float,
     *   result: 'pass'|'fail',
     *   section_position: ?int,
     *   section_total_students: int,
     *   class_position: ?int,
     *   class_total_students: int,
     * }
     */
    public function build(StudentProfile $profile, AcademicExamTerm $term, int $academicYearId): array
    {
        $subjects = $this->subjectResults($profile, $term->id, $academicYearId);

        $gpa = $subjects->isEmpty() ? 0.0 : round($subjects->avg(fn (array $subject) => $subject['grade_point'] ?? 0.0), 2);
        $result = $subjects->every(fn (array $subject) => $subject['passed']) ? 'pass' : 'fail';

        [$sectionPosition, $sectionTotal] = $this->rank($profile, $term->id, $academicYearId, acrossClass: false);
        [$classPosition, $classTotal] = $this->rank($profile, $term->id, $academicYearId, acrossClass: true);

        return [
            'student_profile_id' => $profile->id,
            'subjects' => $subjects->values()->all(),
            'gpa' => $gpa,
            'result' => $result,
            'section_position' => $sectionPosition,
            'section_total_students' => $sectionTotal,
            'class_position' => $classPosition,
            'class_total_students' => $classTotal,
        ];
    }

    /**
     * Per-subject totals/percent/grade/pass for one student. A subject
     * with marks entered but no academic_subject_mark_configures row is
     * skipped (treated as not yet gradeable) rather than throwing —
     * entry-time validation (RecordAcademicExamMarkRequest, Phase 2) is
     * what actually keeps that state from being reachable in practice.
     *
     * @return Collection<int, array{subject_id:int, subject_name:string,
     *   cq:?int, cq_full:?int, mcq:?int, mcq_full:?int,
     *   practical:?int, practical_full:?int,
     *   total_obtained:int, total_full:int, percent:float,
     *   grade_name:?string, grade_point:?float, passed:bool}>
     */
    private function subjectResults(StudentProfile $profile, int $termId, int $academicYearId): Collection
    {
        $marks = AcademicExamMark::where('student_profile_id', $profile->id)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->with('subject')
            ->get();

        $configuresBySubject = AcademicSubjectMarkConfigure::where('class_id', $profile->class_id)
            ->get()
            ->keyBy('subject_id');

        return $marks
            ->map(function (AcademicExamMark $mark) use ($configuresBySubject) {
                $configure = $configuresBySubject->get($mark->subject_id);

                if ($configure === null) {
                    return null;
                }

                $components = [
                    'cq' => [$mark->cq_marks_obtained, $configure->cq_full_marks],
                    'mcq' => [$mark->mcq_marks_obtained, $configure->mcq_full_marks],
                    'practical' => [$mark->practical_marks_obtained, $configure->practical_full_marks],
                ];

                $totalObtained = 0;
                $totalFull = 0;
                $passed = true;

                foreach ($components as [$obtained, $full]) {
                    if ($full === null) {
                        continue;
                    }

                    $effectiveObtained = $obtained ?? 0;
                    $totalObtained += $effectiveObtained;
                    $totalFull += $full;

                    // No rounding of the 0.33 threshold itself — compared
                    // as a float against the integer obtained value (see
                    // this plan's Global Constraints for why).
                    if ($effectiveObtained < $full * 0.33) {
                        $passed = false;
                    }
                }

                $percent = $totalFull > 0 ? round($totalObtained / $totalFull * 100, 2) : 0.0;
                $grade = AcademicGradeConfigure::forPercent($percent);

                return [
                    'subject_id' => $mark->subject_id,
                    'subject_name' => $mark->subject?->bn_name ?? $mark->subject?->name ?? '',
                    'cq' => $mark->cq_marks_obtained,
                    'cq_full' => $configure->cq_full_marks,
                    'mcq' => $mark->mcq_marks_obtained,
                    'mcq_full' => $configure->mcq_full_marks,
                    'practical' => $mark->practical_marks_obtained,
                    'practical_full' => $configure->practical_full_marks,
                    'total_obtained' => $totalObtained,
                    'total_full' => $totalFull,
                    'percent' => $percent,
                    'grade_name' => $grade?->bn_grade_name ?? $grade?->grade_name,
                    'grade_point' => $grade !== null ? (float) $grade->grade_point : null,
                    'passed' => $passed,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private function rank(StudentProfile $profile, int $termId, int $academicYearId, bool $acrossClass): array
    {
        if ($profile->section_id === null || ! AcademicExamPublication::isPublished($profile->section_id, $termId, $academicYearId)) {
            return [null, 0];
        }

        $studentIds = $acrossClass
            ? $this->publishedClassmateIds($profile, $termId, $academicYearId)
            : StudentProfile::where('section_id', $profile->section_id)->pluck('id');

        $ranked = $studentIds
            ->map(function (int $id) use ($termId, $academicYearId) {
                $other = StudentProfile::find($id);

                if ($other === null) {
                    return null;
                }

                $subjects = $this->subjectResults($other, $termId, $academicYearId);

                return [
                    'student_profile_id' => $id,
                    'gpa' => $subjects->isEmpty() ? 0.0 : round($subjects->avg(fn (array $subject) => $subject['grade_point'] ?? 0.0), 2),
                    'total_obtained' => (int) $subjects->sum('total_obtained'),
                ];
            })
            ->filter()
            // Both criteria are descending — negate and sort ascending,
            // since Collection::sortBy()'s multi-criteria array form
            // compares element-wise in one direction only.
            ->sortBy(fn (array $row) => [-$row['gpa'], -$row['total_obtained']])
            ->values();

        $position = $ranked->search(fn (array $row) => $row['student_profile_id'] === $profile->id);

        return [$position === false ? null : $position + 1, $ranked->count()];
    }

    /**
     * Every student in a section belonging to the same class as $profile,
     * restricted to sections that are themselves published for this exact
     * (term, year) — an unpublished sibling section is excluded entirely,
     * not silently counted with stale/missing data.
     *
     * Both queries are explicitly scoped to $profile's own school_id
     * rather than relying on the ambient TenantScope global scope:
     * school_classes (the class_id filtered on here) is a NATIONAL
     * table, so an unscoped query would span every school that happens
     * to share the same SchoolClass. TenantScope only narrows a query
     * when the AUTHENTICATED ACTOR has their own school_id — a
     * national_admin (no school_id) gets no restriction at all, and a
     * district-scoped staff actor gets restricted only to the district.
     * This computation must be correct regardless of who's viewing it,
     * so it never leans on the ambient scope here.
     *
     * @return Collection<int, int>
     */
    private function publishedClassmateIds(StudentProfile $profile, int $termId, int $academicYearId): Collection
    {
        $publishedSectionIds = Section::query()
            ->where('school_id', $profile->school_id)
            ->whereHas('shift.classVersion', fn ($query) => $query->where('class_id', $profile->class_id))
            ->get()
            ->filter(fn (Section $section) => AcademicExamPublication::isPublished($section->id, $termId, $academicYearId))
            ->pluck('id');

        return StudentProfile::whereIn('section_id', $publishedSectionIds)
            ->where('school_id', $profile->school_id)
            ->pluck('id');
    }
}
