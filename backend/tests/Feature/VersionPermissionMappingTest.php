<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_view_is_mapped_to_every_role_holding_class_versions_view(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $rolesWithClassVersionsView = Role::whereHas('permissions', fn ($query) => $query->where('name', 'class_versions.view'))
            ->pluck('name')
            ->sort()
            ->values();

        $rolesWithVersionsView = Role::whereHas('permissions', fn ($query) => $query->where('name', 'versions.view'))
            ->pluck('name')
            ->sort()
            ->values();

        $this->assertEquals($rolesWithClassVersionsView, $rolesWithVersionsView);
    }

    public function test_versions_update_is_national_admin_only(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $roles = Role::whereHas('permissions', fn ($query) => $query->where('name', 'versions.update'))
            ->pluck('name')
            ->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
