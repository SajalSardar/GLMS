<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentProfileAuthorizationTest extends TestCase
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

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['name' => 'Class']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
        ]);
    }

    public function test_store_update_attach_guardian_and_transfer_section_are_authorized(): void
    {
        $existingTargetUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = StudentProfile::create([
            'user_id' => $existingTargetUser->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
        ]);

        $newTargetUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['name' => 'Guardian', 'phone' => '01700000001']);

        $storePayload = ['user_id' => $newTargetUser->id];
        $attachPayload = ['guardian_id' => $guardian->id, 'relation' => 'father'];
        $transferPayload = ['to_section_id' => $this->section->id];

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/transfer-section", $transferPayload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['student_profiles.create', 'student_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertOk();
    }

    /**
     * Regression test: StudentProfileController::store() must resolve
     * user_id against student_users, not the staff users table. Both
     * tables are freshly empty at the start of this test, so the first
     * row created in each gets id 1 — a deliberate id collision between
     * a staff User (in a different, out-of-scope school) and the
     * StudentUser actually being targeted. If the controller ever looks
     * the id up in `users` again, it will silently resolve the staff
     * User instead, fail the in-scope check against the wrong school,
     * and this test will catch it as a non-201 response.
     */
    public function test_student_profile_store_resolves_user_id_against_student_users_not_staff_users(): void
    {
        $otherDivision = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ বি']);
        $otherDistrict = District::create(['division_id' => $otherDivision->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $otherUpazila = Upazila::create(['district_id' => $otherDistrict->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);
        $otherSchool = School::create([
            'division_id' => $otherDivision->id,
            'district_id' => $otherDistrict->id,
            'upazila_id' => $otherUpazila->id,
            'name' => 'School B',
        ]);

        // First row in `users` — id 1 in this fresh test transaction.
        $collidingStaffUser = User::factory()->create(['school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id]);
        // First row in `student_users` — also id 1. This is the real target.
        $studentUser = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $this->assertSame($collidingStaffUser->id, $studentUser->id);

        $allowed = $this->actorWithPermissions(['student_profiles.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/student-profiles', ['user_id' => $studentUser->id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $studentUser->id)
            ->assertJsonPath('data.school_id', $studentUser->school_id)
            ->assertJsonPath('data.district_id', $studentUser->district_id);
    }

    public function test_index_can_be_filtered_by_user_id(): void
    {
        $studentA = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $studentB = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $profileA = StudentProfile::create(['user_id' => $studentA->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $studentB->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $actor = $this->actorWithPermissions(['student_profiles.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/student-profiles?user_id={$studentA->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $profileA->id);
    }

    /**
     * Regression test: the frontend's student detail page fetches a
     * student's profile via this index endpoint (filtered by user_id),
     * not via show() — so an attached guardian has to survive that same
     * endpoint's eager-loading, not just show()'s.
     */
    public function test_index_includes_attached_guardians(): void
    {
        $student = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = StudentProfile::create(['user_id' => $student->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['name' => 'Abdul Karim', 'phone' => '01700000002']);

        $profile->guardians()->attach($guardian->id, ['relation' => 'father', 'is_primary' => true]);

        $actor = $this->actorWithPermissions(['student_profiles.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/student-profiles?user_id={$student->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data.0.guardians');
        $response->assertJsonPath('data.0.guardians.0.id', $guardian->id);
        $response->assertJsonPath('data.0.guardians.0.relation', 'father');
        $response->assertJsonPath('data.0.guardians.0.is_primary', true);
    }
}
