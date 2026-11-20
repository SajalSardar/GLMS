<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Holiday;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Shift $shift;

    private ClassVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $this->shift = Shift::create(['class_version_id' => $this->version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);
    }

    private function studentInSection(?string $rollNumber = '1'): StudentUser
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->version->class_id,
            'section_id' => $this->section->id,
            'shift_id' => $this->shift->id,
            'class_version_id' => $this->version->id,
            'roll_number' => $rollNumber,
        ]);

        return $student;
    }

    public function test_the_class_teacher_can_record_attendance_for_their_section(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => '2026-11-22',
            'records' => [
                ['student_profile_id' => $profile->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('data.students.0.status', 'present');

        $this->assertDatabaseHas('attendances', [
            'student_profile_id' => $profile->id,
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->version->class_id,
            'shift_id' => $this->shift->id,
            'class_version_id' => $this->version->id,
            'status' => 'present',
            'marked_by_id' => $classTeacher->id,
        ]);
        $this->assertSame('2026-11-22', Attendance::first()->date->toDateString());
    }

    public function test_recording_again_for_the_same_date_updates_the_existing_row_instead_of_duplicating(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $payload = fn (string $status) => [
            'section_id' => $this->section->id,
            'date' => '2026-11-22',
            'records' => [['student_profile_id' => $profile->id, 'status' => $status]],
        ];

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', $payload('absent'))->assertOk();
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', $payload('present'))->assertOk()
            ->assertJsonPath('data.students.0.status', 'present');

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', ['student_profile_id' => $profile->id, 'status' => 'present']);
        $this->assertSame('2026-11-22', Attendance::first()->date->toDateString());
    }

    public function test_a_teacher_who_is_not_the_class_teacher_cannot_record_attendance(): void
    {
        $subject = Subject::create(['name' => 'Math']);
        $otherTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $otherTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($otherTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => '2026-11-22',
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    /**
     * A teacher only assigned via SubjectTeacherAssignment (not the class
     * teacher) still gets view access to the roster/report — per the
     * explicit "other teachers assigned to that section will only have
     * view-only access" requirement.
     */
    public function test_a_subject_teacher_can_view_but_not_record(): void
    {
        $subject = Subject::create(['name' => 'Math']);
        $otherTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $otherTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->studentInSection();

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson("/api/v1/attendances/roster?section_id={$this->section->id}&date=2026-11-22")
            ->assertOk()
            ->assertJsonPath('data.can_record', false);
    }

    public function test_a_teacher_with_no_relation_to_the_section_cannot_view_its_roster(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/attendances/roster?section_id={$this->section->id}&date=2026-11-22")
            ->assertForbidden();
    }

    public function test_recording_is_rejected_on_a_holiday_date(): void
    {
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'sunday', 'name' => 'Weekend']);

        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // 2026-11-22 is a Sunday.
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => '2026-11-22',
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_report_shows_the_holiday_name_for_a_holiday_date_with_no_recorded_attendance(): void
    {
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'sunday', 'name' => 'Weekend']);

        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);
        $this->studentInSection();

        $response = $this->actingAs($classTeacher, 'sanctum')
            ->getJson("/api/v1/attendances/report?section_id={$this->section->id}&year=2026&month=11")
            ->assertOk();

        $sunday22 = collect($response->json('data.dates'))->firstWhere('date', '2026-11-22');
        $this->assertSame('Weekend', $sunday22['holiday_name']);
    }

    public function test_student_can_view_their_own_monthly_attendance(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => '2026-11-23',
            'records' => [['student_profile_id' => $profile->id, 'status' => 'absent']],
        ])->assertOk();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/attendances/mine?year=2026&month=11')
            ->assertOk()
            ->assertJsonPath('data.summary.absent', 1);

        $day23 = collect($response->json('data.days'))->firstWhere('date', '2026-11-23');
        $this->assertSame('absent', $day23['status']);
    }

    public function test_a_school_admin_can_view_reports_for_any_section_in_their_school_without_an_assignment(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);
        $this->studentInSection();

        $schoolAdmin = $this->actorWithPermissions(['attendances.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/attendances/report?section_id={$this->section->id}&year=2026&month=11")
            ->assertOk();
    }

    public function test_my_sections_flags_the_class_teacher_section_as_recordable_and_the_assignment_only_section_as_not(): void
    {
        $teacher = $this->teacherActorWithPermissions(['attendances.view', 'attendances.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $teacher->id]);

        $otherSection = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section B']);
        $subject = Subject::create(['name' => 'Math']);
        SubjectTeacherAssignment::create([
            'section_id' => $otherSection->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/attendances/my-sections')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('id', $this->section->id)['can_record']);
        $this->assertFalse($rows->firstWhere('id', $otherSection->id)['can_record']);
    }
}
