<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\GuardianUser;
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

class StudentDashboardControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private StudentProfile $profile;

    private StudentUser $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $shift = Shift::create(['class_version_id' => $version->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $this->studentUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $this->profile = StudentProfile::create(['user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $section->id]);
    }

    public function test_the_student_can_view_their_own_dashboard(): void
    {
        $this->actingAs($this->studentUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.student_profile_id', $this->profile->id);
    }

    public function test_a_linked_guardian_can_view_the_dashboard(): void
    {
        $guardianUser = GuardianUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['user_id' => $guardianUser->id, 'name' => 'Guardian', 'phone' => '01700000001']);
        $guardian->students()->attach($this->profile->id, ['relation' => 'father', 'is_primary' => true]);

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk();
    }

    public function test_an_unrelated_student_is_forbidden(): void
    {
        $otherUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_management_role_with_student_progress_view_can_view_any_dashboard(): void
    {
        $manager = $this->actorWithPermissions(['student_progress.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk();
    }

    public function test_viewdashboard_is_independent_of_the_broader_view_ability(): void
    {
        // Holds student_profiles.view (the broader, pre-existing ability)
        // but NOT student_progress.view — must still be forbidden from
        // the dashboard, proving viewDashboard() doesn't fall back to
        // view()'s flatter gate.
        $broadViewer = $this->actorWithPermissions(['student_profiles.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($broadViewer, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_student_with_no_section_gets_an_empty_dashboard_not_an_error(): void
    {
        $unassignedUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $unassignedProfile = StudentProfile::create(['user_id' => $unassignedUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($unassignedUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$unassignedProfile->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.class_version_id', null)
            ->assertJsonPath('data.subjects', []);
    }
}
