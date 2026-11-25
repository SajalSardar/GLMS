<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherLeaveApplicationPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list teacher_leave_applications.view/.update
     * are mapped to — school_admin explicitly in RolePermissionSeeder;
     * national_admin via its all-permissions wildcard. Granting either to
     * any other role (including teacher — submission/mine/colleagues are
     * all self-service bypass, not permission-gated) would be a real
     * authorization regression.
     */
    public function test_teacher_leave_applications_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['national_admin', 'school_admin'];

        foreach (['teacher_leave_applications.view', 'teacher_leave_applications.update'] as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $roles, "Expected {$permissionName} to be mapped to exactly national_admin and school_admin.");
        }
    }
}
