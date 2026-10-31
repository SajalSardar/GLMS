<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

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
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/roles', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => [$grantablePermission->name]])->assertOk();
    }
}
