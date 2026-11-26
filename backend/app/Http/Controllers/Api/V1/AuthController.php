<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\GuardianUser;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
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
     *
     * Deliberately has no `config/portals.php` role allowlist, unlike the
     * four portal-specific logins below — it is the portal-agnostic
     * endpoint, retained for tooling/admin use and consumed by no portal
     * frontend (`docs/ARCHITECTURE.md` "a generic, portal-agnostic
     * POST /api/v1/auth/login also exists (any role, no allowlist)"; the
     * design spec lists leaving it unrestricted twice, once as an
     * explicit decision and once as out-of-scope). Three things make the
     * asymmetry safe rather than a hole, and they are worth stating here
     * because the shape invites exactly the opposite reading:
     *
     * 1. The boundary the four portal logins enforce — that a student
     *    cannot authenticate at the teacher portal — cannot be crossed
     *    here at all. This method resolves against `User`, whose table
     *    holds only management/staff accounts; teacher, student and
     *    guardian credentials live in `teacher_users`/`student_users`/
     *    `guardian_users` and are unreachable from here.
     * 2. The only role reachable here that no portal accepts is
     *    `office_staff` (trainer/it_admin/auditor moved into
     *    config('portals.management') once the Management Portal grew
     *    nav items for them). It has a real permission grant
     *    (RolePermissionSeeder) and a seeded login-ready account
     *    (UserSeeder); this endpoint is its only way in, so adding the
     *    allowlist would lock it out rather than close anything.
     * 3. A token issued here carries only its own role's permissions, and
     *    every endpoint is Policy-gated on those — authenticating is not
     *    itself an authorization.
     *
     * Retiring this endpoint once all portal frontends are confirmed to
     * use their own is the documented future cleanup; narrowing it is
     * not.
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

        if (! $user) {
            $this->dummyHashCheck();
        }

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
            ?? TeacherUser::where('email', $login)->orWhere('phone', $login)->first();

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
     * Guardian Portal login: resolves by phone or email, same as the
     * generic login; only a `parent_guardian`-role account may
     * authenticate here. No frontend consumes this yet — added for
     * backend symmetry with teacher/student login.
     */
    public function guardianLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = GuardianUser::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.guardian');
    }

    /**
     * Shared portal-login tail: rate limiting, password check, and
     * role-allowlist check collapse to the exact same generic failure
     * message regardless of which part was wrong, so a login attempt
     * never reveals which check failed.
     */
    private function issuePortalToken(LoginRequest $request, ?Authenticatable $user, string $allowedRolesConfigKey): JsonResponse
    {
        $login = $request->string('login')->toString();
        $throttleKey = mb_strtolower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'login' => ['Too many login attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.'],
            ]);
        }

        $allowedRoles = config($allowedRolesConfigKey);

        if ($user === null) {
            $this->dummyHashCheck();
        }

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
     * Dummy-cost placeholder for the not-found path so an unknown login
     * value takes roughly as long to reject as a wrong password for a
     * real account — otherwise the real Hash::check() call's bcrypt cost
     * only shows up when a matching user exists, letting a login/ID's
     * existence leak through response-time differences.
     */
    private function dummyHashCheck(): void
    {
        Hash::check('not-a-real-password', '$2y$12$xnHkXMbYRLNiIdEbf3VRdu3dj.KGkloOPgYou.CszpwQ.5c10a.IO');
    }
}
