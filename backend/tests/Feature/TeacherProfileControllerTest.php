<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        // teacher. Every submitted field overwrites the old one, so
        // nothing stale from teacherOne survives — the reuse is safe.
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
}
