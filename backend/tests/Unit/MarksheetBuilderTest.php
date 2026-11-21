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
