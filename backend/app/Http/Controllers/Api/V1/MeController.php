<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Requests\Api\V1\UpdateMyPasswordRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Self-service "my account" endpoints — the single route all four
 * login-table models (User, TeacherUser, StudentUser, GuardianUser)
 * share, since every one of them already resolves correctly through
 * $request->user() regardless of which table backed the token. This is
 * deliberately NOT the same path as the admin-CRUD controllers
 * (UserController, TeacherUserController, ...): those are Policy-gated
 * and can target anyone in scope; this one only ever targets the caller's
 * own record, so no permission check applies beyond being authenticated.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->serialize($request->user())]);
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->only(array_intersect(['name', 'bn_name', 'email', 'phone'], $user->getFillable()));

        $user->update($data);

        return response()->json(['data' => $this->serialize($user->fresh())]);
    }

    public function updatePassword(UpdateMyPasswordRequest $request): Response
    {
        $request->user()->update(['password' => $request->validated('password')]);

        return response()->noContent();
    }

    /**
     * @return array{name: string, bn_name: ?string, email: ?string, phone: ?string, role: ?string, role_label: ?string}
     */
    private function serialize(Authenticatable $user): array
    {
        return [
            'name' => $user->name,
            'bn_name' => $user->bn_name ?? null,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->name,
            'role_label' => $user->role?->label,
        ];
    }
}
