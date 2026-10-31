# Portal Login Endpoints Implementation Plan (Plan C of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three portal-specific login endpoints (teacher/student/management), each resolving its own identifier type and enforcing its own role allowlist via a small fixed role-to-portal config; correct the two stale architecture docs (bearer-token auth, actually-implemented global-scope tenant isolation) and the design spec's now-disproven "systemic finding" framing.

**Architecture:** Three new `AuthController` methods (`teacherLogin`, `studentLogin`, `managementLogin`) each resolve the target `User` by that portal's identifier rule, then share one private helper (`resolvePortalLogin()`) that does rate-limiting, password verification, and role-allowlist checking — collapsing to the exact same generic "credentials incorrect" failure on any of the three failure modes, so a login attempt never reveals which part was wrong. The role allowlist per portal comes from a new `config/portals.php` file, not a database table. The existing generic `AuthController::login()` is untouched. Two Store/Update `User` FormRequests gain a role-conditional password-length relaxation so a `student` account's PIN-style password can actually be created through the existing API — without this, the student login endpoint this plan builds would have no real account to log into.

**Tech Stack:** Laravel (PHP 8.5), PHPUnit, SQLite in-memory for tests (existing project convention).

**Spec:** `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`

## Global Constraints

- Three endpoints: `POST /api/v1/auth/teacher/login`, `POST /api/v1/auth/student/login`, `POST /api/v1/auth/management/login` — each body `{ login, password, device_name? }`, reusing the existing `LoginRequest` FormRequest (its validation rules are already exactly `login`/`password`/`device_name`, identical to what all three new endpoints need — no new FormRequest classes).
- Identifier resolution per portal: **Teacher** — `teacher_profiles.employee_id` (joined to its `user_id`), or `users.phone`, or `users.email`. **Student** — `student_profiles.student_id` (joined to its `user_id`) **only** — no phone/email fallback. **Management** — `users.phone` or `users.email` (same resolution as the existing generic `/auth/login`).
- Role allowlist per portal (from `config/portals.php`, not a database table): Teacher Portal → `['teacher']`. Student Portal → `['student']`. Management Portal → `['national_admin', 'district_officer', 'school_admin']`. `trainer`, `office_staff`, `it_admin`, `auditor`, `parent_guardian` are not assigned to any portal — a login attempt by one of them against any of the three new endpoints fails with the same generic message as a wrong password.
- On any failure (identifier not found, wrong password, right credentials but wrong-portal role), every endpoint returns the **same generic** `ValidationException` with message key `login` and text `"The provided credentials are incorrect."` — identical wording to the existing `AuthController::login()` — never revealing which part was wrong.
- Rate limiting mirrors the existing `AuthController::login()` exactly: throttle key `mb_strtolower($login).'|'.$request->ip()`, `RateLimiter::tooManyAttempts($throttleKey, 5)`, `RateLimiter::hit()` on failure, `RateLimiter::clear()` on success.
- On success: issue a Sanctum bearer token via `$user->createToken($deviceName)` (device name defaults to `'api'` when omitted, matching the existing endpoint), respond with `(new UserResource($user->load(['role.permissions', 'school', 'district'])))->additional(['token' => $token->plainTextToken])->response()` — byte-for-byte the same response shape as the existing `/auth/login`.
- The existing `AuthController::login()`/`::logout()`/`::me()` methods and the existing `/api/v1/auth/login` route are **not modified** in this plan — it has no portal/role restriction and stays exactly as-is (future cleanup, out of scope here).
- No new `portals` database table, no new "Teacher ID"/"Student ID" columns (they are the existing `teacher_profiles.employee_id`/`student_profiles.student_id` columns), no frontend login UI, no MFA — all out of scope per the spec.
- After every PHP file change, run `vendor/bin/pint --dirty --format agent` before considering a task done (project convention from `backend/CLAUDE.md`).
- No model factories exist in this project besides `UserFactory` — every other model's test fixtures are built with `Model::create([...])` directly, and shared test-actor helpers come from the existing `Tests\Concerns\CreatesAuthorizedActors` trait (added in Plan B) where applicable.

---

### Task 1: Role-to-portal config

**Files:**
- Create: `backend/config/portals.php`
- Test: `backend/tests/Unit/PortalsConfigTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `config('portals.teacher')`, `config('portals.student')`, `config('portals.management')`, each a `list<string>` of allowed role names — consumed by Task 3's three login methods as their role-allowlist source.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/PortalsConfigTest.php`

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

class PortalsConfigTest extends TestCase
{
    public function test_teacher_portal_allows_only_the_teacher_role(): void
    {
        $this->assertSame(['teacher'], config('portals.teacher'));
    }

    public function test_student_portal_allows_only_the_student_role(): void
    {
        $this->assertSame(['student'], config('portals.student'));
    }

    public function test_management_portal_allows_the_three_administrative_roles(): void
    {
        $this->assertSame(['national_admin', 'district_officer', 'school_admin'], config('portals.management'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/PortalsConfigTest.php`
Expected: FAIL — `config('portals.teacher')` returns `null` since `config/portals.php` doesn't exist yet.

- [ ] **Step 3: Create `backend/config/portals.php`**

```php
<?php

/**
 * Maps each of the three portal-specific login endpoints
 * (POST /api/v1/auth/{portal}/login) to the role names allowed to
 * authenticate through it. A role not listed here for any portal
 * (trainer, office_staff, it_admin, auditor, parent_guardian) has no
 * portal login — see docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
 * "Portal-to-role mapping".
 */
return [
    'teacher' => ['teacher'],
    'student' => ['student'],
    'management' => ['national_admin', 'district_officer', 'school_admin'],
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/PortalsConfigTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add config/portals.php tests/Unit/PortalsConfigTest.php
git commit -m "Add role-to-portal config for the three login endpoints"
```

---

### Task 2: Relax password validation for student accounts

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreUserRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/UpdateUserRequest.php`
- Test: `backend/tests/Feature/StudentPasswordValidationTest.php`

**Interfaces:**
- Consumes: nothing new
- Produces: nothing consumed by a later task in this plan — but without this, no `student`-role account can be created through the existing `POST /api/v1/users` endpoint with a short numeric PIN (the current blanket `min:8` rule blocks it), which would leave Task 3's student login endpoint with no real account to exercise outside of a test fixture built by bypassing validation entirely.

**Design decision (the spec says "relaxed validation... numeric-only PIN allowed, shorter minimum length" without giving an exact number):** minimum length drops to **4** characters for a `student`-role account, otherwise stays at the existing `8`. The `string` validation rule already accepts a purely-numeric string like `"1234"` (Laravel's `string` rule checks PHP type, not character class) — so no separate "numeric-only" rule is needed; a student's PIN already satisfies `string` today. Only the minimum-length threshold is role-conditional.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/StudentPasswordValidationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithUsersCreateAndUpdate(): User
    {
        $role = Role::create(['name' => 'school_admin_spv', 'label' => 'School Admin']);
        $createPermission = Permission::create(['name' => 'users.create', 'label' => 'Create users']);
        $updatePermission = Permission::create(['name' => 'users.update', 'label' => 'Update users']);
        $role->permissions()->attach([$createPermission->id, $updatePermission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_student_account_can_be_created_with_a_four_digit_pin(): void
    {
        $studentRole = Role::create(['name' => 'student', 'label' => 'Student']);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Test Student',
            'email' => 'test-student@example.test',
            'password' => '1234',
            'role_id' => $studentRole->id,
        ]);

        $response->assertCreated();
    }

    public function test_a_non_student_account_still_requires_an_eight_character_password(): void
    {
        $teacherRole = Role::create(['name' => 'teacher_spv', 'label' => 'Teacher']);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Test Teacher',
            'email' => 'test-teacher@example.test',
            'password' => '1234',
            'role_id' => $teacherRole->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_students_pin_can_be_updated_to_another_short_pin(): void
    {
        $studentRole = Role::create(['name' => 'student', 'label' => 'Student']);
        $student = User::factory()->create(['role_id' => $studentRole->id]);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->patchJson("/api/v1/users/{$student->id}", [
            'password' => '5678',
        ]);

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/StudentPasswordValidationTest.php`
Expected: FAIL on `test_a_student_account_can_be_created_with_a_four_digit_pin` and `test_a_students_pin_can_be_updated_to_another_short_pin` (both currently 422 against the blanket `min:8` rule); `test_a_non_student_account_still_requires_an_eight_character_password` already passes.

- [ ] **Step 3: Relax `backend/app/Http/Requests/Api/V1/StoreUserRequest.php`**

Change:
```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->withoutTrashed()],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
```
to:
```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', $this->isStudentRole() ? 'min:4' : 'min:8'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->withoutTrashed()],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }

    /**
     * A student account's password is a short PIN, not a full password —
     * see docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
     * "Login endpoints" for why.
     */
    private function isStudentRole(): bool
    {
        $roleId = $this->integer('role_id') ?: null;

        return $roleId !== null && Role::find($roleId)?->name === 'student';
    }
}
```

- [ ] **Step 4: Relax `backend/app/Http/Requests/Api/V1/UpdateUserRequest.php`**

Change:
```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Role/school/district changes go through `PATCH /users/{user}/role`
     * instead, where the privilege-escalation and scope checks live.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }
}
```
to:
```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Role/school/district changes go through `PATCH /users/{user}/role`
     * instead, where the privilege-escalation and scope checks live.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'required', 'string', $this->isStudentRole() ? 'min:4' : 'min:8'],
        ];
    }

    /**
     * A student account's password is a short PIN, not a full password.
     * Unlike store(), role isn't part of this request's payload (role
     * changes go through assignRole instead), so this checks the
     * route-bound user's CURRENT role.
     */
    private function isStudentRole(): bool
    {
        return $this->route('user')?->role?->name === 'student';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/StudentPasswordValidationTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full test suite**

Run: `cd backend && php artisan test`
Expected: PASS — no existing test created a user with a 4-7 character password under a non-student role (which would newly fail), and no existing test relies on `min:8` being unconditional for a student-role user.

- [ ] **Step 7: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/StoreUserRequest.php app/Http/Requests/Api/V1/UpdateUserRequest.php tests/Feature/StudentPasswordValidationTest.php
git commit -m "Relax password length to 4 chars for student-role accounts"
```

---

### Task 3: Three portal login endpoints

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PortalLoginTest.php`

**Interfaces:**
- Consumes: `config('portals.teacher'|'student'|'management')` (Task 1).
- Produces: `POST /api/v1/auth/teacher/login`, `POST /api/v1/auth/student/login`, `POST /api/v1/auth/management/login` — consumed by Task 5 (final regression) and, eventually, each portal frontend's login page (out of this plan's scope).

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/PortalLoginTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/PortalLoginTest.php`
Expected: FAIL on every `/auth/teacher/login`, `/auth/student/login`, `/auth/management/login` request with a 404 (routes don't exist yet); `test_existing_generic_login_still_works_unchanged` already passes.

- [ ] **Step 3: Add the three routes** — in `backend/routes/api.php`, change:
```php
    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });
```
to:
```php
    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/teacher/login', [AuthController::class, 'teacherLogin']);
        Route::post('/student/login', [AuthController::class, 'studentLogin']);
        Route::post('/management/login', [AuthController::class, 'managementLogin']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });
```

- [ ] **Step 4: Add the three methods and the shared helper** — in `backend/app/Http/Controllers/Api/V1/AuthController.php`, change:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate the user and issue a new personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();
        $throttleKey = mb_strtolower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'login' => ['Too many login attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.'],
            ]);
        }

        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($request->string('device_name')->toString() ?: 'api');

        return (new UserResource($user->load(['role.permissions', 'school', 'district'])))
            ->additional(['token' => $token->plainTextToken])
            ->response();
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role.permissions', 'school', 'district']));
    }
}
```
to:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate the user and issue a new personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();
        $throttleKey = mb_strtolower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'login' => ['Too many login attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.'],
            ]);
        }

        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($request->string('device_name')->toString() ?: 'api');

        return (new UserResource($user->load(['role.permissions', 'school', 'district'])))
            ->additional(['token' => $token->plainTextToken])
            ->response();
    }

    /**
     * Teacher Portal login: resolves by employee_id, phone, or email;
     * only a `teacher`-role account may authenticate here.
     */
    public function teacherLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = TeacherProfile::where('employee_id', $login)->first()?->user
            ?? User::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.teacher');
    }

    /**
     * Student Portal login: resolves by student_id ONLY (no phone/email
     * fallback — a student's account may not even have those set); only
     * a `student`-role account may authenticate here.
     */
    public function studentLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = StudentProfile::where('student_id', $login)->first()?->user;

        return $this->issuePortalToken($request, $user, 'portals.student');
    }

    /**
     * Management Portal login: resolves by phone or email, same as the
     * generic login; only `national_admin`/`district_officer`/
     * `school_admin` accounts may authenticate here.
     */
    public function managementLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.management');
    }

    /**
     * Shared portal-login tail: rate limiting, password check, and
     * role-allowlist check collapse to the exact same generic failure
     * message regardless of which part was wrong, so a login attempt
     * never reveals which check failed.
     */
    private function issuePortalToken(LoginRequest $request, ?User $user, string $allowedRolesConfigKey): JsonResponse
    {
        $login = $request->string('login')->toString();
        $throttleKey = mb_strtolower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'login' => ['Too many login attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.'],
            ]);
        }

        $allowedRoles = config($allowedRolesConfigKey);
        $credentialsValid = $user !== null
            && Hash::check($request->string('password')->toString(), $user->password)
            && in_array($user->role?->name, $allowedRoles, true);

        if (! $credentialsValid) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($request->string('device_name')->toString() ?: 'api');

        return (new UserResource($user->load(['role.permissions', 'school', 'district'])))
            ->additional(['token' => $token->plainTextToken])
            ->response();
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['role.permissions', 'school', 'district']));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/PortalLoginTest.php`
Expected: PASS (12 tests).

- [ ] **Step 6: Run the full test suite**

Run: `cd backend && php artisan test`
Expected: PASS — no existing test hits `/api/v1/auth/*`, so nothing else can regress.

- [ ] **Step 7: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/AuthController.php routes/api.php tests/Feature/PortalLoginTest.php
git commit -m "Add teacher/student/management portal login endpoints"
```

---

### Task 4: Documentation corrections

**Files:**
- Modify: `docs/API_SPECIFICATION.md`
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`

**Interfaces:**
- Consumes: Task 3's three endpoints (to describe them accurately); the Plan B final-review finding that the design spec's original "systemic finding" framing was factually wrong (FormRequest-level `authorize()` already enforced every check this plan's prior phase added at the controller level — there was never an exploitable gap).
- Produces: nothing consumed by a later task — this is the plan's closing documentation task.

- [ ] **Step 1: Update `docs/API_SPECIFICATION.md`'s Auth convention row** — change:
```markdown
- **Auth (Phase 2+):** Laravel Sanctum cookie-based SPA session — `GET /sanctum/csrf-cookie` → `POST /api/v1/auth/login` → session-authenticated requests. Bearer tokens are not used for the first-party SPA.
```
to:
```markdown
- **Auth:** Laravel Sanctum bearer tokens, not cookie-based SPA sessions — `POST /api/v1/auth/login` (generic), or one of the three portal-specific endpoints (`POST /api/v1/auth/{teacher,student,management}/login`), each returning `{ "data": {...user...}, "token": "<plain-text-token>" }`. Every subsequent request carries `Authorization: Bearer <token>`. Chosen over cookie sessions because the three portals are three separate-subdomain SPAs with no shared cookie domain to configure.
```

- [ ] **Step 2: Update `docs/API_SPECIFICATION.md`'s endpoint table** — add these three rows to the "Endpoints That Exist Today" table (after the existing `/api/v1/health` row, or wherever the table's auth endpoints are listed once Plan C ships — if the table has no auth rows yet, add a new row group):
```markdown
| `POST` | `/api/v1/auth/teacher/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by `teacher_profiles.employee_id`, phone, or email; `teacher` role only) |
| `POST` | `/api/v1/auth/student/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by `student_profiles.student_id` only; `student` role only) |
| `POST` | `/api/v1/auth/management/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by phone or email; `national_admin`/`district_officer`/`school_admin` roles only) |
```

- [ ] **Step 3: Update `docs/ARCHITECTURE.md`'s Backend Architecture table** — change:
```markdown
| Auth (installed, not yet wired) | Laravel Sanctum | Cookie-based SPA session auth, per 🆕 recommendation in the source proposal; full login/MFA endpoints are Phase 2 work |
```
to:
```markdown
| Auth | Laravel Sanctum, bearer tokens | Four login endpoints: generic `/auth/login` plus three portal-specific ones (`/auth/{teacher,student,management}/login`), each enforcing its own role allowlist via `config/portals.php`. Bearer tokens, not cookie sessions — the three portals are separate-subdomain SPAs with no shared cookie domain. MFA remains Phase 2 work |
```
and change:
```markdown
| Planned multi-tenancy | Shared MySQL DB, `school_id`-scoped rows + mandatory Eloquent global scopes on every tenant-owned model | **Not implemented in Phase 1** — there are no tenant-owned tables yet. This is the design to apply starting with the first real domain model (e.g., `schools`, `students`) |
```
to:
```markdown
| Multi-tenancy | Shared MySQL DB, `school_id`-scoped rows + a mandatory Eloquent global scope (`App\Models\Scopes\TenantScope`, via the `TenantScoped` trait) on every tenant-owned model | **Implemented.** Applied to all 13 tenant-owned models (`School`, `User`, `TeacherProfile`, `StudentProfile`, `SchoolClass`, `Classroom`, `ClassRoutine`, `ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment`, `TimetableEntry`, `AcademicYear`); a foreign-tenant row 404s via implicit route-model-binding before any controller or Policy code runs |
```

- [ ] **Step 4: Update `docs/ARCHITECTURE.md`'s Authentication Design section** — change:
```markdown
## 4. Authentication Design (target — Phase 2, not built in Phase 1)

Laravel Sanctum, cookie-based SPA session auth: the SPA calls `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login`; subsequent requests are session-authenticated via an HttpOnly cookie. This was chosen over Passport/OAuth2 because no third-party developer API access is in scope — see `inspiration/Research_Notes_and_Sources.md` §6 for the full comparison.

`packages/auth` (added in the frontend monorepo restructuring) currently implements bearer-token storage via `localStorage`, not the cookie-based session described above. This mismatch, and the broader token-storage/XSS tradeoff, must be resolved by the multi-portal auth sub-project (see `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`) before any real login flow is wired up — not left as an implicit default.
```
to:
```markdown
## 4. Authentication Design (implemented)

Laravel Sanctum, bearer-token auth — not cookie-based SPA sessions. Each of the three portal frontends (`teacher`/`student`/`management`) calls its own login endpoint (`POST /api/v1/auth/{portal}/login`), receives a plain-text personal access token, and sends it as `Authorization: Bearer <token>` on every subsequent request. This was chosen over Passport/OAuth2 because no third-party developer API access is in scope, and over cookie sessions because the three portals are separate-subdomain SPAs with no shared cookie domain to configure (see `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md` "Architecture mismatches found and resolved" §1 for the full decision record).

`packages/auth`'s existing `localStorage` bearer-token storage (added in the frontend monorepo restructuring) is therefore the actual target, not a mismatch to resolve — each portal's own login-page sub-project stores the token this way.

A generic, portal-agnostic `POST /api/v1/auth/login` also exists (any role, no allowlist) and is retained for tooling/admin use; it predates the three portal-specific endpoints and is not part of any portal frontend's login flow.
```

- [ ] **Step 5: Append a correction note to the design spec** — in `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`, after the "Systemic finding: missing `authorize()` calls across all 20 controllers" section's closing paragraph (the one ending "...Both halves (global scope AND the missing `authorize()` calls) are needed together."), add:
```markdown
**Correction (found during Plan B's implementation, 2026-10-31):** the claim above — "any authenticated user, regardless of role or permission, can create or update any tenant-owned resource for any school" — is factually incorrect. Every `Store*`/`Update*`/custom-action `FormRequest` in `backend/app/Http/Requests/Api/V1/` already overrides `authorize()` with the exact same Policy check Plan B added at the controller level (verified across all 45 relevant FormRequest classes; only `LoginRequest` legitimately returns `true` unconditionally, since login is public). Laravel authorizes a type-hinted FormRequest before the controller method body ever runs, so this was already enforced, system-wide, before Plan B started. Combined with the pre-existing controller-level `authorize()` calls on `destroy()`/`setCurrent()`/`publish()`/`unpublish()`/`detachGuardian()`, there was never an exploitable gap. Plan B's controller-level `authorize()` calls were still implemented as intentional defense-in-depth (consistency with the app's own `destroy()` convention; protection if a future action ever swaps a typed FormRequest for a plain `Request`, as several custom actions already do) — not as a vulnerability fix. Treat this spec's original framing above as superseded by this note, not as an accurate description of a bug that shipped.
```

- [ ] **Step 6: Commit**

```bash
git add docs/API_SPECIFICATION.md docs/ARCHITECTURE.md docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
git commit -m "Correct auth/tenant-isolation docs to match what's implemented; correct the design spec's disproven systemic-finding framing"
```

---

### Task 5: Full-suite regression run

**Files:**
- None (verification-only task; no code changes).

**Interfaces:**
- Consumes: every task above.
- Produces: confirmation that all three portal login endpoints work end-to-end, the student-password relaxation didn't regress anything, and the documentation now matches reality, before this plan is merged.

- [ ] **Step 1: Run the full backend test suite**

Run: `cd backend && php artisan test`
Expected: PASS — every test from Plan A, Plan B, and every test added in Tasks 1-3 of this plan passes.

- [ ] **Step 2: Manually sanity-check the three new routes exist**

Run: `cd backend && php artisan route:list --path=auth`
Expected: 6 routes — the pre-existing `login`/`logout`/`me`, plus the new `teacher/login`, `student/login`, `management/login`.

- [ ] **Step 3: Report**

Summarize: three portal login endpoints implemented and tested (resolution rules, role allowlists, generic failure message, rate limiting, token issuance); student-account PIN creation unblocked; `docs/API_SPECIFICATION.md`/`docs/ARCHITECTURE.md` now describe bearer-token auth and the actually-implemented global-scope tenant isolation; the design spec's disproven "systemic finding" framing is corrected in place. Full suite green. No commit needed for this task (verification only).
