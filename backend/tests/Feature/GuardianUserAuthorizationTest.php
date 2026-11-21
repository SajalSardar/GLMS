<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\GuardianUser;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class GuardianUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_guardian_user_store_and_update_are_authorized(): void
    {
        $target = GuardianUser::factory()->create();
        $payload = ['name' => 'New Guardian', 'email' => 'new-guardian@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertOk();
    }

    public function test_school_scoped_actor_cannot_create_guardian_in_different_school(): void
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

        $payload = [
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $schoolB->id,
        ];

        $this->actingAs($actorScopedToA, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertUnprocessable();
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
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $schoolInDistrictB->id,
        ];

        $this->actingAs($actorScopedToDistrictA, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertUnprocessable();
    }

    public function test_guardian_user_store_resolves_district_from_school_for_unscoped_actor(): void
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
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $school->id,
        ];

        $response = $this->actingAs($unscopedActor, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();

        $response->assertJsonPath('data.district.id', $district->id);
        $this->assertDatabaseHas('guardian_users', [
            'email' => 'new-guardian@example.test',
            'school_id' => $school->id,
            'district_id' => $district->id,
        ]);
    }

    public function test_recreating_a_soft_deleted_guardian_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $payload = ['name' => 'New Guardian', 'email' => 'new-guardian@example.test', 'password' => 'password123'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();
        $guardianId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianId}")->assertNoContent();

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianId,
            'email' => 'new-guardian@example.test',
            'deleted_at' => null,
        ]);
    }

    public function test_recreating_a_soft_deleted_guardian_matched_by_phone_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'First Guardian', 'email' => 'first-guardian@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $firstPayload)->assertCreated();
        $guardianId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianId}")->assertNoContent();

        $secondPayload = ['name' => 'Second Guardian', 'email' => 'second-guardian@example.test', 'phone' => '01913000000', 'password' => 'password123'];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $secondPayload)->assertCreated();

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianId,
            'email' => 'second-guardian@example.test',
            'phone' => '01913000000',
            'deleted_at' => null,
        ]);

        $this->assertSame(1, GuardianUser::withTrashed()->where('phone', '01913000000')->count());
    }

    /**
     * Regression test: Sanctum tokens never expire here, and store()'s
     * email/phone-matched restore paths can repoint a trashed row at a
     * *different* person. A token left behind on delete would then come
     * back to life as the new owner's token, so destroy() must revoke
     * every token the account holds.
     */
    public function test_deleting_a_guardian_revokes_their_api_tokens(): void
    {
        $actor = $this->actorWithPermissions(['users.delete']);
        $target = GuardianUser::factory()->create();
        $target->createToken('old-device');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$target->id}")->assertNoContent();

        $this->assertSoftDeleted('guardian_users', ['id' => $target->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => GuardianUser::class,
            'tokenable_id' => $target->id,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_guardian_on_phone(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'Guardian A', 'email' => 'guardian-a@example.test', 'phone' => '01913000001', 'password' => 'password123'];
        $createdA = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $firstPayload)->assertCreated();
        $guardianAId = $createdA->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianAId}")->assertNoContent();

        $secondPayload = ['name' => 'Guardian B', 'email' => 'guardian-b@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $createdB = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $secondPayload)->assertCreated();
        $guardianBId = $createdB->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianBId}")->assertNoContent();

        // Matches guardian A by email, but the phone collides with guardian B's phone.
        $conflictingPayload = ['name' => 'New Guardian', 'email' => 'guardian-a@example.test', 'phone' => '01913000002', 'password' => 'password123'];
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $conflictingPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianAId,
            'email' => 'guardian-a@example.test',
            'phone' => '01913000001',
        ]);
        $this->assertSoftDeleted('guardian_users', ['id' => $guardianAId]);

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianBId,
            'email' => 'guardian-b@example.test',
            'phone' => '01913000002',
        ]);
        $this->assertSoftDeleted('guardian_users', ['id' => $guardianBId]);
    }

    /**
     * Regression test: a trashed guardian matched by phone is a *different*
     * person, so updating it with only the submitted keys is a partial
     * merge. The new submission omits bn_name entirely, so a buggy partial
     * merge would leave the old guardian's bn_name in place — a different
     * person's name riding along on a brand-new identity. (email can't be
     * used as the leaking field here: it's a required column on this
     * endpoint, so it's always present in the submission.)
     */
    public function test_restoring_a_guardian_matched_by_phone_does_not_inherit_the_previous_owners_data(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = [
            'name' => 'Old Guardian',
            'bn_name' => 'পুরনো অভিভাবক',
            'email' => 'old-guardian@example.test',
            'phone' => '0171000001',
            'password' => 'password123',
        ];
        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $firstPayload)->assertCreated();
        $guardianId = $created->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianId}")->assertNoContent();

        $secondPayload = [
            'name' => 'Brand New Guardian',
            'email' => 'brand-new-guardian@example.test',
            'phone' => '0171000001',
            'password' => 'secret123',
        ];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/guardian-users', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.id', $guardianId);

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianId,
            'name' => 'Brand New Guardian',
            'email' => 'brand-new-guardian@example.test',
            'phone' => '0171000001',
            'bn_name' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_recreating_a_soft_deleted_guardian_from_a_different_school_is_rejected(): void
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

        $payload = ['name' => 'New Guardian', 'email' => 'new-guardian@example.test', 'password' => 'password123'];

        $created = $this->actingAs($actorA, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();
        $guardianId = $created->json('data.id');

        $this->actingAs($actorA, 'sanctum')->deleteJson("/api/v1/guardian-users/{$guardianId}")->assertNoContent();

        $this->actingAs($actorB, 'sanctum')->postJson('/api/v1/guardian-users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('guardian_users', [
            'id' => $guardianId,
            'school_id' => $schoolA->id,
        ]);
        $this->assertSoftDeleted('guardian_users', ['id' => $guardianId]);
    }
}
