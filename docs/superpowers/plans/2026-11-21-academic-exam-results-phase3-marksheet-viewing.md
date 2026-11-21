# Academic Exam Results — Phase 3: Marksheet Computation & Viewing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compute and expose marksheets (per-subject grade/GPA, overall result, section-wise and class-wise position) from the marks entered in Phase 2, and let teachers (within their own scope) and students (their own, once published) view and print them — without yet building promotion (a later phase).

**Architecture:** A pure `App\Support\MarksheetBuilder` class (no HTTP concerns, precedent `StudentDashboardBuilder`/`TimetablePeriodGrid`) computes everything live from `academic_exam_marks`/`academic_subject_mark_configures`/`academic_grade_configures`/`academic_exam_publications` — nothing is cached or stored. Two new `AcademicExamMarkController` actions expose it (`marksheet` for teacher/staff lookup, `mine`/`mine/terms` for a student's own, gated by publication). A new, broader `AcademicExamMarkPolicy::viewMarksheet()` method (whole-section visibility, not per-subject) gates the teacher/staff lookup path, extending — not replacing — the per-subject `viewSection`/`recordSection` methods from Phase 2.

**Tech Stack:** Laravel 13 / PHP 8.5 (backend), React + TypeScript + Vite (teacher + student frontends), MySQL in production / SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-11-20-academic-exam-results-design.md` — this plan implements the "Computation" section (`App\Support\MarksheetBuilder`) in full, the `/academic-exam-marks/marksheet` and `/academic-exam-marks/mine` rows of "Workflows → endpoints", the marksheet-viewing rows of "Permissions & policies", and the Teacher "Marksheets" + Student "Results" rows of "Frontend". `student_class_promotions`/promotion is a later phase, not this branch.

## Global Constraints

- `MarksheetBuilder` is a pure `App\Support` class — no HTTP concerns, no caching, no stored/materialized grade/GPA/position. Every call recomputes from current data, consistent with `Exam::totalMarks()`'s own live-computation convention.
- **Pass rule, per subject:** for every component whose configured full marks is non-null, `obtained (defaulting to 0 if not yet entered) >= full * 0.33`, compared as a float (no rounding of the 0.33 threshold — e.g. 70 full marks requires `obtained >= 23.1`, so 23 fails and 24 passes). A component whose full marks is null is skipped entirely — never counted toward the subject's total or pass check. This exact rounding behavior is a plan-level decision (Phase 2 never implemented a 33%-threshold check anywhere else to match against, despite an earlier spec draft note suggesting one existed — there wasn't one, so there's nothing to stay consistent with).
- **GPA:** plain average of every subject's `grade_point`, treating a subject with no matching grade range (null `grade_point`) as `0.0` in the average — never excluded from the denominator. **Result:** `'pass'` iff every subject's `passed` is `true` (vacuously `'pass'` if there are zero subjects — no special-casing beyond what `Collection::every()` already does).
- **Position:** both section-wise and class-wise are `null` when the student's own section isn't published for that `(term, year)` — "no fair comparison available." Class-wise ranking only includes students from sections that are *also* published for that same `(term, year)` (an unpublished sibling section is excluded, not silently counted with stale data). Ranking order: GPA descending, then total marks obtained (summed across subjects) descending.
- Marksheet viewing authorization: student via `mine()`/`mine/terms` (self only, requires `AcademicExamPublication::isPublished()` — unpublished is a 404, not an error); teacher via the new `viewMarksheet()` policy method (broader than Phase 2's per-subject `viewSection`/`recordSection` — a teacher with *any* relationship to the section, class-teacher or any subject assignment, can see the *whole* marksheet, since a report card spans every subject, not one); staff via `academic_exam_marks.view` (same permission from Phase 2, TenantScope-limited, no publish-state requirement — staff can audit before publishing).
- After any backend PHP change: `vendor/bin/pint --dirty --format agent` must report `{"result":"passed"}`, and `php artisan test --compact` must stay fully green.
- After any frontend change: `npx tsc -b --noEmit` (run from the specific app directory touched) must produce no output, and `npm run build` (same directory) must succeed.
- Printable pages (Teacher Marksheets, Student Results) follow the established A4 print convention exactly: an inline `<style>{\`@media print { @page { size: A4; margin: ...mm; } body { background: white !important; } } \`}</style>` block, a `print:hidden` wrapper around on-screen-only controls, and `print:*` Tailwind overrides (no shadow/rounding/padding) on the printed content container — same pattern already used by `ClassRoutinePage.tsx` (teacher) and `CertificatePage.tsx` (student).
- Every new/changed i18n key must exist in **both** `packages/i18n/src/common/en.json` and `packages/i18n/src/common/bn.json` with a real Bengali translation — verify with:
  ```bash
  node -e "
  const en = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/en.json','utf8'));
  const bn = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/bn.json','utf8'));
  const enKeys = Object.keys(en).sort();
  const bnKeys = Object.keys(bn).sort();
  console.log('missing in bn:', enKeys.filter(k => !bnKeys.includes(k)));
  console.log('missing in en:', bnKeys.filter(k => !enKeys.includes(k)));
  "
  ```
  run from `frontend/`.
- Never commit without the relevant checks above passing for whatever you touched in that commit.

---

### Task 1: `App\Support\MarksheetBuilder`

**Files:**
- Create: `backend/app/Support/MarksheetBuilder.php`
- Create: `backend/tests/Unit/MarksheetBuilderTest.php`

**Interfaces:**
- Produces: `App\Support\MarksheetBuilder::build(StudentProfile $profile, AcademicExamTerm $term, int $academicYearId): array` — **this exact public signature and the exact shape of its return array are relied on by Task 2's controller `marksheet()` action and Task 3's `mine()` action; do not change either without updating both.** Return shape:
  ```
  array{
    student_profile_id: int,
    subjects: list<array{subject_id:int, subject_name:string,
      cq:?int, cq_full:?int, mcq:?int, mcq_full:?int,
      practical:?int, practical_full:?int,
      total_obtained:int, total_full:int, percent:float,
      grade_name:?string, grade_point:?float, passed:bool}>,
    gpa: float,
    result: 'pass'|'fail',
    section_position: ?int,
    section_total_students: int,
    class_position: ?int,
    class_total_students: int,
  }
  ```
- Consumes: `App\Models\AcademicExamMark`, `App\Models\AcademicSubjectMarkConfigure`, `App\Models\AcademicGradeConfigure::forPercent()`, `App\Models\AcademicExamPublication::isPublished()`, `App\Models\Section` (all from Phase 1/2, already committed) and `App\Models\StudentProfile` — no changes to any of them.

- [x] **Step 1: Write the failing unit test**

`backend/tests/Unit/MarksheetBuilderTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicExamTerm;
use App\Models\AcademicGradeConfigure;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use App\Support\MarksheetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class MarksheetBuilderTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private AcademicExamTerm $term;

    private AcademicYear $year;

    private MarksheetBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->term = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00, 'sort_order' => 1]);
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00, 'sort_order' => 2]);
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 32.99, 'grade_name' => 'F', 'grade_point' => 0.00, 'sort_order' => 7]);

        $this->builder = new MarksheetBuilder;
    }

    private function studentInSection(?string $rollNumber = '1', ?Section $section = null): StudentProfile
    {
        $section ??= $this->section;
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        return StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $section->id,
            'shift_id' => $section->shift_id,
            'class_version_id' => $section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);
    }

    private function mark(StudentProfile $profile, Subject $subject, array $marks): void
    {
        AcademicExamMark::create([
            'student_profile_id' => $profile->id,
            'subject_id' => $subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $profile->section_id,
            'shift_id' => $profile->shift_id,
            'class_version_id' => $profile->class_version_id,
            ...$marks,
        ]);
    }

    public function test_computes_total_obtained_full_percent_grade_and_passed_per_subject(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $profile = $this->studentInSection();
        $this->mark($profile, $subject, ['cq_marks_obtained' => 56, 'mcq_marks_obtained' => 24]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertSame(80, $result['subjects'][0]['total_obtained']);
        $this->assertSame(100, $result['subjects'][0]['total_full']);
        $this->assertSame(80.0, $result['subjects'][0]['percent']);
        $this->assertSame('A+', $result['subjects'][0]['grade_name']);
        $this->assertSame(5.0, $result['subjects'][0]['grade_point']);
        $this->assertTrue($result['subjects'][0]['passed']);
    }

    public function test_skips_null_full_marks_components_from_total_and_pass_check(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        // practical_full_marks left null — not configured for this subject.
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $profile = $this->studentInSection();
        $this->mark($profile, $subject, ['cq_marks_obtained' => 56, 'mcq_marks_obtained' => 24, 'practical_marks_obtained' => null]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertNull($result['subjects'][0]['practical_full']);
        $this->assertSame(100, $result['subjects'][0]['total_full']);
    }

    public function test_a_component_below_33_percent_of_its_full_marks_fails_the_subject(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $profile = $this->studentInSection();
        // 23 < 70 * 0.33 (23.1) — fails on CQ alone, even though the overall total (23+25=48%) would otherwise look passable.
        $this->mark($profile, $subject, ['cq_marks_obtained' => 23, 'mcq_marks_obtained' => 25]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertFalse($result['subjects'][0]['passed']);
        $this->assertSame('fail', $result['result']);
    }

    public function test_gpa_is_the_average_of_subject_grade_points_treating_a_missing_grade_as_zero(): void
    {
        $math = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $math->id, 'cq_full_marks' => 100]);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'cq_full_marks' => 100]);

        $profile = $this->studentInSection();
        $this->mark($profile, $math, ['cq_marks_obtained' => 85]); // 85% -> A+ -> 5.00
        // 50% matches no configured grade range in this test's fixture (only 80-100, 70-79.99, 0-32.99 exist) -> grade_point null -> treated as 0.0.
        $this->mark($profile, $english, ['cq_marks_obtained' => 50]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertSame(2.5, $result['gpa']);
    }

    public function test_result_is_fail_when_any_subject_fails(): void
    {
        $math = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $math->id, 'cq_full_marks' => 100]);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'cq_full_marks' => 100]);

        $profile = $this->studentInSection();
        $this->mark($profile, $math, ['cq_marks_obtained' => 85]);
        $this->mark($profile, $english, ['cq_marks_obtained' => 10]); // fails (10 < 33)

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertSame('fail', $result['result']);
    }

    public function test_result_is_pass_when_every_subject_passes(): void
    {
        $math = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $math->id, 'cq_full_marks' => 100]);

        $profile = $this->studentInSection();
        $this->mark($profile, $math, ['cq_marks_obtained' => 85]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertSame('pass', $result['result']);
    }

    public function test_positions_are_null_when_the_students_own_section_is_unpublished(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 100]);

        $profile = $this->studentInSection();
        $this->mark($profile, $subject, ['cq_marks_obtained' => 85]);

        $result = $this->builder->build($profile, $this->term, $this->year->id);

        $this->assertNull($result['section_position']);
        $this->assertNull($result['class_position']);
        $this->assertSame(0, $result['section_total_students']);
    }

    public function test_section_position_ranks_by_gpa_descending_then_total_obtained_descending(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 100]);

        $top = $this->studentInSection('1');
        $this->mark($top, $subject, ['cq_marks_obtained' => 90]);

        $second = $this->studentInSection('2');
        $this->mark($second, $subject, ['cq_marks_obtained' => 75]);

        AcademicExamPublication::create([
            'section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
        ]);

        $topResult = $this->builder->build($top, $this->term, $this->year->id);
        $secondResult = $this->builder->build($second, $this->term, $this->year->id);

        $this->assertSame(1, $topResult['section_position']);
        $this->assertSame(2, $secondResult['section_position']);
        $this->assertSame(2, $topResult['section_total_students']);
    }

    public function test_class_position_excludes_students_from_an_unpublished_sibling_section(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $subject->id, 'cq_full_marks' => 100]);

        $otherSection = Section::create(['shift_id' => $this->section->shift_id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section B']);

        $mine = $this->studentInSection('1', $this->section);
        $this->mark($mine, $subject, ['cq_marks_obtained' => 50]);

        // Distinct roll_number — student_profiles has a unique(class_id, roll_number)
        // constraint, and this student shares the same class_id as $mine.
        $otherSectionStudent = $this->studentInSection('2', $otherSection);
        $this->mark($otherSectionStudent, $subject, ['cq_marks_obtained' => 95]);

        // Only MY section is published — the other section's student must not appear in my class-wide ranking.
        AcademicExamPublication::create([
            'section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
        ]);

        $result = $this->builder->build($mine, $this->term, $this->year->id);

        $this->assertSame(1, $result['class_position']);
        $this->assertSame(1, $result['class_total_students']);
    }
}
```

- [x] **Step 2: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=MarksheetBuilderTest
```

Expected: FAIL — `App\Support\MarksheetBuilder` doesn't exist yet.

- [x] **Step 3: Write the Support class**

`backend/app/Support/MarksheetBuilder.php`:

```php
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
     * @return Collection<int, int>
     */
    private function publishedClassmateIds(StudentProfile $profile, int $termId, int $academicYearId): Collection
    {
        $publishedSectionIds = Section::query()
            ->whereHas('shift.classVersion', fn ($query) => $query->where('class_id', $profile->class_id))
            ->get()
            ->filter(fn (Section $section) => AcademicExamPublication::isPublished($section->id, $termId, $academicYearId))
            ->pluck('id');

        return StudentProfile::whereIn('section_id', $publishedSectionIds)->pluck('id');
    }
}
```

- [x] **Step 4: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=MarksheetBuilderTest
```

Expected: all 9 tests green.

- [x] **Step 5: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: Pint `{"result":"passed"}`; full suite fully green (no regressions).

- [x] **Step 6: Commit**

```bash
cd backend && git add app/Support/MarksheetBuilder.php tests/Unit/MarksheetBuilderTest.php
git commit -m "Add MarksheetBuilder: grade/GPA/pass/position computation"
```

---

### Task 2: Teacher/staff marksheet lookup (policy extension, controller actions, routes, tests)

**Files:**
- Modify: `backend/app/Policies/AcademicExamMarkPolicy.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`
- Modify: `backend/tests/Feature/AcademicExamMarkControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `AcademicExamMarkPolicy::viewMarksheet(Authenticatable $user, Section $section): bool` — a new, broader (whole-section, not per-subject) ability. Routes (`auth:sanctum`): `GET /api/v1/academic-exam-marks/marksheet`, `GET /api/v1/academic-exam-marks/section-students`.
- Consumes: `App\Support\MarksheetBuilder::build()` (Task 1, same plan), `App\Models\AcademicExamMark`/`Section`/`StudentProfile`/`SubjectTeacherAssignment`/`TeacherUser` (Phase 1/2, already committed).

- [x] **Step 1: Write the failing feature tests**

Append to `backend/tests/Feature/AcademicExamMarkControllerTest.php` (inside the existing `AcademicExamMarkControllerTest` class, after the last existing test method, before the closing `}`):

```php
    public function test_marksheet_is_viewable_by_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))->assertOk();

        $this->actingAs($classTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.subject_id', $this->subject->id);
    }

    public function test_marksheet_is_viewable_by_a_subject_teacher_with_no_class_teacher_role(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $englishTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $englishTeacher->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // The English teacher may view the WHOLE marksheet (every subject,
        // including Math) even though they only teach English — unlike
        // per-subject recordSection(), which would reject them for Math.
        $this->actingAs($englishTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk();
    }

    public function test_marksheet_is_forbidden_for_a_teacher_with_no_relation_to_the_section(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_marksheet_is_viewable_by_staff_with_academic_exam_marks_view(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        $admin = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk();
    }

    public function test_section_students_lists_the_whole_roster_for_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $this->studentInSection('1');
        $this->studentInSection('2');

        $this->actingAs($classTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/section-students?section_id={$this->section->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.students');
    }

    public function test_section_students_is_forbidden_for_a_teacher_with_no_relation_to_the_section(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/section-students?section_id={$this->section->id}")
            ->assertForbidden();
    }
```

- [x] **Step 2: Run the tests to confirm they fail**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkControllerTest
```

Expected: the 6 new tests FAIL (route/policy method/controller actions don't exist yet); the 16 pre-existing tests still PASS.

- [x] **Step 3: Add the policy method**

In `backend/app/Policies/AcademicExamMarkPolicy.php`, add this method to the `AcademicExamMarkPolicy` class, after `recordSection()`:

```php
    /**
     * Whether $user may view the WHOLE marksheet (every subject) for a
     * student in $section — broader than viewSection()/recordSection(),
     * which both gate one specific subject. Any relationship to the
     * section (class teacher, or any subject assignment at all) grants
     * visibility into the full report card, since a report card spans
     * every subject, not one.
     */
    public function viewMarksheet(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)->where('teacher_id', $user->id)->exists();
        }

        return $user->hasPermission('academic_exam_marks.view');
    }
```

- [x] **Step 4: Add the controller actions**

In `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`:

Add imports:

```php
use App\Models\AcademicExamTerm;
use App\Support\MarksheetBuilder;
```

Add a constructor at the top of the class (before `myAssignments()`):

```php
    public function __construct(private readonly MarksheetBuilder $marksheetBuilder) {}
```

Add these two methods, after `record()`:

```php
    /**
     * A student's full marksheet for one exam term/academic year —
     * viewable by the section's class teacher, any teacher with a
     * subject assignment in that section (for ANY subject, not just
     * their own — see AcademicExamMarkPolicy::viewMarksheet()), or staff
     * with academic_exam_marks.view.
     */
    public function marksheet(Request $request): JsonResponse
    {
        $profile = StudentProfile::findOrFail($request->integer('student_profile_id'));
        $section = Section::findOrFail($profile->section_id);
        $term = AcademicExamTerm::findOrFail($request->integer('academic_exam_term_id'));

        $this->authorize('viewMarksheet', [AcademicExamMark::class, $section]);

        return response()->json([
            'data' => $this->marksheetBuilder->build($profile, $term, $request->integer('academic_year_id')),
        ]);
    }

    /**
     * Every student in a section, independent of subject — used by the
     * teacher portal's Marksheets lookup to populate its Student picker
     * once a Section has been chosen.
     */
    public function sectionStudents(Request $request): JsonResponse
    {
        $section = Section::findOrFail($request->integer('section_id'));

        $this->authorize('viewMarksheet', [AcademicExamMark::class, $section]);

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'students' => $students->map(fn (StudentProfile $profile) => [
                    'student_profile_id' => $profile->id,
                    'name' => $profile->user?->name,
                    'roll_number' => $profile->roll_number,
                ])->values()->all(),
            ],
        ]);
    }
```

- [x] **Step 5: Register the routes**

In `backend/routes/api.php`, add to the `auth:sanctum` block, right after the three existing `/academic-exam-marks/*` lines from Phase 2:

```php
Route::get('/academic-exam-marks/marksheet', [AcademicExamMarkController::class, 'marksheet']);
Route::get('/academic-exam-marks/section-students', [AcademicExamMarkController::class, 'sectionStudents']);
```

- [x] **Step 6: Run the tests to confirm they pass**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkControllerTest
```

Expected: all 22 tests green (16 pre-existing + 6 new).

- [x] **Step 7: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: both green.

- [x] **Step 8: Commit**

```bash
cd backend && git add app/Policies/AcademicExamMarkPolicy.php app/Http/Controllers/Api/V1/AcademicExamMarkController.php tests/Feature/AcademicExamMarkControllerTest.php routes/api.php
git commit -m "Add teacher/staff marksheet lookup and section-students listing"
```

---

### Task 3: Student's own results (`mine`/`mine/terms`, routes, tests)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`
- Create: `backend/tests/Feature/AcademicExamMarkMineControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: Routes (`auth:sanctum`): `GET /api/v1/academic-exam-marks/mine/terms`, `GET /api/v1/academic-exam-marks/mine`.
- Consumes: `App\Support\MarksheetBuilder::build()` (Task 1), `App\Models\StudentUser`/`AcademicExamTerm`/`AcademicYear`/`AcademicExamPublication` — no changes to any of them. The constructor-injected `$this->marksheetBuilder` added in Task 2 is reused here, not re-added.

- [x] **Step 1: Write the failing feature test**

`backend/tests/Feature/AcademicExamMarkMineControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicExamTerm;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamMarkMineControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private AcademicExamTerm $publishedTerm;

    private AcademicExamTerm $unpublishedTerm;

    private AcademicYear $year;

    private StudentUser $student;

    private StudentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100]);

        $this->publishedTerm = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $this->unpublishedTerm = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->profile = StudentProfile::create([
            'user_id' => $this->student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => '1',
        ]);

        AcademicExamMark::create([
            'student_profile_id' => $this->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->publishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 90,
        ]);

        AcademicExamPublication::create([
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->publishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        // Marks exist for the unpublished term too, but deliberately no
        // corresponding AcademicExamPublication row — so the fixture has
        // a state where "marks exist" and "is published" disagree, and
        // mineTerms()'s filter is proven to key on publish state rather
        // than mark existence.
        AcademicExamMark::create([
            'student_profile_id' => $this->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->unpublishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 70,
        ]);
    }

    public function test_mine_terms_lists_only_published_terms(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/mine/terms')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($this->publishedTerm->id, $response->json('data.0.academic_exam_term_id'));
    }

    public function test_mine_returns_the_marksheet_for_a_published_term(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.cq', 90);
    }

    public function test_mine_404s_for_an_unpublished_term(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->unpublishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertNotFound();
    }

    public function test_mine_returns_null_data_when_the_student_has_no_profile(): void
    {
        $profileless = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($profileless, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_mine_is_forbidden_for_a_non_student_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }
}
```

- [x] **Step 2: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkMineControllerTest
```

Expected: FAIL — routes/actions don't exist yet.

- [x] **Step 3: Add the controller actions**

In `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`, add the import:

```php
use App\Models\AcademicExamPublication;
use App\Models\AcademicYear;
use App\Models\StudentUser;
```

Add these two methods, after `sectionStudents()`:

```php
    /**
     * Every exam term already published for this student's own section,
     * in the current academic year — the source data behind the student
     * portal's Results page list ("which terms can I see a result for").
     */
    public function mineTerms(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student instanceof StudentUser, 403);

        $profile = $student->profile;

        if ($profile === null || $profile->section_id === null) {
            return response()->json(['data' => []]);
        }

        $academicYearId = AcademicYear::where('is_current', true)->value('id');

        if ($academicYearId === null) {
            return response()->json(['data' => []]);
        }

        $terms = AcademicExamTerm::where('is_active', true)->orderBy('sort_order')->get();

        $published = $terms->filter(
            fn (AcademicExamTerm $term) => AcademicExamPublication::isPublished($profile->section_id, $term->id, $academicYearId)
        );

        return response()->json([
            'data' => $published->map(fn (AcademicExamTerm $term) => [
                'academic_exam_term_id' => $term->id,
                'term_name' => $term->bn_name ?? $term->name,
                'academic_year_id' => $academicYearId,
            ])->values(),
        ]);
    }

    /**
     * This student's own marksheet for one exam term/academic year —
     * 404s outright unless that (section, term, year) has actually been
     * published, same gate mineTerms() already filters its list by.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student instanceof StudentUser, 403);

        $profile = $student->profile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        $term = AcademicExamTerm::findOrFail($request->integer('academic_exam_term_id'));
        $academicYearId = $request->integer('academic_year_id');

        abort_unless(
            $profile->section_id !== null && AcademicExamPublication::isPublished($profile->section_id, $term->id, $academicYearId),
            404
        );

        return response()->json([
            'data' => $this->marksheetBuilder->build($profile, $term, $academicYearId),
        ]);
    }
```

- [x] **Step 4: Register the routes**

In `backend/routes/api.php`, add to the `auth:sanctum` block, right after the two lines added in Task 2:

```php
Route::get('/academic-exam-marks/mine/terms', [AcademicExamMarkController::class, 'mineTerms']);
Route::get('/academic-exam-marks/mine', [AcademicExamMarkController::class, 'mine']);
```

- [x] **Step 5: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkMineControllerTest
```

Expected: all 5 tests green.

- [x] **Step 6: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: both green.

- [x] **Step 7: Commit**

```bash
cd backend && git add app/Http/Controllers/Api/V1/AcademicExamMarkController.php tests/Feature/AcademicExamMarkMineControllerTest.php routes/api.php
git commit -m "Add student's own results: mine/terms list and mine marksheet"
```

---

### Task 4: `api-client` additions

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: TypeScript types `AcademicExamMarksheetSubject`, `AcademicExamMarksheet`, `AcademicExamSectionStudent`, `MyAcademicExamResultTerm`, and functions `getAcademicExamMarksheet`, `getAcademicExamSectionStudents`, `listMyAcademicExamResultTerms`, `getMyAcademicExamResult` — **these exact names are what Tasks 5-6's pages import.**
- Consumes: the existing `apiFetch`/`authHeaders`/`buildQuery` helpers already used throughout this file (same ones Phase 2's additions used).

- [x] **Step 1: Add the four sections**

Append to the end of `frontend/packages/api-client/src/index.ts`:

```typescript
// ---------- Academic exam marksheets (teacher/staff lookup) ----------

export interface AcademicExamMarksheetSubject {
  subject_id: number
  subject_name: string
  cq: number | null
  cq_full: number | null
  mcq: number | null
  mcq_full: number | null
  practical: number | null
  practical_full: number | null
  total_obtained: number
  total_full: number
  percent: number
  grade_name: string | null
  grade_point: number | null
  passed: boolean
}

export interface AcademicExamMarksheet {
  student_profile_id: number
  subjects: AcademicExamMarksheetSubject[]
  gpa: number
  result: 'pass' | 'fail'
  section_position: number | null
  section_total_students: number
  class_position: number | null
  class_total_students: number
}

export function getAcademicExamMarksheet(
  params: { studentProfileId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarksheet }> {
  const query = buildQuery({
    student_profile_id: params.studentProfileId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamMarksheet }>(`/academic-exam-marks/marksheet${query}`, {
    headers: authHeaders(token),
  })
}

export interface AcademicExamSectionStudent {
  student_profile_id: number
  name: string | null
  roll_number: string | null
}

export function getAcademicExamSectionStudents(
  params: { sectionId: number },
  token: string,
): Promise<{ data: { section_id: number; students: AcademicExamSectionStudent[] } }> {
  const query = buildQuery({ section_id: params.sectionId })

  return apiFetch<{ data: { section_id: number; students: AcademicExamSectionStudent[] } }>(
    `/academic-exam-marks/section-students${query}`,
    { headers: authHeaders(token) },
  )
}

// ---------- Academic exam results (student's own) ----------

export interface MyAcademicExamResultTerm {
  academic_exam_term_id: number
  term_name: string
  academic_year_id: number
}

export function listMyAcademicExamResultTerms(token: string): Promise<{ data: MyAcademicExamResultTerm[] }> {
  return apiFetch<{ data: MyAcademicExamResultTerm[] }>('/academic-exam-marks/mine/terms', {
    headers: authHeaders(token),
  })
}

export function getMyAcademicExamResult(
  params: { academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarksheet | null }> {
  const query = buildQuery({ academic_exam_term_id: params.academicExamTermId, academic_year_id: params.academicYearId })

  return apiFetch<{ data: AcademicExamMarksheet | null }>(`/academic-exam-marks/mine${query}`, {
    headers: authHeaders(token),
  })
}
```

- [x] **Step 2: Typecheck**

```bash
cd frontend/packages/api-client && npx tsc -b --noEmit
```

Expected: no output (clean).

- [x] **Step 3: Commit**

```bash
cd frontend && git add packages/api-client/src/index.ts
git commit -m "Add api-client types/functions for marksheet viewing"
```

---

### Task 5: Teacher — Marksheets page

**Files:**
- Create: `frontend/apps/teacher/src/pages/MarksheetsPage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listMyAcademicExamMarkAssignments` (Phase 2, for the Section picker — reusing the already-deduplicated section list, not inventing a second one), `getAcademicExamSectionStudents`, `getAcademicExamMarksheet`, `listAcademicExamTerms`, `listAcademicYears`, and their types from Task 4/Phase 1/2.
- Produces: route `/marksheets`, a flat nav item right after `/marks-entry`.

- [x] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add:

```json
"teacher.nav.marksheets": "Marksheets",
"teacher.marksheets.selectSection": "Select section",
"teacher.marksheets.selectStudent": "Select student",
"teacher.marksheets.selectTerm": "Select exam term",
"teacher.marksheets.selectYear": "Select academic year",
"teacher.marksheets.loading": "Loading…",
"teacher.marksheets.pickAllFilters": "Pick a section, student, exam term, and academic year to view a marksheet.",
"teacher.marksheets.print": "Print",
"teacher.marksheets.colSubject": "Subject",
"teacher.marksheets.colCq": "CQ",
"teacher.marksheets.colMcq": "MCQ",
"teacher.marksheets.colPractical": "Practical",
"teacher.marksheets.colTotal": "Total",
"teacher.marksheets.colGrade": "Grade",
"teacher.marksheets.gpa": "GPA",
"teacher.marksheets.result": "Result",
"teacher.marksheets.resultPass": "Pass",
"teacher.marksheets.resultFail": "Fail",
"teacher.marksheets.sectionPosition": "Section Position",
"teacher.marksheets.classPosition": "Class Position",
"teacher.marksheets.positionNotAvailable": "Not available (not yet published)"
```

In `frontend/packages/i18n/src/common/bn.json`, mirrored with real Bengali translations:

```json
"teacher.nav.marksheets": "মার্কশিট",
"teacher.marksheets.selectSection": "শাখা নির্বাচন করুন",
"teacher.marksheets.selectStudent": "শিক্ষার্থী নির্বাচন করুন",
"teacher.marksheets.selectTerm": "পরীক্ষার মেয়াদ নির্বাচন করুন",
"teacher.marksheets.selectYear": "শিক্ষাবর্ষ নির্বাচন করুন",
"teacher.marksheets.loading": "লোড হচ্ছে…",
"teacher.marksheets.pickAllFilters": "মার্কশিট দেখতে একটি শাখা, শিক্ষার্থী, পরীক্ষার মেয়াদ এবং শিক্ষাবর্ষ নির্বাচন করুন।",
"teacher.marksheets.print": "প্রিন্ট করুন",
"teacher.marksheets.colSubject": "বিষয়",
"teacher.marksheets.colCq": "সিকিউ",
"teacher.marksheets.colMcq": "এমসিকিউ",
"teacher.marksheets.colPractical": "ব্যবহারিক",
"teacher.marksheets.colTotal": "মোট",
"teacher.marksheets.colGrade": "গ্রেড",
"teacher.marksheets.gpa": "জিপিএ",
"teacher.marksheets.result": "ফলাফল",
"teacher.marksheets.resultPass": "উত্তীর্ণ",
"teacher.marksheets.resultFail": "অকৃতকার্য",
"teacher.marksheets.sectionPosition": "শাখা অবস্থান",
"teacher.marksheets.classPosition": "শ্রেণি অবস্থান",
"teacher.marksheets.positionNotAvailable": "উপলব্ধ নয় (এখনও প্রকাশিত হয়নি)"
```

Verify parity (run from `frontend/`):

```bash
node -e "
const en = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/en.json','utf8'));
const bn = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/bn.json','utf8'));
const enKeys = Object.keys(en).sort();
const bnKeys = Object.keys(bn).sort();
console.log('missing in bn:', enKeys.filter(k => !bnKeys.includes(k)));
console.log('missing in en:', bnKeys.filter(k => !enKeys.includes(k)));
"
```

Expected: both arrays empty.

- [x] **Step 2: Write the page**

`frontend/apps/teacher/src/pages/MarksheetsPage.tsx`:

```tsx
import { useEffect, useMemo, useState } from 'react'
import {
  getAcademicExamMarksheet,
  getAcademicExamSectionStudents,
  listAcademicExamTerms,
  listAcademicYears,
  listMyAcademicExamMarkAssignments,
  type AcademicExamMarkAssignment,
  type AcademicExamMarksheet,
  type AcademicExamSectionStudent,
  type AcademicExamTerm,
  type AcademicYear,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

function uniqueByKey<T, K>(items: T[], keyFn: (item: T) => K | null): T[] {
  const seen = new Set<K>()
  const result: T[] = []

  for (const item of items) {
    const key = keyFn(item)
    if (key === null || seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

export default function MarksheetsPage() {
  const { token } = useAuth()

  const [assignments, setAssignments] = useState<AcademicExamMarkAssignment[]>([])
  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [years, setYears] = useState<AcademicYear[]>([])
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [students, setStudents] = useState<AcademicExamSectionStudent[]>([])
  const [studentProfileId, setStudentProfileId] = useState<number | null>(null)
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [loadingMarksheet, setLoadingMarksheet] = useState(false)
  const [marksheet, setMarksheet] = useState<AcademicExamMarksheet | null>(null)

  useEffect(() => {
    if (!token) return

    Promise.all([listMyAcademicExamMarkAssignments(token), listAcademicExamTerms(), listAcademicYears()])
      .then(([assignmentResult, termResult, yearResult]) => {
        setAssignments(assignmentResult.data)
        setTerms(termResult.data)
        setYears(yearResult.data)
      })
      .catch(() => {
        setAssignments([])
        setTerms([])
        setYears([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])

  useEffect(() => {
    if (!token || sectionId === null) {
      setStudents([])
      setStudentProfileId(null)
      return
    }

    let active = true

    getAcademicExamSectionStudents({ sectionId }, token)
      .then((result) => {
        if (!active) return
        setStudents(result.data.students)
      })
      .catch(() => {
        if (active) setStudents([])
      })

    return () => {
      active = false
    }
  }, [token, sectionId])

  useEffect(() => {
    if (!token || studentProfileId === null || academicExamTermId === null || academicYearId === null) {
      setMarksheet(null)
      return
    }

    let active = true
    setLoadingMarksheet(true)

    getAcademicExamMarksheet({ studentProfileId, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (active) setMarksheet(result.data)
      })
      .catch(() => {
        if (active) setMarksheet(null)
      })
      .finally(() => {
        if (active) setLoadingMarksheet(false)
      })

    return () => {
      active = false
    }
  }, [token, studentProfileId, academicExamTermId, academicYearId])

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-xl font-semibold text-gray-900 print:hidden">{t['teacher.nav.marksheets']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm print:hidden">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={sectionId ?? ''}
          onChange={(event) => {
            setSectionId(event.target.value ? Number(event.target.value) : null)
            setStudentProfileId(null)
          }}
        >
          <option value="">{t['teacher.marksheets.selectSection']}</option>
          {sections.map((row) => (
            <option key={row.section_id} value={row.section_id}>
              {[row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
          value={studentProfileId ?? ''}
          disabled={sectionId === null}
          onChange={(event) => setStudentProfileId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksheets.selectStudent']}</option>
          {students.map((student) => (
            <option key={student.student_profile_id} value={student.student_profile_id}>
              {student.roll_number ? `${student.roll_number} - ` : ''}
              {student.name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicExamTermId ?? ''}
          onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksheets.selectTerm']}</option>
          {terms.map((term) => (
            <option key={term.id} value={term.id}>
              {term.bn_name ?? term.name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicYearId ?? ''}
          onChange={(event) => setAcademicYearId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksheets.selectYear']}</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
            </option>
          ))}
        </select>

        {marksheet && (
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
            {t['teacher.marksheets.print']}
          </button>
        )}
      </div>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.loading']}</p>}

      {!loadingFilters && (sectionId === null || studentProfileId === null || academicExamTermId === null || academicYearId === null) && (
        <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.pickAllFilters']}</p>
      )}

      {loadingMarksheet && <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.loading']}</p>}

      {!loadingMarksheet && marksheet && marksheet.subjects.length === 0 && (
        <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.noMarksRecorded']}</p>
      )}

      {!loadingMarksheet && marksheet && marksheet.subjects.length > 0 && (
        <div className="mt-4 rounded-md bg-white p-6 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
          <div className="overflow-x-auto rounded-md border border-gray-100">
            <table className="w-full min-w-[32rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colSubject']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colCq']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colMcq']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colPractical']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colTotal']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colGrade']}</th>
                </tr>
              </thead>
              <tbody>
                {marksheet.subjects.map((subject) => (
                  <tr key={subject.subject_id} className="border-t border-gray-100">
                    <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{subject.subject_name}</td>
                    <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.cq ?? '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.mcq ?? '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.practical ?? '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap text-gray-600">
                      {subject.total_obtained} / {subject.total_full}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.grade_name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div className="rounded-md border border-gray-100 p-3">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.gpa']}</p>
              <p className="mt-1 text-lg font-bold text-gray-900">{marksheet.gpa.toFixed(2)}</p>
            </div>
            <div className="rounded-md border border-gray-100 p-3">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.result']}</p>
              <p className={`mt-1 text-lg font-bold ${marksheet.result === 'pass' ? 'text-success-600' : 'text-danger-600'}`}>
                {marksheet.result === 'pass' ? t['teacher.marksheets.resultPass'] : t['teacher.marksheets.resultFail']}
              </p>
            </div>
            <div className="rounded-md border border-gray-100 p-3">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.sectionPosition']}</p>
              <p className="mt-1 text-lg font-bold text-gray-900">
                {marksheet.section_position !== null
                  ? `${marksheet.section_position} / ${marksheet.section_total_students}`
                  : t['teacher.marksheets.positionNotAvailable']}
              </p>
            </div>
            <div className="rounded-md border border-gray-100 p-3">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.classPosition']}</p>
              <p className="mt-1 text-lg font-bold text-gray-900">
                {marksheet.class_position !== null
                  ? `${marksheet.class_position} / ${marksheet.class_total_students}`
                  : t['teacher.marksheets.positionNotAvailable']}
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
```

- [x] **Step 3: Wire the route and nav item**

In `frontend/apps/teacher/src/App.tsx`, add the import:

```tsx
import MarksheetsPage from './pages/MarksheetsPage'
```

Add the route after the `marks-entry` route (from Phase 2):

```tsx
<Route path="marksheets" element={<MarksheetsPage />} />
```

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, add the nav item to `navItems`, right after the `/marks-entry` entry (from Phase 2):

```tsx
{ to: '/marksheets', label: t['teacher.nav.marksheets'], icon: 'description' },
```

- [x] **Step 4: Typecheck and build**

```bash
cd frontend/apps/teacher && npx tsc -b --noEmit && npm run build
```

Expected: no typecheck output; build succeeds.

- [x] **Step 5: Commit**

```bash
cd frontend && git add apps/teacher/src/pages/MarksheetsPage.tsx apps/teacher/src/App.tsx apps/teacher/src/components/TeacherAppShell.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add teacher Marksheets lookup page"
```

---

### Task 6: Student — Results page

**Files:**
- Create: `frontend/apps/student/src/pages/dashboard/ResultsPage.tsx`
- Modify: `frontend/apps/student/src/App.tsx`
- Modify: `frontend/apps/student/src/components/StudentAppShell.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listMyAcademicExamResultTerms`, `getMyAcademicExamResult`, and their types from Task 4; this app's own `useLocale()` hook (not `getCommonStrings('en')` — the student app supports live locale switching, unlike teacher/management).
- Produces: route `/results`, a flat nav item in `StudentAppShell.tsx`'s `navItems`.

- [x] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add:

```json
"student.nav.results": "Results",
"student.results.title": "My Results",
"student.results.loading": "Loading…",
"student.results.empty": "No results have been published yet.",
"student.results.back": "Back to results",
"student.results.print": "Print",
"student.results.colSubject": "Subject",
"student.results.colCq": "CQ",
"student.results.colMcq": "MCQ",
"student.results.colPractical": "Practical",
"student.results.colTotal": "Total",
"student.results.colGrade": "Grade",
"student.results.gpa": "GPA",
"student.results.result": "Result",
"student.results.resultPass": "Pass",
"student.results.resultFail": "Fail",
"student.results.sectionPosition": "Section Position",
"student.results.classPosition": "Class Position",
"student.results.positionNotAvailable": "Not available"
```

In `frontend/packages/i18n/src/common/bn.json`, mirrored with real Bengali translations:

```json
"student.nav.results": "ফলাফল",
"student.results.title": "আমার ফলাফল",
"student.results.loading": "লোড হচ্ছে…",
"student.results.empty": "এখনও কোনো ফলাফল প্রকাশিত হয়নি।",
"student.results.back": "ফলাফলের তালিকায় ফিরে যান",
"student.results.print": "প্রিন্ট করুন",
"student.results.colSubject": "বিষয়",
"student.results.colCq": "সিকিউ",
"student.results.colMcq": "এমসিকিউ",
"student.results.colPractical": "ব্যবহারিক",
"student.results.colTotal": "মোট",
"student.results.colGrade": "গ্রেড",
"student.results.gpa": "জিপিএ",
"student.results.result": "ফলাফল",
"student.results.resultPass": "উত্তীর্ণ",
"student.results.resultFail": "অকৃতকার্য",
"student.results.sectionPosition": "শাখা অবস্থান",
"student.results.classPosition": "শ্রেণি অবস্থান",
"student.results.positionNotAvailable": "উপলব্ধ নয়"
```

Verify parity with the same `node -e` command as Task 5 Step 1.

- [x] **Step 2: Write the page**

`frontend/apps/student/src/pages/dashboard/ResultsPage.tsx`:

```tsx
import { useEffect, useState } from 'react'
import {
  getMyAcademicExamResult,
  listMyAcademicExamResultTerms,
  type AcademicExamMarksheet,
  type MyAcademicExamResultTerm,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../../lib/locale'

// List-then-detail, same single-page pattern used elsewhere in this app
// rather than a second route — selecting a term swaps the list for its
// marksheet in place, with a Back link to return.
export default function ResultsPage() {
  const { token } = useAuth()
  const { t } = useLocale()

  const [loadingTerms, setLoadingTerms] = useState(true)
  const [terms, setTerms] = useState<MyAcademicExamResultTerm[]>([])

  const [selectedTerm, setSelectedTerm] = useState<MyAcademicExamResultTerm | null>(null)
  const [loadingMarksheet, setLoadingMarksheet] = useState(false)
  const [marksheet, setMarksheet] = useState<AcademicExamMarksheet | null>(null)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoadingTerms(true)

    listMyAcademicExamResultTerms(token)
      .then((result) => {
        if (active) setTerms(result.data)
      })
      .catch(() => {
        if (active) setTerms([])
      })
      .finally(() => {
        if (active) setLoadingTerms(false)
      })

    return () => {
      active = false
    }
  }, [token])

  useEffect(() => {
    if (!token || !selectedTerm) {
      setMarksheet(null)
      return
    }

    let active = true
    setLoadingMarksheet(true)

    getMyAcademicExamResult({ academicExamTermId: selectedTerm.academic_exam_term_id, academicYearId: selectedTerm.academic_year_id }, token)
      .then((result) => {
        if (active) setMarksheet(result.data)
      })
      .catch(() => {
        if (active) setMarksheet(null)
      })
      .finally(() => {
        if (active) setLoadingMarksheet(false)
      })

    return () => {
      active = false
    }
  }, [token, selectedTerm])

  return (
    <section className="px-4 py-6 lg:px-6">
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-2xl font-bold text-primary-800 print:hidden">{t['student.results.title']}</h1>

      {!selectedTerm && (
        <div className="mt-6 print:hidden">
          {loadingTerms && <p className="text-sm text-primary-600">{t['student.results.loading']}</p>}

          {!loadingTerms && terms.length === 0 && <p className="text-sm text-primary-600">{t['student.results.empty']}</p>}

          {!loadingTerms && terms.length > 0 && (
            <ul className="flex flex-col gap-3">
              {terms.map((term) => (
                <li key={`${term.academic_exam_term_id}-${term.academic_year_id}`}>
                  <button
                    type="button"
                    onClick={() => setSelectedTerm(term)}
                    className="flex w-full cursor-pointer items-center justify-between rounded-md bg-white p-4 text-left shadow-sm transition-colors hover:border-primary-300"
                  >
                    <span className="font-semibold text-primary-800">{term.term_name}</span>
                    <span className="material-symbols-outlined text-primary-400">chevron_right</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {selectedTerm && (
        <>
          <div className="mt-4 flex items-center justify-between print:hidden">
            <button type="button" onClick={() => setSelectedTerm(null)} className="text-sm font-semibold text-primary-700 underline">
              ← {t['student.results.back']}
            </button>

            {marksheet && (
              <button
                type="button"
                onClick={() => window.print()}
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
              >
                {t['student.results.print']}
              </button>
            )}
          </div>

          {loadingMarksheet && <p className="mt-6 text-sm text-primary-600 print:hidden">{t['student.results.loading']}</p>}

          {!loadingMarksheet && marksheet && (
            <div className="mt-4 rounded-md bg-white p-6 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
              <div className="overflow-x-auto rounded-md border border-gray-100">
                <table className="w-full min-w-[32rem] text-sm">
                  <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                    <tr>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colSubject']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colCq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colMcq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colPractical']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colTotal']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colGrade']}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {marksheet.subjects.map((subject) => (
                      <tr key={subject.subject_id} className="border-t border-gray-100">
                        <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{subject.subject_name}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.cq ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.mcq ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.practical ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">
                          {subject.total_obtained} / {subject.total_full}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.grade_name ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.gpa']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">{marksheet.gpa.toFixed(2)}</p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.result']}</p>
                  <p className={`mt-1 text-lg font-bold ${marksheet.result === 'pass' ? 'text-success-600' : 'text-danger-600'}`}>
                    {marksheet.result === 'pass' ? t['student.results.resultPass'] : t['student.results.resultFail']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.sectionPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.section_position !== null
                      ? `${marksheet.section_position} / ${marksheet.section_total_students}`
                      : t['student.results.positionNotAvailable']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.classPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.class_position !== null
                      ? `${marksheet.class_position} / ${marksheet.class_total_students}`
                      : t['student.results.positionNotAvailable']}
                  </p>
                </div>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  )
}
```

- [x] **Step 3: Wire the route and nav item**

In `frontend/apps/student/src/App.tsx`, add the import:

```tsx
import DashboardResultsPage from './pages/dashboard/ResultsPage'
```

Add the route inside the existing dashboard `RequireAuth` block, after the `attendance` route:

```tsx
<Route path="results" element={<DashboardResultsPage />} />
```

In `frontend/apps/student/src/components/StudentAppShell.tsx`, add the nav item to `navItems`, right after the `/attendance` entry:

```tsx
{ to: '/results', label: t['student.nav.results'], icon: 'school' },
```

- [x] **Step 4: Typecheck and build**

```bash
cd frontend/apps/student && npx tsc -b --noEmit && npm run build
```

Expected: no typecheck output; build succeeds.

- [x] **Step 5: Commit**

```bash
cd frontend && git add apps/student/src/pages/dashboard/ResultsPage.tsx apps/student/src/App.tsx apps/student/src/components/StudentAppShell.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add student Results page"
```

---

### Task 7: End-to-end verification

**Files:** none (verification only — no code changes).

**Interfaces:** none.

- [x] **Step 1: Run the full backend suite one more time**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both fully green.

- [x] **Step 2: Typecheck and build all three frontend apps one more time**

```bash
cd frontend/apps/teacher && npx tsc -b --noEmit && npm run build
cd ../management && npx tsc -b --noEmit && npm run build
cd ../student && npx tsc -b --noEmit && npm run build
```

Expected: clean.

- [ ] **Step 3: Manual smoke test in the browser**

Using the data seeded by Phases 1-2's own smoke tests (exam terms, grade scale, subject mark configures, entered marks, at least one published section/term/year):

1. As the section's class teacher: navigate to Marksheets, pick that section, pick a student, pick the published exam term and year, confirm the marksheet renders with per-subject grades, GPA, Result, and (since the section is published) a real section position (and class position, if other sections share the class and at least one is also published). Click Print and confirm the browser print preview shows a clean A4 page with no on-screen controls.
2. As a teacher only assigned via Subject Teacher Assignment to one subject in that section: confirm they can still view the SAME student's full marksheet (every subject, not just their own).
3. As a teacher with no relation to that section: confirm Marksheets' Section dropdown does not offer that section at all (same `my-assignments` scoping already used by Marks Entry).
4. As the student whose marksheet was just viewed: log into the student portal, navigate to Results, confirm the published term appears in the list, click it, confirm the same marksheet renders (subjects, GPA, Result, positions), and confirm an unpublished term does NOT appear in the list at all.
5. As a school_admin: confirm `/academic-exam-marks/marksheet` is reachable for any student in their school even before publishing (staff audit, no publish-state requirement) — e.g. via the Teacher Marksheets page if the admin role has access, or by confirming the earlier `test_marksheet_is_viewable_by_staff_with_academic_exam_marks_view` test already covers this at the API level.

Report back what you observed; this step has no pass/fail assertion beyond "did the two screens behave as described."

- [ ] **Step 4: No commit for this task** (verification-only).

---

## Post-review fixes

The final whole-branch review (after all 6 tasks above had already
passed their own task-level reviews) found and fixed two cross-cutting
issues:

- **`MarksheetBuilder::publishedClassmateIds()` had no explicit school
  boundary.** It built the class-wide ranking pool by filtering
  `school_classes`-backed sections by `class_id` alone, relying on the
  ambient `TenantScope` global scope to keep the pool within one
  school. Since `school_classes` is a national table shared by every
  school, this meant a `national_admin` viewer (no `school_id` of
  their own) got a ranking pool spanning every school in the country,
  and a district-scoped staff viewer got one spanning the whole
  district — the same marksheet's `class_position`/
  `class_total_students` would differ depending on who requested it.
  Fixed by adding an explicit `->where('school_id', $profile->school_id)`
  to both the `Section` and `StudentProfile` queries in
  `publishedClassmateIds()`, so the computation is correct regardless
  of the viewer's own scope. A regression test
  (`test_class_position_excludes_students_from_a_different_school_sharing_the_same_class`)
  was added to `MarksheetBuilderTest`, using a second school that
  reuses the same `SchoolClass` row.
- **An empty marksheet (zero subjects) rendered a confident green
  "Pass."** `Collection::every()` on an empty collection is vacuously
  `true`, so `MarksheetBuilder::build()` correctly (and deliberately,
  per this plan's Global Constraints) returns `result: 'pass'`, `gpa:
  0.0` for a student with no `academic_exam_marks` rows for the
  requested (term, year). Neither `MarksheetsPage.tsx` nor
  `ResultsPage.tsx` checked for this case before rendering, so a
  genuinely empty marksheet looked identical to a real, passing one.
  Both pages now branch on `marksheet.subjects.length === 0` and render
  an i18n'd empty-state message (`teacher.marksheets.noMarksRecorded` /
  `student.results.noMarksRecorded`, both with real Bengali
  translations) instead of the table/summary in that case. The
  `MarksheetsPage.tsx` code block in Task 5 above already reflects this
  fix (along with the unrelated `setStudentProfileId(null)` ordering
  fix from commit `998f588`); the equivalent branch in
  `ResultsPage.tsx` (Task 6) is the same shape, gated on
  `student.results.noMarksRecorded`.

---

## What this plan does NOT cover

Explicitly deferred to the next phase, per the spec: `student_class_promotions` (bulk promotion for passing students), the Promote Students management page, and the `student-promotions` endpoints. None of those are started by this plan.
