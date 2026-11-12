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
}
