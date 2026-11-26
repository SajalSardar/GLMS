<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAttendancePermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list every new permission this feature adds is
     * mapped to — school_admin explicitly in RolePermissionSeeder;
     * national_admin via its all-permissions wildcard.
     */
    public function test_teacher_attendance_and_kiosk_device_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['national_admin', 'school_admin'];

        $permissions = [
            'teacher_attendances.view',
            'teacher_attendances.update',
            'kiosk_devices.view',
            'kiosk_devices.create',
            'kiosk_devices.update',
            'kiosk_devices.delete',
        ];

        foreach ($permissions as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $roles, "Expected {$permissionName} to be mapped to exactly national_admin and school_admin.");
        }
    }
}
