<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private function roleNamed(string $name): Role
    {
        return Role::create(['name' => $name, 'label' => ucfirst($name)]);
    }

    public function test_teacher_login_succeeds_with_employee_id_and_returns_a_token(): void
    {
        $role = $this->roleNamed('teacher');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
        TeacherProfile::create(['user_id' => $user->id, 'employee_id' => 'EMP-001']);

        $response = $this->postJson('/api/v1/auth/teacher/login', [
            'login' => 'EMP-001',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
    }

    public function test_teacher_login_succeeds_with_email_fallback_when_no_employee_id_matches(): void
    {
        $role = $this->roleNamed('teacher');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'teacher@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/teacher/login', [
            'login' => 'teacher@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
    }

    public function test_teacher_login_fails_with_wrong_password(): void
    {
        $role = $this->roleNamed('teacher');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
        TeacherProfile::create(['user_id' => $user->id, 'employee_id' => 'EMP-002']);

        $response = $this->postJson('/api/v1/auth/teacher/login', [
            'login' => 'EMP-002',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_teacher_login_fails_for_a_role_outside_the_teacher_portal(): void
    {
        $role = $this->roleNamed('student');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
        TeacherProfile::create(['user_id' => $user->id, 'employee_id' => 'EMP-003']);

        $response = $this->postJson('/api/v1/auth/teacher/login', [
            'login' => 'EMP-003',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_student_login_succeeds_with_student_id_and_returns_a_token(): void
    {
        $role = $this->roleNamed('student');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => '1234']);
        StudentProfile::create(['user_id' => $user->id, 'student_id' => 'STU-001']);

        $response = $this->postJson('/api/v1/auth/student/login', [
            'login' => 'STU-001',
            'password' => '1234',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
    }

    public function test_student_login_fails_with_wrong_password(): void
    {
        $role = $this->roleNamed('student');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => '1234']);
        StudentProfile::create(['user_id' => $user->id, 'student_id' => 'STU-002']);

        $response = $this->postJson('/api/v1/auth/student/login', [
            'login' => 'STU-002',
            'password' => '9999',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_student_login_fails_for_a_role_outside_the_student_portal(): void
    {
        $role = $this->roleNamed('teacher');
        $user = User::factory()->create(['role_id' => $role->id, 'password' => '1234']);
        StudentProfile::create(['user_id' => $user->id, 'student_id' => 'STU-003']);

        $response = $this->postJson('/api/v1/auth/student/login', [
            'login' => 'STU-003',
            'password' => '1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_student_login_does_not_fall_back_to_email_or_phone(): void
    {
        $role = $this->roleNamed('student');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'student@example.test', 'password' => '1234']);

        $response = $this->postJson('/api/v1/auth/student/login', [
            'login' => 'student@example.test',
            'password' => '1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_management_login_succeeds_with_email_and_returns_a_token(): void
    {
        $role = $this->roleNamed('school_admin');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'admin@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'admin@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
    }

    public function test_management_login_fails_with_wrong_password(): void
    {
        $role = $this->roleNamed('national_admin');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'natadmin@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'natadmin@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_management_login_fails_for_a_role_outside_the_management_portal(): void
    {
        $role = $this->roleNamed('teacher');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'notadmin@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'notadmin@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_existing_generic_login_still_works_unchanged(): void
    {
        $role = $this->roleNamed('national_admin');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'generic@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'generic@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
    }
}
