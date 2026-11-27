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
 *
 * Subject configuration and ranking are resolved against the class/section
 * the exam was actually sat in — denormalized onto every academic_exam_marks
 * row at entry time — never the student's CURRENT class/section, so a past
 * marksheet stays stable after a promotion moves the student on.
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
     *
     * `section_position`/`class_position`/`section_total_students`/`class_total_students`
     * are `null`/`0` when `$withPositions` is false, and are computed against
     * the historical exam section/class rather than `$profile`'s current one.
     */
    public function build(StudentProfile $profile, AcademicExamTerm $term, int $academicYearId, bool $withPositions = true): array
    {
        $subjects = $this->subjectResults($profile, $term->id, $academicYearId);

        $gpa = $subjects->isEmpty() ? 0.0 : round($subjects->avg(fn (array $subject) => $subject['grade_point'] ?? 0.0), 2);
        $result = $subjects->every(fn (array $subject) => $subject['passed']) ? 'pass' : 'fail';

        if ($withPositions) {
            [$sectionPosition, $sectionTotal] = $this->rank($profile, $term->id, $academicYearId, acrossClass: false);
            [$classPosition, $classTotal] = $this->rank($profile, $term->id, $academicYearId, acrossClass: true);
        } else {
            [$sectionPosition, $sectionTotal] = [null, 0];
            [$classPosition, $classTotal] = [null, 0];
        }

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
     * Every student in $section's per-subject marks for one (term, year)
     * — the class-wide tabulation sheet behind the management portal's
     * Publish Results "view" screen. Reuses subjectResults() per student;
     * unlike build(), never computes rank/GPA — not needed for this
     * report, and ranking every student here would be an O(n²) cost
     * across the whole section for data this report never shows.
     *
     * $subjects lists every subject configured for the section's class
     * (not just ones with marks entered), so the grid's column set is
     * stable regardless of how much marking is still outstanding.
     *
     * @return array{
     *   subjects: list<array{subject_id:int, subject_name:string}>,
     *   students: list<array{student_profile_id:int, name:?string,
     *     roll_number:?string,
     *     subjects: array<int, array{cq:?int, cq_full:?int, mcq:?int,
     *       mcq_full:?int, practical:?int, practical_full:?int,
     *       total_obtained:int, total_full:int}>,
     *     total_obtained:int, total_full:int},
     * }
     */
    public function sectionGrid(Section $section, AcademicExamTerm $term, int $academicYearId): array
    {
        $classId = $section->shift?->classVersion?->class_id;

        $subjectColumns = AcademicSubjectMarkConfigure::where('class_id', $classId)
            ->with('subject')
            ->get()
            ->map(fn (AcademicSubjectMarkConfigure $configure) => [
                'subject_id' => $configure->subject_id,
                'subject_name' => $configure->subject?->bn_name ?? $configure->subject?->name ?? '',
            ])
            ->values()
            ->all();

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $rows = $students->map(function (StudentProfile $profile) use ($term, $academicYearId) {
            $subjects = $this->subjectResults($profile, $term->id, $academicYearId)->keyBy('subject_id');

            return [
                'student_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'roll_number' => $profile->roll_number,
                'subjects' => $subjects->all(),
                'total_obtained' => (int) $subjects->sum('total_obtained'),
                'total_full' => (int) $subjects->sum('total_full'),
            ];
        })->values()->all();

        return ['subjects' => $subjectColumns, 'students' => $rows];
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

        if ($marks->isEmpty()) {
            return collect();
        }

        // Subject full-marks configuration is looked up by the class this
        // exam was actually sat in — denormalized onto every mark row at
        // entry time — never by the student's CURRENT class_id. Promotion
        // (Phase 4) mutates student_profiles.class_id/section_id in place,
        // so a promoted student's already-published marksheet must keep
        // resolving against the class/section it was graded in, not
        // whatever class they've since moved to.
        $examClassId = $marks->first()->class_id;

        $configuresBySubject = AcademicSubjectMarkConfigure::where('class_id', $examClassId)
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
        [$examClassId, $examSectionId] = $this->examClassAndSection($profile, $termId, $academicYearId);

        if ($examSectionId === null || ! AcademicExamPublication::isPublished($examSectionId, $termId, $academicYearId)) {
            return [null, 0];
        }

        $studentIds = $acrossClass
            ? $this->publishedClassmateIds($profile, $examClassId, $termId, $academicYearId)
            : $this->examRosterIds($profile, [$examSectionId], $termId, $academicYearId);

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
     * The (class_id, section_id) an exam was actually sat under — taken from
     * this student's own denormalized academic_exam_marks rows for this
     * (term, year), never from the student's CURRENT profile whenever any
     * such row exists.
     *
     * A student with no marks for this (term, year) at all falls back to
     * their current placement: there is no historical exam placement to
     * honor for an exam they never sat, so the fallback cannot contradict
     * any history, and it preserves the established behavior that a
     * never-graded student enrolled in a published section is still ranked
     * (last, on a 0.0 GPA) rather than dropping to [null, 0].
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function examClassAndSection(StudentProfile $profile, int $termId, int $academicYearId): array
    {
        $mark = AcademicExamMark::where('student_profile_id', $profile->id)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->first();

        if ($mark === null) {
            return [$profile->class_id, $profile->section_id];
        }

        return [$mark->class_id, $mark->section_id];
    }

    /**
     * The roster one exam is ranked against: the deduplicated UNION of
     * (a) every student CURRENTLY enrolled in $sectionIds, and (b) every
     * student carrying an academic_exam_marks row for this (term, year)
     * denormalized to one of those section_ids.
     *
     * (a) preserves the established semantics that the denominator is
     * everyone enrolled — a never-graded student is still counted and still
     * sorts last on a 0.0 GPA. (b) adds back a student who did sit the exam
     * here but has since been promoted away: their current
     * student_profiles.section_id no longer points at this section, so (a)
     * alone would drop them out of their own already-published ranking and
     * silently renumber everyone who stayed. Reading the historical half
     * from academic_exam_marks is what that table's denormalized
     * class_id/section_id exist for.
     *
     * Both queries are explicitly scoped to $profile's own school_id rather
     * than leaning on the ambient TenantScope, for the same reason as
     * publishedClassmateIds().
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, int>
     */
    private function examRosterIds(StudentProfile $profile, array $sectionIds, int $termId, int $academicYearId): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        $enrolledIds = StudentProfile::whereIn('section_id', $sectionIds)
            ->where('school_id', $profile->school_id)
            ->pluck('id');

        $gradedIds = AcademicExamMark::whereIn('section_id', $sectionIds)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $academicYearId)
            ->where('school_id', $profile->school_id)
            ->pluck('student_profile_id');

        // Currently-enrolled students lead, so ties keep the same order they
        // resolved in before the historical half was unioned in (sortBy() is
        // stable).
        return $enrolledIds->concat($gradedIds)->unique()->values();
    }

    /**
     * The class-wide roster (see examRosterIds() for the union it resolves)
     * across every section belonging to $examClassId, restricted to sections
     * that are themselves published for this exact (term, year) — an
     * unpublished sibling section is excluded entirely, not silently counted
     * with stale/missing data.
     *
     * $examClassId is the class the EXAM was sat in (resolved by the
     * caller via examClassAndSection()), not necessarily $profile's current
     * class_id — see the class-level doc comment on subjectResults()/rank()
     * for why these must never diverge after a promotion.
     *
     * The section query is explicitly scoped to $profile's own school_id
     * rather than relying on the ambient TenantScope global scope:
     * school_classes (the class_id filtered on here) is a NATIONAL
     * table, so an unscoped query would span every school that happens
     * to share the same SchoolClass. TenantScope only narrows a query
     * when the AUTHENTICATED ACTOR has their own school_id — a
     * national_admin (no school_id) gets no restriction at all, and a
     * district-scoped staff actor gets restricted only to the district.
     * This computation must be correct regardless of who's viewing it,
     * so it never leans on the ambient scope here. $profile->school_id is
     * safe to use even for a promoted student: promotion's own cross-school
     * guard (StudentPromotionController) never allows a promotion across
     * schools, so a student's school_id is invariant across any promotion.
     *
     * @return Collection<int, int>
     */
    private function publishedClassmateIds(StudentProfile $profile, ?int $examClassId, int $termId, int $academicYearId): Collection
    {
        if ($examClassId === null) {
            return collect();
        }

        $publishedSectionIds = Section::query()
            ->where('school_id', $profile->school_id)
            ->whereHas('shift.classVersion', fn ($query) => $query->where('class_id', $examClassId))
            ->get()
            ->filter(fn (Section $section) => AcademicExamPublication::isPublished($section->id, $termId, $academicYearId))
            ->pluck('id');

        return $this->examRosterIds($profile, $publishedSectionIds->all(), $termId, $academicYearId);
    }
}
