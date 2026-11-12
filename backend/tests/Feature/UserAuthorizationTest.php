<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_user_store_update_and_assign_role_are_authorized(): void
    {
        $target = User::factory()->create();
        $assignableRole = Role::create(['name' => 'assignable_role', 'label' => 'Assignable Role']);
        $payload = ['name' => 'New User', 'email' => 'new-user@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertOk();
    }

    public function test_a_non_split_off_account_still_requires_an_eight_character_password(): void
    {
        $staffRole = Role::create(['name' => 'office_staff_spv', 'label' => 'Office Staff']);
        $actor = $this->actorWithPermissions(['users.create']);

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'New Staff Member',
            'email' => 'new-staff@example.test',
            'password' => '1234',
            'role_id' => $staffRole->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_creating_a_users_row_with_a_split_off_role_is_rejected_even_for_a_fully_permissioned_actor(): void
    {
        $studentRole = Role::create(['name' => 'student', 'label' => 'Student']);
        $actor = $this->actorWithPermissions(['users.create', 'users.update', 'users.delete', 'users.view']);

        $payload = [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'password123',
            'role_id' => $studentRole->id,
        ];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.test']);
    }

    public function test_recreating_a_soft_deleted_user_from_a_different_school_is_rejected(): void
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

        $payload = ['name' => 'New User', 'email' => 'new-user@example.test', 'password' => 'password123'];

        $created = $this->actingAs($actorA, 'sanctum')->postJson('/api/v1/users', $payload)->assertCreated();
        $userId = $created->json('data.id');

        $this->actingAs($actorA, 'sanctum')->deleteJson("/api/v1/users/{$userId}")->assertNoContent();

        $this->actingAs($actorB, 'sanctum')->postJson('/api/v1/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'school_id' => $schoolA->id,
        ]);
        $this->assertSoftDeleted('users', ['id' => $userId]);
    }
}
