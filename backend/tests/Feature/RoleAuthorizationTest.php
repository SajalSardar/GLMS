<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_role_store_update_and_update_permissions_are_authorized(): void
    {
        $role = Role::create(['name' => 'existing_role', 'label' => 'Existing Role']);
        $grantablePermission = Permission::create(['name' => 'classes.view', 'label' => 'View classes']);
        $payload = ['name' => 'new_role', 'label' => 'New Role'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/roles', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();

        $allowed = $this->actorWithPermissions(['roles.create', 'roles.update', 'classes.view']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/roles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => [$grantablePermission->name]])->assertOk();
    }
}
