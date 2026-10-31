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
