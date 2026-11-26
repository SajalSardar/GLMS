<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePrintPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list student_profiles.print/teacher_profiles.print
     * are mapped to — school_admin explicitly in RolePermissionSeeder;
     * national_admin via its all-permissions wildcard. Every other role
     * (including teacher, which already holds the broader .view permission)
     * must never see the ID-card print action.
     */
    public function test_profile_print_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['national_admin', 'school_admin'];

        foreach (['student_profiles.print', 'teacher_profiles.print'] as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $roles, "Expected {$permissionName} to be mapped to exactly national_admin and school_admin.");
        }
    }
}
