<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersRolesPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list users.delete is mapped to — school_admin
     * explicitly in RolePermissionSeeder (for full CRUD on the Users &
     * Roles management screen); national_admin via its all-permissions
     * wildcard. Granting it to any other role here would let it delete
     * staff accounts outside its own authority, a real authorization
     * regression, not just redundancy.
     */
    public function test_users_delete_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'users.delete'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin', 'school_admin'], $roles);
    }

    /**
     * roles.view and permissions.view are deliberately view-only for
     * school_admin (plus auditor, already seeded that way) — neither
     * roles.create/update/delete nor roles.updatePermissions is granted,
     * so a school_admin can see what a role can do but never edit a
     * role's permission set, which would otherwise let it grant any role
     * (including its own) more permissions than it started with.
     */
    public function test_roles_view_and_permissions_view_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['auditor', 'national_admin', 'school_admin'];

        $rolesViewRoles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'roles.view'))
            ->pluck('name')->sort()->values()->all();
        $permissionsViewRoles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'permissions.view'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame($expected, $rolesViewRoles);
        $this->assertSame($expected, $permissionsViewRoles);
    }

    /**
     * school_admin must not hold any role-authoring permission — only
     * national_admin may create/update/delete a role or change its
     * permission set.
     */
    public function test_role_authoring_permissions_are_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['roles.create', 'roles.update', 'roles.delete'] as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame(['national_admin'], $roles, "Expected {$permissionName} to be mapped to exactly national_admin.");
        }
    }
}
