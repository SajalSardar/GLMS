<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class VersionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_and_show_are_public(): void
    {
        $this->getJson('/api/v1/versions')->assertOk()->assertJsonCount(2, 'data');

        $bangla = Version::where('name', 'Bangla')->first();
        $this->getJson("/api/v1/versions/{$bangla->id}")->assertOk()->assertJsonPath('data.name', 'Bangla');
    }

    public function test_store_and_destroy_routes_do_not_exist(): void
    {
        // No store/destroy route is registered for this URI at all; since
        // the same URI is already matched by index (GET /versions) and by
        // show/update (GET|PATCH /versions/{version}), Laravel's router
        // reports a method mismatch (405) rather than a missing route
        // (404) for the unregistered verbs — see Illuminate's own
        // assertMethodNotAllowed() helper, which exists for exactly this.
        $this->postJson('/api/v1/versions', ['name' => 'Urdu'])->assertMethodNotAllowed();

        $bangla = Version::where('name', 'Bangla')->first();
        $this->deleteJson("/api/v1/versions/{$bangla->id}")->assertMethodNotAllowed();
    }

    public function test_update_is_authorized(): void
    {
        $bangla = Version::where('name', 'Bangla')->first();

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['bn_name' => 'বাংলা সংস্করণ'])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['versions.update']);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['bn_name' => 'বাংলা সংস্করণ'])
            ->assertOk()
            ->assertJsonPath('data.bn_name', 'বাংলা সংস্করণ')
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_update_rejects_a_duplicate_name(): void
    {
        $bangla = Version::where('name', 'Bangla')->first();

        $allowed = $this->actorWithPermissions(['versions.update']);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['name' => 'English'])
            ->assertStatus(422);
    }
}
