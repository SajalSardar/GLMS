<?php

namespace Database\Seeders;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicExamTerm;
use App\Models\AcademicGradeConfigure;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class AcademicExamResultsDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Gives the Academic Exam Results feature (terms, grade scale, subject
     * mark configuration, mark entry, publish, marksheet viewing,
     * promotion) real data to click through, built on StudentProfileSeeder's
     * demo cohort: Class 6, "Main" section, Debidwar Pilot Government High
     * School (school_id 1) — the same section TimetableGenerationDemoSeeder
     * uses for Class 7.
     *
     * Three exam terms (First/Second Terminal, Annual — only Annual is
     * is_annual), the standard Bangladeshi grading scale, and Class 6's own
     * 7-subject curriculum configured for marks entry. Deliberately leaves
     * three different states for the three terms, so each page has
     * something real to show on first load:
     *  - First Terminal: marks entered for every student, NOT published —
     *    exercises the Publish Results page's "ready to publish" state.
     *  - Second Terminal: nothing at all — exercises Mark Entry's
     *    not-yet-started state.
     *  - Annual: marks entered AND published, with 6 passing/2 failing
     *    students — exercises marksheet viewing, ranking, and the Promote
     *    Students page's live roster with real Pass/Fail/GPA defaults.
     *
     * Deliberately does NOT create any student_class_promotions rows —
     * promoting this cohort from Class 6 into the already-existing Class 7
     * section at the same school is left as the live action to exercise
     * through the Promote Students page.
     *
     * A no-op if its dependencies (StudentProfileSeeder's Class 6 cohort,
     * SectionBackfillSeeder's sections) haven't run.
     */
    public function run(): void
    {
        $school = School::find(1);
        $class6 = SchoolClass::where('name', 'Class 6')->first();
        $year = AcademicYear::where('is_current', true)->first();

        if (! $school || ! $class6 || ! $year) {
            return;
        }

        $section = Section::where('school_id', $school->id)
            ->whereHas('shift.classVersion', fn ($query) => $query->where('class_id', $class6->id))
            ->with('shift')
            ->first();

        $students = $section !== null
            ? StudentProfile::where('section_id', $section->id)->with('user')->orderBy('roll_number')->get()
            : collect();

        if ($section === null || $students->isEmpty()) {
            return;
        }

        [$firstTerm, , $annualTerm] = $this->terms();
        $this->gradeScale();
        $subjects = $this->subjectMarkConfigures($class6->id);

        // First Terminal: entered, not published — a "ready to publish"
        // roster for the Publish Results page.
        $this->recordMarks($students, $subjects, $firstTerm, $year, $section, $this->firstTermPercents());

        // Annual: entered AND published — the roster Promote Students and
        // marksheet viewing actually need, with a mix of pass/fail so the
        // promotion page's default promote/retain split is visible.
        $this->recordMarks($students, $subjects, $annualTerm, $year, $section, $this->annualTermPercents());

        AcademicExamPublication::updateOrCreate(
            [
                'section_id' => $section->id,
                'academic_exam_term_id' => $annualTerm->id,
                'academic_year_id' => $year->id,
            ],
            [
                'school_id' => $school->id,
                'district_id' => $school->district_id,
            ]
        );

        // Second Terminal is deliberately left with no marks at all.
    }

    /**
     * @return array{0: AcademicExamTerm, 1: AcademicExamTerm, 2: AcademicExamTerm}
     */
    private function terms(): array
    {
        $first = AcademicExamTerm::updateOrCreate(
            ['name' => 'First Terminal Examination'],
            ['bn_name' => 'প্রথম সাময়িক পরীক্ষা', 'sort_order' => 1, 'is_annual' => false, 'is_active' => true]
        );

        $second = AcademicExamTerm::updateOrCreate(
            ['name' => 'Second Terminal Examination'],
            ['bn_name' => 'দ্বিতীয় সাময়িক পরীক্ষা', 'sort_order' => 2, 'is_annual' => false, 'is_active' => true]
        );

        $annual = AcademicExamTerm::updateOrCreate(
            ['name' => 'Annual Examination'],
            ['bn_name' => 'বার্ষিক পরীক্ষা', 'sort_order' => 3, 'is_annual' => true, 'is_active' => true]
        );

        return [$first, $second, $annual];
    }

    private function gradeScale(): void
    {
        $grades = [
            ['grade_name' => 'A+', 'bn_grade_name' => 'এ প্লাস', 'min_percent' => 80, 'max_percent' => 100, 'grade_point' => 5.00, 'sort_order' => 1],
            ['grade_name' => 'A', 'bn_grade_name' => 'এ', 'min_percent' => 70, 'max_percent' => 79.99, 'grade_point' => 4.00, 'sort_order' => 2],
            ['grade_name' => 'A-', 'bn_grade_name' => 'এ মাইনাস', 'min_percent' => 60, 'max_percent' => 69.99, 'grade_point' => 3.50, 'sort_order' => 3],
            ['grade_name' => 'B', 'bn_grade_name' => 'বি', 'min_percent' => 50, 'max_percent' => 59.99, 'grade_point' => 3.00, 'sort_order' => 4],
            ['grade_name' => 'C', 'bn_grade_name' => 'সি', 'min_percent' => 40, 'max_percent' => 49.99, 'grade_point' => 2.00, 'sort_order' => 5],
            ['grade_name' => 'D', 'bn_grade_name' => 'ডি', 'min_percent' => 33, 'max_percent' => 39.99, 'grade_point' => 1.00, 'sort_order' => 6],
            ['grade_name' => 'F', 'bn_grade_name' => 'এফ', 'min_percent' => 0, 'max_percent' => 32.99, 'grade_point' => 0.00, 'sort_order' => 7],
        ];

        foreach ($grades as $grade) {
            AcademicGradeConfigure::updateOrCreate(
                ['grade_name' => $grade['grade_name']],
                $grade + ['is_active' => true]
            );
        }
    }

    /**
     * Class 6's own 7-subject curriculum (ClassSubjectSeeder), each split
     * 70 CQ / 30 MCQ — the 100-mark convention this feature's own tests
     * already use.
     *
     * @return Collection<int, AcademicSubjectMarkConfigure> keyed by subject_id
     */
    private function subjectMarkConfigures(int $classId): Collection
    {
        return Subject::whereIn('id', range(1, 7))->get()
            ->mapWithKeys(fn (Subject $subject) => [
                $subject->id => AcademicSubjectMarkConfigure::updateOrCreate(
                    ['class_id' => $classId, 'subject_id' => $subject->id],
                    ['cq_full_marks' => 70, 'mcq_full_marks' => 30, 'practical_full_marks' => null]
                ),
            ]);
    }

    /**
     * roll_number => intended overall percent for every subject. All six
     * pass comfortably — this term is never published, so pass/fail
     * doesn't drive any UI default, only realistic-looking numbers.
     *
     * @return array<string, int>
     */
    private function firstTermPercents(): array
    {
        return ['1' => 88, '2' => 82, '3' => 78, '4' => 65, '5' => 58, '6' => 52, '7' => 45, '8' => 40];
    }

    /**
     * roll_number => intended overall percent for every subject on the
     * Annual term. Six comfortable passes, two clear fails (below the
     * 33% per-component pass threshold) — gives the Promote Students page
     * a real, non-trivial default promote/retain split to display.
     *
     * @return array<string, int>
     */
    private function annualTermPercents(): array
    {
        return ['1' => 92, '2' => 85, '3' => 80, '4' => 68, '5' => 60, '6' => 50, '7' => 20, '8' => 15];
    }

    /**
     * shift_id/class_version_id are derived from $section's own relations
     * (the already-shipped AcademicExamMarkController::record() convention
     * — not from the student profile's own columns, which can be stale or
     * never backfilled for a profile created after SectionBackfillSeeder
     * already ran, e.g. StudentDemoSeeder's cohort in a fresh test-suite
     * seed).
     *
     * @param  Collection<int, StudentProfile>  $students
     * @param  Collection<int, AcademicSubjectMarkConfigure>  $subjects
     * @param  array<string, int>  $percentsByRollNumber
     */
    private function recordMarks(Collection $students, Collection $subjects, AcademicExamTerm $term, AcademicYear $year, Section $section, array $percentsByRollNumber): void
    {
        $shiftId = $section->shift_id;
        $classVersionId = $section->shift?->class_version_id;

        foreach ($students as $profile) {
            $percent = $percentsByRollNumber[$profile->roll_number] ?? 50;

            foreach ($subjects as $subjectId => $configure) {
                AcademicExamMark::updateOrCreate(
                    [
                        'student_profile_id' => $profile->id,
                        'subject_id' => $subjectId,
                        'academic_exam_term_id' => $term->id,
                        'academic_year_id' => $year->id,
                    ],
                    [
                        'school_id' => $section->school_id,
                        'district_id' => $section->district_id,
                        'class_id' => $profile->class_id,
                        'section_id' => $section->id,
                        'shift_id' => $shiftId,
                        'class_version_id' => $classVersionId,
                        'cq_marks_obtained' => (int) round($configure->cq_full_marks * $percent / 100),
                        'mcq_marks_obtained' => (int) round($configure->mcq_full_marks * $percent / 100),
                        'practical_marks_obtained' => null,
                    ]
                );
            }
        }
    }
}
