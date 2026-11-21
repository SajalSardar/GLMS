<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\GuardianUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class GuardianControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_creating_a_guardian_reuses_a_soft_deleted_guardian_matching_nid_number_with_a_different_phone(): void
    {
        $actor = $this->actorWithPermissions(['guardians.create']);

        $firstGuardian = Guardian::create([
            'name' => 'First Guardian',
            'phone' => '0100000001',
            'nid_number' => 'NID-SHARED',
        ]);
        $firstGuardian->delete();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/guardians', [
                'name' => 'Second Guardian',
                'phone' => '0100000002',
                'nid_number' => 'NID-SHARED',
            ])
            ->assertCreated();

        // The restore-check found the trashed row via nid_number (not
        // phone), so the same row is reused. Every fillable column is
        // rewritten (submitted value, or null when absent), so nothing
        // from the first guardian survives — see
        // test_restoring_a_guardian_matched_by_nid_number_does_not_inherit_the_previous_owners_account().
        $response->assertJsonPath('data.id', $firstGuardian->id);

        $this->assertDatabaseHas('guardians', [
            'id' => $firstGuardian->id,
            'name' => 'Second Guardian',
            'phone' => '0100000002',
            'nid_number' => 'NID-SHARED',
            'deleted_at' => null,
        ]);
    }

    public function test_creating_a_guardian_reuses_a_soft_deleted_guardian_matching_user_id_with_a_different_phone(): void
    {
        $actor = $this->actorWithPermissions(['guardians.create']);
        $guardianUser = GuardianUser::factory()->create();

        $firstGuardian = Guardian::create([
            'user_id' => $guardianUser->id,
            'name' => 'First Guardian',
            'phone' => '0100000003',
        ]);
        $firstGuardian->delete();

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/guardians', [
                'user_id' => $guardianUser->id,
                'name' => 'Second Guardian',
                'phone' => '0100000004',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.id', $firstGuardian->id);

        $this->assertDatabaseHas('guardians', [
            'id' => $firstGuardian->id,
            'user_id' => $guardianUser->id,
            'name' => 'Second Guardian',
            'phone' => '0100000004',
            'deleted_at' => null,
        ]);
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_guardian_on_user_id(): void
    {
        $actor = $this->actorWithPermissions(['guardians.create']);
        $guardianUser = GuardianUser::factory()->create();

        $guardianA = Guardian::create([
            'name' => 'Guardian A',
            'phone' => '0100000005',
            'nid_number' => 'NID-A',
        ]);
        $guardianA->delete();

        $guardianB = Guardian::create([
            'user_id' => $guardianUser->id,
            'name' => 'Guardian B',
            'phone' => '0100000006',
            'nid_number' => 'NID-B',
        ]);
        $guardianB->delete();

        // Matches guardian A by nid_number, but the user_id collides with
        // guardian B's user_id.
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/guardians', [
                'user_id' => $guardianUser->id,
                'name' => 'New Guardian',
                'phone' => '0100000007',
                'nid_number' => 'NID-A',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseHas('guardians', [
            'id' => $guardianA->id,
            'name' => 'Guardian A',
            'phone' => '0100000005',
            'nid_number' => 'NID-A',
            'user_id' => null,
        ]);
        $this->assertSoftDeleted('guardians', ['id' => $guardianA->id]);

        $this->assertDatabaseHas('guardians', [
            'id' => $guardianB->id,
            'user_id' => $guardianUser->id,
            'name' => 'Guardian B',
            'phone' => '0100000006',
            'nid_number' => 'NID-B',
        ]);
        $this->assertSoftDeleted('guardians', ['id' => $guardianB->id]);
    }

    /**
     * Regression test: a trashed guardian matched by nid_number is a
     * *different* person, so updating it with only the submitted keys is a
     * partial merge. The worst casualty is user_id — a brand-new guardian
     * submitted with no user_id at all came back still linked to the
     * deleted guardian's login account, i.e. one person's account
     * answering to another person's record — plus the old email,
     * occupation and bn_name riding along.
     */
    public function test_restoring_a_guardian_matched_by_nid_number_does_not_inherit_the_previous_owners_account(): void
    {
        $actor = $this->actorWithPermissions(['guardians.create']);
        $guardianUser = GuardianUser::factory()->create();

        $guardianA = Guardian::create([
            'user_id' => $guardianUser->id,
            'name' => 'Guardian A',
            'bn_name' => 'অভিভাবক',
            'phone' => '0100000011',
            'email' => 'old-guardian@example.test',
            'nid_number' => 'NID-A',
            'occupation' => 'Farmer',
            'present_address' => 'Old Present Address',
        ]);
        $guardianA->delete();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/guardians', [
                'name' => 'Brand New Guardian',
                'phone' => '0100000099',
                'nid_number' => 'NID-A',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $guardianA->id);

        $this->assertDatabaseHas('guardians', [
            'id' => $guardianA->id,
            'name' => 'Brand New Guardian',
            'phone' => '0100000099',
            'nid_number' => 'NID-A',
            'user_id' => null,
            'bn_name' => null,
            'email' => null,
            'occupation' => null,
            'present_address' => null,
            'deleted_at' => null,
        ]);
    }

    /**
     * The common case must keep working: a guardian deleted and recreated
     * for the SAME login account (matched by user_id, the genuine identity
     * anchor) with a complete payload still restores and takes every
     * submitted value.
     */
    public function test_restoring_a_guardian_matched_by_user_id_with_a_full_payload_still_works(): void
    {
        $actor = $this->actorWithPermissions(['guardians.create']);
        $guardianUser = GuardianUser::factory()->create();

        $guardian = Guardian::create([
            'user_id' => $guardianUser->id,
            'name' => 'Old Name',
            'phone' => '0100000021',
            'occupation' => 'Old Occupation',
        ]);
        $guardian->delete();

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/guardians', [
                'user_id' => $guardianUser->id,
                'name' => 'New Name',
                'bn_name' => 'নতুন নাম',
                'phone' => '0100000022',
                'email' => 'new-guardian@example.test',
                'nid_number' => 'NID-NEW',
                'occupation' => 'Teacher',
                'present_address' => 'Present Address',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $guardian->id);

        $this->assertDatabaseHas('guardians', [
            'id' => $guardian->id,
            'user_id' => $guardianUser->id,
            'name' => 'New Name',
            'bn_name' => 'নতুন নাম',
            'phone' => '0100000022',
            'email' => 'new-guardian@example.test',
            'nid_number' => 'NID-NEW',
            'occupation' => 'Teacher',
            'present_address' => 'Present Address',
            'deleted_at' => null,
        ]);
    }
}
