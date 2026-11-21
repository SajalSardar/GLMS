<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_teacher_user_store_and_update_are_authorized(): void
    {
        $target = TeacherUser::factory()->create();
        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertOk();
    }

    public function test_bn_name_round_trips_through_store_and_update(): void
    {
        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);

        $payload = [
            'name' => 'New Teacher',
            'bn_name' => 'নতুন শিক্ষক',
            'email' => 'new-teacher@example.test',
            'password' => 'password123',
        ];

        $created = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.bn_name', 'নতুন শিক্ষক');

        $teacherId = $created->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/teacher-users/{$teacherId}", ['bn_name' => 'হালনাগাদকৃত নাম'])
            ->assertOk()
            ->assertJsonPath('data.bn_name', 'হালনাগাদকৃত নাম');
    }

    public function test_school_scoped_actor_cannot_create_teacher_in_different_school(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $actorScopedToA = $this->actorWithPermissions(['users.create'], $schoolA->id, $district->id);

        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123', 'school_id' => $schoolB->id];

        $this->actingAs($actorScopedToA, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertUnprocessable();
    }

    public function test_district_scoped_actor_cannot_bypass_district_with_out_of_district_school(): void
    {
        $division1 = Division::create(['name' => 'Division 1', 'bn_name' => 'বিভাগ ১']);
        $district1 = District::create(['division_id' => $division1->id, 'name' => 'District A', 'bn_name' => 'জেলা ক']);
        $upazila1 = Upazila::create(['district_id' => $district1->id, 'name' => 'Upazila 1', 'bn_name' => 'উপজেলা ১']);

        $division2 = Division::create(['name' => 'Division 2', 'bn_name' => 'বিভাগ ২']);
        $district2 = District::create(['division_id' => $division2->id, 'name' => 'District B', 'bn_name' => 'জেলা খ']);
        $upazila2 = Upazila::create(['district_id' => $district2->id, 'name' => 'Upazila 2', 'bn_name' => 'উপজেলা ২']);

        $schoolInDistrictB = School::create([
            'division_id' => $division2->id,
            'district_id' => $district2->id,
            'upazila_id' => $upazila2->id,
            'name' => 'School in District B',
        ]);

        $actorScopedToDistrictA = $this->actorWithPermissions(['users.create'], null, $district1->id);

        $payload = [
            'name' => 'New Teacher',
            'email' => 'new-teacher@example.test',
            'password' => 'password123',
            'school_id' => $schoolInDistrictB->id,
        ];

        $this->actingAs($actorScopedToDistrictA, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertUnprocessable();
    }

    public function test_teacher_user_store_resolves_district_from_school_for_unscoped_actor(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $unscopedActor = $this->actorWithPermissions(['users.create']);

        $payload = [
            'name' => 'New Teacher',
            'email' => 'new-teacher@example.test',
            'password' => 'password123',
            'school_id' => $school->id,
        ];

        $response = $this->actingAs($unscopedActor, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();

        $response->assertJsonPath('data.district.id', $district->id);
        $this->assertDatabaseHas('teacher_users', [
            'email' => 'new-teacher@example.test',
            'school_id' => $school->id,
            'district_id' => $district->id,
        ]);
    }

    public function test_recreating_a_soft_deleted_teacher_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();
        $teacherId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherId}")->assertNoContent();

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherId,
            'email' => 'new-teacher@example.test',
            'deleted_at' => null,
        ]);
    }

    public function test_recreating_a_soft_deleted_teacher_matched_by_phone_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'First Teacher', 'email' => 'first-teacher@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $firstPayload)->assertCreated();
        $teacherId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherId}")->assertNoContent();

        $secondPayload = ['name' => 'Second Teacher', 'email' => 'second-teacher@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $secondPayload)->assertCreated();

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherId,
            'email' => 'second-teacher@example.test',
            'phone' => '01913000000',
            'deleted_at' => null,
        ]);

        $this->assertSame(1, TeacherUser::withTrashed()->where('phone', '01913000000')->count());
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_teacher_on_phone(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'Teacher A', 'email' => 'teacher-a@example.test', 'phone' => '01913000001', 'password' => 'password123'];
        $createdA = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $firstPayload)->assertCreated();
        $teacherAId = $createdA->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherAId}")->assertNoContent();

        $secondPayload = ['name' => 'Teacher B', 'email' => 'teacher-b@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $createdB = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $secondPayload)->assertCreated();
        $teacherBId = $createdB->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherBId}")->assertNoContent();

        // Matches teacher A by email, but the phone collides with teacher B's phone.
        $conflictingPayload = ['name' => 'New Teacher', 'email' => 'teacher-a@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $conflictingPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherAId,
            'email' => 'teacher-a@example.test',
            'phone' => '01913000001',
        ]);
        $this->assertSoftDeleted('teacher_users', ['id' => $teacherAId]);

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherBId,
            'email' => 'teacher-b@example.test',
            'phone' => '01913000002',
        ]);
        $this->assertSoftDeleted('teacher_users', ['id' => $teacherBId]);
    }

    /**
     * Regression test: a trashed teacher matched by phone is a *different*
     * person, so updating it with only the submitted keys is a partial
     * merge. The new submission omits bn_name entirely, so a buggy partial
     * merge would leave the old teacher's bn_name in place — a different
     * person's name riding along on a brand-new identity. (email can't be
     * used as the leaking field here: it's a required column on this
     * endpoint, so it's always present in the submission.)
     */
    public function test_restoring_a_teacher_matched_by_phone_does_not_inherit_the_previous_owners_data(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = [
            'name' => 'Old Teacher',
            'bn_name' => 'পুরনো শিক্ষক',
            'email' => 'old-teacher@example.test',
            'phone' => '0171000001',
            'password' => 'password123',
        ];
        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $firstPayload)->assertCreated();
        $teacherId = $created->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherId}")->assertNoContent();

        $secondPayload = [
            'name' => 'Brand New Teacher',
            'email' => 'brand-new-teacher@example.test',
            'phone' => '0171000001',
            'password' => 'secret123',
        ];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/teacher-users', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.id', $teacherId);

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherId,
            'name' => 'Brand New Teacher',
            'email' => 'brand-new-teacher@example.test',
            'phone' => '0171000001',
            'bn_name' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_recreating_a_soft_deleted_teacher_from_a_different_school_is_rejected(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $actorA = $this->actorWithPermissions(['users.create', 'users.delete'], $schoolA->id, $district->id);
        $actorB = $this->actorWithPermissions(['users.create'], $schoolB->id, $district->id);

        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123'];

        $created = $this->actingAs($actorA, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();
        $teacherId = $created->json('data.id');

        $this->actingAs($actorA, 'sanctum')->deleteJson("/api/v1/teacher-users/{$teacherId}")->assertNoContent();

        $this->actingAs($actorB, 'sanctum')->postJson('/api/v1/teacher-users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('teacher_users', [
            'id' => $teacherId,
            'school_id' => $schoolA->id,
        ]);
        $this->assertSoftDeleted('teacher_users', ['id' => $teacherId]);
    }

    public function test_index_can_be_filtered_by_school_id(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $teacherInA = TeacherUser::factory()->create(['school_id' => $schoolA->id, 'district_id' => $district->id]);
        TeacherUser::factory()->create(['school_id' => $schoolB->id, 'district_id' => $district->id]);

        $unscopedActor = $this->actorWithPermissions(['users.view']);

        $response = $this->actingAs($unscopedActor, 'sanctum')
            ->getJson("/api/v1/teacher-users?school_id={$schoolA->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $teacherInA->id);
    }

    public function test_index_can_be_filtered_by_district_id(): void
    {
        $divisionA = Division::create(['name' => 'Division A', 'bn_name' => 'বিভাগ ক']);
        $districtA = District::create(['division_id' => $divisionA->id, 'name' => 'District A', 'bn_name' => 'জেলা ক']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা ক']);
        $schoolA = School::create([
            'division_id' => $divisionA->id,
            'district_id' => $districtA->id,
            'upazila_id' => $upazilaA->id,
            'name' => 'School A',
        ]);

        $divisionB = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ খ']);
        $districtB = District::create(['division_id' => $divisionB->id, 'name' => 'District B', 'bn_name' => 'জেলা খ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা খ']);
        $schoolB = School::create([
            'division_id' => $divisionB->id,
            'district_id' => $districtB->id,
            'upazila_id' => $upazilaB->id,
            'name' => 'School B',
        ]);

        $teacherInA = TeacherUser::factory()->create(['school_id' => $schoolA->id, 'district_id' => $districtA->id]);
        TeacherUser::factory()->create(['school_id' => $schoolB->id, 'district_id' => $districtB->id]);

        $unscopedActor = $this->actorWithPermissions(['users.view']);

        $response = $this->actingAs($unscopedActor, 'sanctum')
            ->getJson("/api/v1/teacher-users?district_id={$districtA->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $teacherInA->id);
    }

    public function test_index_can_be_filtered_by_search_on_name_or_email(): void
    {
        TeacherUser::factory()->create(['name' => 'Rafiq Islam', 'email' => 'rafiq@example.test']);
        TeacherUser::factory()->create(['name' => 'Karim Ahmed', 'email' => 'karim@example.test']);

        $unscopedActor = $this->actorWithPermissions(['users.view']);

        $this->actingAs($unscopedActor, 'sanctum')
            ->getJson('/api/v1/teacher-users?search=Rafiq')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rafiq Islam');

        $this->actingAs($unscopedActor, 'sanctum')
            ->getJson('/api/v1/teacher-users?search=karim@example.test')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Karim Ahmed');
    }
}
