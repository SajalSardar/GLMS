<?php

namespace Tests\Concerns;

use App\Models\GuardianUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use App\Models\User;

trait CreatesAuthorizedActors
{
    /**
     * @param  list<string>  $permissionNames
     */
    private function actorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    private function actorWithNoPermissions(?int $schoolId = null, ?int $districtId = null): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function teacherActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): TeacherUser
    {
        $role = Role::create(['name' => 'teacher_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Teacher Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return TeacherUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function studentActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): StudentUser
    {
        $role = Role::create(['name' => 'student_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Student Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function guardianActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): GuardianUser
    {
        $role = Role::create(['name' => 'guardian_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Guardian Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return GuardianUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }
}
