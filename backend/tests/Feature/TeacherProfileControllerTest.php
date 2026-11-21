<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherProfileControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_creating_a_profile_reuses_a_soft_deleted_profile_matching_employee_id_for_a_different_teacher(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacherOne = TeacherUser::factory()->create();
        $teacherTwo = TeacherUser::factory()->create();

        $firstProfile = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'employee_id' => 'EMP1',
            'designation' => 'Old Designation',
        ]);
        $firstProfile->delete();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherTwo->id,
                'employee_id' => 'EMP1',
                'designation' => 'New Designation',
            ])
            ->assertCreated();

        // The restore-check found the trashed row via employee_id (not
        // user_id), so the same row is reused and repointed at the new
        // teacher. Every fillable column is rewritten (submitted value, or
        // null when absent), so nothing from teacherOne survives — see
        // test_restoring_a_profile_matched_by_employee_id_does_not_inherit_the_previous_owners_fields().
        $response->assertJsonPath('data.id', $firstProfile->id);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $firstProfile->id,
            'user_id' => $teacherTwo->id,
            'employee_id' => 'EMP1',
            'designation' => 'New Designation',
            'deleted_at' => null,
        ]);
    }

    public function test_creating_a_profile_reuses_a_soft_deleted_profile_matching_nid_number_for_a_different_teacher(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacherOne = TeacherUser::factory()->create();
        $teacherTwo = TeacherUser::factory()->create();

        $firstProfile = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'nid_number' => 'NID1',
            'designation' => 'Old Designation',
        ]);
        $firstProfile->delete();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherTwo->id,
                'nid_number' => 'NID1',
                'designation' => 'New Designation',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.id', $firstProfile->id);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $firstProfile->id,
            'user_id' => $teacherTwo->id,
            'nid_number' => 'NID1',
            'designation' => 'New Designation',
            'deleted_at' => null,
        ]);
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_profile_on_nid_number(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacherOne = TeacherUser::factory()->create();
        $teacherTwo = TeacherUser::factory()->create();
        $teacherThree = TeacherUser::factory()->create();

        $profileA = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'employee_id' => 'EMP-A',
            'nid_number' => 'NID-A',
        ]);
        $profileA->delete();

        $profileB = TeacherProfile::create([
            'user_id' => $teacherTwo->id,
            'employee_id' => 'EMP-B',
            'nid_number' => 'NID-B',
        ]);
        $profileB->delete();

        // Matches profile A by employee_id, but the nid_number collides
        // with profile B's nid_number.
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherThree->id,
                'employee_id' => 'EMP-A',
                'nid_number' => 'NID-B',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nid_number']);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profileA->id,
            'user_id' => $teacherOne->id,
            'employee_id' => 'EMP-A',
            'nid_number' => 'NID-A',
        ]);
        $this->assertSoftDeleted('teacher_profiles', ['id' => $profileA->id]);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profileB->id,
            'user_id' => $teacherTwo->id,
            'employee_id' => 'EMP-B',
            'nid_number' => 'NID-B',
        ]);
        $this->assertSoftDeleted('teacher_profiles', ['id' => $profileB->id]);
    }

    /**
     * Regression test: teacher_profiles.employee_id and nid_number are
     * GLOBAL unique indexes, not per-school ones, so a trashed profile in
     * a *different* school is still a hard DB collision. TeacherProfile is
     * TenantScoped, so without an explicit withoutGlobalScope() bypass the
     * restore-check and its conflict guard simply cannot see that row —
     * the request then restored the in-scope match and crashed on the live
     * unique index with a 500 instead of returning a clean 422.
     */
    public function test_store_rejects_a_trashed_nid_collision_in_another_school_instead_of_crashing(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School B']);

        $actor = $this->actorWithPermissions(['teacher_profiles.create'], $schoolA->id, $district->id);

        $teacherOne = TeacherUser::factory()->create(['school_id' => $schoolA->id, 'district_id' => $district->id]);
        $teacherTwo = TeacherUser::factory()->create(['school_id' => $schoolB->id, 'district_id' => $district->id]);
        $teacherThree = TeacherUser::factory()->create(['school_id' => $schoolA->id, 'district_id' => $district->id]);

        $profileInSchoolA = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'school_id' => $schoolA->id,
            'district_id' => $district->id,
            'employee_id' => 'EMP-A',
        ]);
        $profileInSchoolA->delete();

        $profileInSchoolB = TeacherProfile::create([
            'user_id' => $teacherTwo->id,
            'school_id' => $schoolB->id,
            'district_id' => $district->id,
            'nid_number' => 'NID-X',
        ]);
        $profileInSchoolB->delete();

        // Matches school A's trashed profile by employee_id, but the
        // submitted nid_number is held by school B's trashed profile — a
        // row this school-A actor's scoped queries cannot see at all.
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherThree->id,
                'employee_id' => 'EMP-A',
                'nid_number' => 'NID-X',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nid_number']);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profileInSchoolA->id,
            'user_id' => $teacherOne->id,
            'school_id' => $schoolA->id,
            'employee_id' => 'EMP-A',
            'nid_number' => null,
        ]);
        $this->assertSoftDeleted('teacher_profiles', ['id' => $profileInSchoolA->id]);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profileInSchoolB->id,
            'user_id' => $teacherTwo->id,
            'school_id' => $schoolB->id,
            'nid_number' => 'NID-X',
        ]);
        $this->assertSoftDeleted('teacher_profiles', ['id' => $profileInSchoolB->id]);
    }

    /**
     * Regression test: restore() and update() are two separate writes. If
     * the second fails, an unwrapped pair leaves the row un-deleted — a
     * request that returned 500 to the caller would have silently
     * resurrected a deleted teacher's profile. The write failure is
     * simulated with a model event, since the guard now closes every
     * collision this controller can see before restoring.
     */
    public function test_a_write_failure_after_restore_does_not_leave_the_profile_resurrected(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacherOne = TeacherUser::factory()->create();
        $teacherTwo = TeacherUser::factory()->create();

        $profile = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'employee_id' => 'EMP-TX',
        ]);
        $profile->delete();

        // restore() dirties deleted_at; the update() that follows it does
        // not — so this throws on the second write only.
        TeacherProfile::updating(function (TeacherProfile $model) {
            if (! $model->isDirty('deleted_at')) {
                throw new RuntimeException('simulated write failure after restore');
            }
        });

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherTwo->id,
                'employee_id' => 'EMP-TX',
            ])
            ->assertStatus(500);

        $this->assertSoftDeleted('teacher_profiles', [
            'id' => $profile->id,
            'user_id' => $teacherOne->id,
        ]);
    }

    /**
     * Regression test: a trashed profile matched by employee_id belonged
     * to a *different* teacher, so updating it with only the submitted
     * keys is a partial merge — every absent fillable column keeps the
     * previous owner's value, leaking their nid_number, phone, designation
     * and blood group onto the new teacher's profile.
     */
    public function test_restoring_a_profile_matched_by_employee_id_does_not_inherit_the_previous_owners_fields(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacherOne = TeacherUser::factory()->create();
        $teacherThree = TeacherUser::factory()->create();

        $firstProfile = TeacherProfile::create([
            'user_id' => $teacherOne->id,
            'employee_id' => 'EMP-A',
            'nid_number' => 'NID-OLD',
            'designation' => 'Old Designation',
            'subject_specialization' => 'Old Subject',
            'qualification' => 'Old Qualification',
            'blood_group' => 'A+',
            'phone' => '0199999999',
            'present_address' => 'Old Present Address',
            'permanent_address' => 'Old Permanent Address',
            'photo_path' => 'old/photo.jpg',
        ]);
        $firstProfile->delete();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacherThree->id,
                'employee_id' => 'EMP-A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $firstProfile->id);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $firstProfile->id,
            'user_id' => $teacherThree->id,
            'employee_id' => 'EMP-A',
            'nid_number' => null,
            'designation' => null,
            'subject_specialization' => null,
            'qualification' => null,
            'joining_date' => null,
            'blood_group' => null,
            'phone' => null,
            'present_address' => null,
            'permanent_address' => null,
            'photo_path' => null,
            'deleted_at' => null,
        ]);
    }

    /**
     * The common case must keep working: a profile deleted and recreated
     * for the SAME teacher (matched by user_id, the genuine identity
     * anchor) with a complete payload still restores and takes every
     * submitted value.
     */
    public function test_restoring_a_profile_matched_by_user_id_with_a_full_payload_still_works(): void
    {
        $actor = $this->actorWithPermissions(['teacher_profiles.create']);

        $teacher = TeacherUser::factory()->create();

        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'employee_id' => 'EMP-OLD',
            'designation' => 'Old Designation',
        ]);
        $profile->delete();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/teacher-profiles', [
                'user_id' => $teacher->id,
                'employee_id' => 'EMP-NEW',
                'designation' => 'Senior Teacher',
                'subject_specialization' => 'Mathematics',
                'qualification' => 'MSc',
                'joining_date' => '2024-01-15',
                'nid_number' => 'NID-NEW',
                'phone' => '0171111111',
                'blood_group' => 'B+',
                'present_address' => 'Present Address',
                'permanent_address' => 'Permanent Address',
                'photo_path' => 'photos/teacher.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $profile->id);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profile->id,
            'user_id' => $teacher->id,
            'employee_id' => 'EMP-NEW',
            'designation' => 'Senior Teacher',
            'subject_specialization' => 'Mathematics',
            'qualification' => 'MSc',
            'nid_number' => 'NID-NEW',
            'phone' => '0171111111',
            'blood_group' => 'B+',
            'present_address' => 'Present Address',
            'permanent_address' => 'Permanent Address',
            'photo_path' => 'photos/teacher.jpg',
            'deleted_at' => null,
        ]);

        $this->assertSame('2024-01-15', TeacherProfile::findOrFail($profile->id)->joining_date->toDateString());
    }
}
