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
        // phone), so the same row is reused. $data is the full submitted
        // payload, so every field is overwritten and nothing stale from
        // the first guardian survives.
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
}
