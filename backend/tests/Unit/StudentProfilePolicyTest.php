<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\User;
use App\Policies\StudentProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfilePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_guardian_and_transfer_section_use_the_update_ability(): void
    {
        $permission = Permission::create(['name' => 'student_profiles.update', 'label' => 'Update student profiles']);
        $role = Role::create(['name' => 'school_admin_sp_update', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id]);
        $targetUser = StudentUser::factory()->create();
        $profile = StudentProfile::create(['user_id' => $targetUser->id]);

        $policy = new StudentProfilePolicy;

        $this->assertTrue($policy->attachGuardian($user, $profile));
        $this->assertTrue($policy->transferSection($user, $profile));
    }

    public function test_attach_guardian_and_transfer_section_are_denied_without_the_update_permission(): void
    {
        $role = Role::create(['name' => 'no_perm_sp', 'label' => 'No Permission']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $targetUser = StudentUser::factory()->create();
        $profile = StudentProfile::create(['user_id' => $targetUser->id]);

        $policy = new StudentProfilePolicy;

        $this->assertFalse($policy->attachGuardian($user, $profile));
        $this->assertFalse($policy->transferSection($user, $profile));
    }
}
