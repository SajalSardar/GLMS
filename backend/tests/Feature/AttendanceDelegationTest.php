<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\TeacherLeaveApplication;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AttendanceDelegationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);
    }

    private function studentInSection(): StudentProfile
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        return StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
            'roll_number' => '1',
        ]);
    }

    public function test_an_approved_delegate_can_record_attendance_during_the_leave_window(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [
                ['student_profile_id' => $profile->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('data.students.0.status', 'present');
    }

    public function test_a_named_delegate_cannot_record_before_the_application_is_approved(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'pending',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_delegate_cannot_record_outside_the_leave_date_range(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_teacher_not_named_as_delegate_cannot_record(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $uninvolvedTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($uninvolvedTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_my_sections_lists_the_delegated_section_with_can_record_true(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $response = $this->actingAs($substitute, 'sanctum')
            ->getJson('/api/v1/attendances/my-sections')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->section->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['can_record']);
    }

    /**
     * Non-regression: an ordinary class teacher with no delegation
     * involved records exactly as before.
     */
    public function test_the_class_teacher_can_still_record_their_own_section_normally(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $profile = $this->studentInSection();

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertOk();
    }
}
