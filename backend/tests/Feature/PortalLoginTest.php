<?php

namespace Tests\Feature;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
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
        $user = TeacherUser::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
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
        $user = TeacherUser::factory()->create(['role_id' => $role->id, 'email' => 'teacher@example.test', 'password' => 'correct-password']);

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
        $user = TeacherUser::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
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
        $user = TeacherUser::factory()->create(['role_id' => $role->id, 'password' => 'correct-password']);
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
        $user = StudentUser::factory()->create(['role_id' => $role->id, 'password' => '1234']);
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
        $user = StudentUser::factory()->create(['role_id' => $role->id, 'password' => '1234']);
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
        $user = StudentUser::factory()->create(['role_id' => $role->id, 'password' => '1234']);
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
        $user = StudentUser::factory()->create(['role_id' => $role->id, 'email' => 'student@example.test', 'password' => '1234']);

        $response = $this->postJson('/api/v1/auth/student/login', [
            'login' => 'student@example.test',
            'password' => '1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_guardian_login_succeeds_with_email_and_returns_a_token(): void
    {
        $role = $this->roleNamed('parent_guardian');
        $user = GuardianUser::factory()->create(['role_id' => $role->id, 'email' => 'guardian@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/guardian/login', [
            'login' => 'guardian@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
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

    /**
     * The generic endpoint's defining difference from the four portal
     * ones: it has no role allowlist, on purpose. `trainer` is mapped to
     * no portal in config/portals.php, yet holds a real permission grant
     * (RolePermissionSeeder) and a seeded login-ready account
     * (UserSeeder) — this endpoint is its only way in. Asserted
     * explicitly so that narrowing /auth/login to a portal allowlist (a
     * tempting-looking consistency fix) fails here instead of silently
     * locking out trainer/office_staff/it_admin/auditor. See
     * AuthController::login()'s docblock.
     */
    public function test_generic_login_accepts_a_role_mapped_to_no_portal(): void
    {
        $this->assertNotContains('trainer', array_merge(...array_values(config('portals'))));

        $role = $this->roleNamed('trainer');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'trainer@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'trainer@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertIsString($response->json('token'));
    }

    /**
     * The flip side: a portal-less role is still rejected by every portal
     * endpoint, with the same generic message — the allowlist asymmetry
     * is confined to /auth/login and is not a general weakening.
     */
    public function test_portal_logins_reject_a_role_mapped_to_no_portal(): void
    {
        $role = $this->roleNamed('auditor');
        User::factory()->create(['role_id' => $role->id, 'email' => 'auditor@example.test', 'password' => 'correct-password']);

        $response = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'auditor@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login' => 'The provided credentials are incorrect.']);
    }

    public function test_logout_revokes_the_token_used_to_authenticate_the_request(): void
    {
        $role = $this->roleNamed('national_admin');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'logout@example.test', 'password' => 'correct-password']);

        $login = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'logout@example.test',
            'password' => 'correct-password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Sanctum's RequestGuard caches its resolved user for the
        // container's lifetime (see AuthManager::guard()), so a second
        // in-process request through the same guard would otherwise reuse
        // the first call's cached auth rather than re-checking the
        // now-deleted token — forgetGuards() forces a fresh resolution,
        // same fix used elsewhere in this suite for this exact gotcha.
        $this->app['auth']->forgetGuards();

        // The same token, reused against the same auth:sanctum-gated route,
        // now fails — proving the token row was actually deleted rather
        // than just dropped client-side.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    public function test_login_locks_out_after_five_failed_attempts(): void
    {
        $role = $this->roleNamed('national_admin');
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'lockout@example.test', 'password' => 'correct-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/management/login', [
                'login' => 'lockout@example.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/v1/auth/management/login', [
            'login' => 'lockout@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['login']);
        $this->assertStringContainsString('Too many login attempts', $response->json('errors.login.0'));
    }
}
