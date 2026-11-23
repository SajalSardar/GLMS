<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
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
use App\Models\Version;
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

    private ClassVersion $version;

    private Subject $subject;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $this->version = $version;
        $academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->academicYear = $academicYear;
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

    public function test_a_practice_exam_never_appears_in_the_dashboard_rollup(): void
    {
        // A practice exam is provisioned with exactly the student's own
        // class_version_id/academic_year_id, so without an explicit
        // is_practice filter it matches the rollup query forever.
        $this->version->subjects()->attach($this->subject->id);

        $teacherExam = Exam::create([
            'class_version_id' => $this->version->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYear->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Midterm',
            'selection_mode' => 'fixed',
            'marks_per_question' => 1,
            'status' => 'published',
        ]);

        $practiceExam = Exam::create([
            'class_version_id' => $this->version->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYear->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Practice #abc12345',
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 5,
            'marks_per_question' => 1,
            'status' => 'published',
            'is_practice' => true,
        ]);

        $response = $this->actingAs($this->studentUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk();

        $examIds = collect($response->json('data.subjects.0.exams'))->pluck('exam_id')->all();

        $this->assertContains($teacherExam->id, $examIds);
        $this->assertNotContains($practiceExam->id, $examIds);
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
