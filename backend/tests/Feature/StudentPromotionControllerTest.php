<?php

namespace Tests\Feature;

use App\Models\AcademicExamMark;
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
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentPromotionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class6;

    private SchoolClass $class7;

    private Section $section;

    private Section $toSection;

    private Subject $subject;

    private AcademicExamTerm $annualTerm;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class6 = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->class7 = SchoolClass::create(['name' => 'Class 7', 'numeric_level' => 7]);

        $version = ClassVersion::create(['class_id' => $this->class6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $toVersion = ClassVersion::create(['class_id' => $this->class7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $toShift = Shift::create(['class_version_id' => $toVersion->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->toSection = Section::create(['shift_id' => $toShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class6->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100]);

        $this->annualTerm = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    private function studentInSection(?string $rollNumber, ?int $cqMarks): StudentProfile
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class6->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);

        if ($cqMarks !== null) {
            AcademicExamMark::create([
                'student_profile_id' => $profile->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->annualTerm->id,
                'academic_year_id' => $this->year->id,
                'school_id' => $this->school->id,
                'district_id' => $this->school->district_id,
                'class_id' => $this->class6->id,
                'section_id' => $this->section->id,
                'shift_id' => $this->section->shift_id,
                'class_version_id' => $this->section->shift->class_version_id,
                'cq_marks_obtained' => $cqMarks,
            ]);
        }

        return $profile;
    }

    public function test_preview_defaults_passing_students_to_promote_and_failing_students_to_retain(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk();

        $rows = collect($response->json('data.students'))->keyBy('student_profile_id');
        $this->assertSame('promote', $rows[$pass->id]['default_action']);
        $this->assertSame('pass', $rows[$pass->id]['result']);
        $this->assertSame('retain', $rows[$fail->id]['default_action']);
        $this->assertSame('fail', $rows[$fail->id]['result']);
    }

    public function test_preview_suggests_the_next_numeric_level_class(): void
    {
        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.suggested_to_class_id', $this->class7->id);
    }

    public function test_preview_marks_a_student_with_no_entered_marks_as_no_marks_defaulting_to_retain(): void
    {
        $ungraded = $this->studentInSection('1', null);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('student_profile_id', $ungraded->id);
        $this->assertSame('no_marks', $row['result']);
        $this->assertSame('retain', $row['default_action']);
    }

    public function test_preview_requires_the_permission(): void
    {
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_preview_rejects_when_no_annual_term_is_configured(): void
    {
        $this->annualTerm->update(['is_annual' => false]);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertUnprocessable();
    }

    public function test_store_promotes_a_passing_student_by_moving_their_profile_in_place(): void
    {
        $pass = $this->studentInSection('1', 85);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertCreated();

        $pass->refresh();
        $this->assertSame($this->class7->id, $pass->class_id);
        $this->assertSame($this->toSection->id, $pass->section_id);
        $this->assertSame($this->toSection->shift_id, $pass->shift_id);
        $this->assertSame($this->toSection->shift->class_version_id, $pass->class_version_id);
        $this->assertSame('1', $pass->roll_number);
    }

    public function test_store_leaves_a_retained_students_profile_completely_untouched(): void
    {
        $fail = $this->studentInSection('2', 10);
        $originalAttributes = $fail->only(['class_id', 'section_id', 'shift_id', 'class_version_id']);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $fail->id, 'promote' => false]],
        ])->assertCreated();

        $fail->refresh();
        $this->assertSame($originalAttributes, $fail->only(['class_id', 'section_id', 'shift_id', 'class_version_id']));
    }

    public function test_store_writes_an_audit_row_for_every_student_with_null_to_fields_for_retained(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [
                ['student_profile_id' => $pass->id, 'promote' => true],
                ['student_profile_id' => $fail->id, 'promote' => false],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('student_class_promotions', 2);
        $this->assertDatabaseHas('student_class_promotions', [
            'student_profile_id' => $pass->id, 'result' => 'pass', 'to_section_id' => $this->toSection->id,
            'to_class_id' => $this->class7->id, 'promoted_by_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('student_class_promotions', [
            'student_profile_id' => $fail->id, 'result' => 'fail', 'to_section_id' => null, 'to_class_id' => null,
        ]);
    }

    public function test_store_honors_a_per_student_override_in_both_directions(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        // Override: promote the failing student, retain the passing one.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [
                ['student_profile_id' => $pass->id, 'promote' => false],
                ['student_profile_id' => $fail->id, 'promote' => true],
            ],
        ])->assertCreated();

        $pass->refresh();
        $fail->refresh();
        $this->assertSame($this->class6->id, $pass->class_id);
        $this->assertSame($this->class7->id, $fail->class_id);
    }

    public function test_store_requires_the_permission(): void
    {
        $pass = $this->studentInSection('1', 85);
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertForbidden();
    }

    public function test_store_is_forbidden_for_a_teacher_even_as_the_sections_own_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);
        $pass = $this->studentInSection('1', 85);

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertForbidden();
    }

    public function test_store_rejects_a_target_section_from_a_different_school(): void
    {
        $otherDivision = Division::create(['name' => 'Other Division', 'bn_name' => 'অন্য বিভাগ']);
        $otherDistrict = District::create(['division_id' => $otherDivision->id, 'name' => 'Other District', 'bn_name' => 'অন্য জেলা']);
        $otherUpazila = Upazila::create(['district_id' => $otherDistrict->id, 'name' => 'Other Upazila', 'bn_name' => 'অন্য উপজেলা']);
        $otherSchool = School::create(['division_id' => $otherDivision->id, 'district_id' => $otherDistrict->id, 'upazila_id' => $otherUpazila->id, 'name' => 'Other School']);
        $otherVersion = ClassVersion::create(['class_id' => $this->class7->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'General']);
        $otherShift = Shift::create(['class_version_id' => $otherVersion->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $otherSchoolSection = Section::create(['shift_id' => $otherShift->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Main']);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create']); // unscoped: national_admin-shaped actor, no school_id

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $otherSchoolSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_section_id');
    }

    public function test_store_rejects_promotion_exceeding_target_section_capacity_without_override(): void
    {
        $this->toSection->update(['capacity' => 1]);
        $existingTargetStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $existingTargetStudent->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
            'class_id' => $this->class7->id, 'section_id' => $this->toSection->id,
            'shift_id' => $this->toSection->shift_id, 'class_version_id' => $this->toSection->shift->class_version_id,
            'roll_number' => '1',
        ]);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_section_id');
    }

    public function test_store_allows_capacity_override_with_the_override_capacity_permission(): void
    {
        $this->toSection->update(['capacity' => 1]);
        $existingTargetStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $existingTargetStudent->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
            'class_id' => $this->class7->id, 'section_id' => $this->toSection->id,
            'shift_id' => $this->toSection->shift_id, 'class_version_id' => $this->toSection->shift->class_version_id,
            // A different roll number than the promoted student below:
            // class7 (the target class) already enforces a unique
            // [class_id, roll_number] index, and this fixture's point is
            // to fill the target section's capacity, not to also exercise
            // that unrelated collision.
            'roll_number' => '99',
        ]);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create', 'sections.override_capacity'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'override_capacity' => true,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertCreated();
    }

    public function test_store_rejects_the_whole_batch_when_a_promoted_students_roll_number_collides_with_an_unrelated_target_class_student(): void
    {
        $unrelatedTargetStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        $unrelated = StudentProfile::create([
            'user_id' => $unrelatedTargetStudent->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
            'class_id' => $this->class7->id, 'section_id' => $this->toSection->id,
            'shift_id' => $this->toSection->shift_id, 'class_version_id' => $this->toSection->shift->class_version_id,
            'roll_number' => '7',
        ]);

        $pass = $this->studentInSection('7', 85);
        $otherPass = $this->studentInSection('8', 90);
        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [
                ['student_profile_id' => $pass->id, 'promote' => true],
                ['student_profile_id' => $otherPass->id, 'promote' => true],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('records');

        $this->assertStringContainsString('7', $response->json('errors.records.0'));

        // Nothing committed — the colliding student's own collision must
        // not partially apply, and the OTHER, non-colliding student in
        // the same batch must not have been promoted either.
        $this->assertDatabaseCount('student_class_promotions', 0);
        $pass->refresh();
        $otherPass->refresh();
        $this->assertSame($this->class6->id, $pass->class_id);
        $this->assertSame($this->section->id, $pass->section_id);
        $this->assertSame($this->class6->id, $otherPass->class_id);
        $this->assertSame($this->section->id, $otherPass->section_id);

        $unrelated->refresh();
        $this->assertSame($this->class7->id, $unrelated->class_id);
    }
}
