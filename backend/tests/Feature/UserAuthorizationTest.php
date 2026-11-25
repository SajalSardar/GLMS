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

    public function test_index_can_be_filtered_by_search_on_name_or_email(): void
    {
        User::factory()->create(['name' => 'Rafiq Islam', 'email' => 'rafiq@example.test']);
        User::factory()->create(['name' => 'Karim Ahmed', 'email' => 'karim@example.test']);

        $actor = $this->actorWithPermissions(['users.view']);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/users?search=Rafiq')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rafiq Islam');

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/users?search=karim@example.test')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Karim Ahmed');
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

    public function test_recreating_a_soft_deleted_user_matched_by_phone_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'First User', 'email' => 'first-user@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $firstPayload)->assertCreated();
        $userId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/users/{$userId}")->assertNoContent();

        $secondPayload = ['name' => 'Second User', 'email' => 'second-user@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $secondPayload)->assertCreated();

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'second-user@example.test',
            'phone' => '01913000000',
            'deleted_at' => null,
        ]);

        $this->assertSame(1, User::withTrashed()->where('phone', '01913000000')->count());
    }

    /**
     * Regression test: Sanctum tokens never expire here, and store()'s
     * email/phone-matched restore paths can repoint a trashed row at a
     * *different* person. A token left behind on delete would then come
     * back to life as the new owner's token, so destroy() must revoke
     * every token the account holds.
     */
    public function test_deleting_a_user_revokes_their_api_tokens(): void
    {
        $actor = $this->actorWithPermissions(['users.delete']);
        $target = User::factory()->create();
        $target->createToken('old-device');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/users/{$target->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_user_on_phone(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'User A', 'email' => 'user-a@example.test', 'phone' => '01913000001', 'password' => 'password123'];
        $createdA = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $firstPayload)->assertCreated();
        $userAId = $createdA->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/users/{$userAId}")->assertNoContent();

        $secondPayload = ['name' => 'User B', 'email' => 'user-b@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $createdB = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $secondPayload)->assertCreated();
        $userBId = $createdB->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/users/{$userBId}")->assertNoContent();

        // Matches user A by email, but the phone collides with user B's phone.
        $conflictingPayload = ['name' => 'New User', 'email' => 'user-a@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', $conflictingPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseHas('users', [
            'id' => $userAId,
            'email' => 'user-a@example.test',
            'phone' => '01913000001',
        ]);
        $this->assertSoftDeleted('users', ['id' => $userAId]);

        $this->assertDatabaseHas('users', [
            'id' => $userBId,
            'email' => 'user-b@example.test',
            'phone' => '01913000002',
        ]);
        $this->assertSoftDeleted('users', ['id' => $userBId]);
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
