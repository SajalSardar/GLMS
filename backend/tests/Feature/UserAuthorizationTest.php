<?php

namespace Tests\Feature;

use App\Models\Role;
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
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertOk();
    }
}
