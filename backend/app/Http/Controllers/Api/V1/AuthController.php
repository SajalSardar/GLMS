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
