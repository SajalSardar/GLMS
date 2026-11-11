<?php

namespace Tests\Unit;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTeacherGuardianUserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'teacher', 'label' => 'Teacher']);
        $teacher = TeacherUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $teacher->role->id);
        $this->assertFalse($teacher->hasPermission('questions.create'));
    }

    public function test_student_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'student', 'label' => 'Student']);
        $student = StudentUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $student->role->id);
    }

    public function test_guardian_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'parent_guardian', 'label' => 'Parent/Guardian']);
        $guardian = GuardianUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $guardian->role->id);
    }
}
