<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignRoleRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->visibleTo(request()->user())
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return UserResource::collection($users);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A caller can only hand out a role whose permissions are a subset of
     * their own (no privilege escalation). When school/district are
     * omitted, the new user is placed in the caller's own scope; a
     * scoped caller cannot place a user outside that scope.
     *
     * A soft-deleted user with the same email is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();

        $schoolId = $request->filled('school_id') ? $request->integer('school_id') : $actor->school_id;
        $districtId = $this->resolveDistrictId($request, $schoolId, $actor->district_id);

        $this->assertAssignable($actor, $request->integer('role_id') ?: null, $schoolId, $districtId);

        $attributes = [
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $request->integer('role_id') ?: null,
            'school_id' => $schoolId,
            'district_id' => $districtId,
        ];

        $user = User::onlyTrashed()->where('email', $attributes['email'])->first();

        if ($user) {
            $user->restore();
            $user->update($attributes);
        } else {
            $user = User::create($attributes);
        }

        return (new UserResource($user->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     *
     * Role/school/district changes go through `assignRole` instead, where
     * the privilege-escalation and scope checks live.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new UserResource($user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        if ($user->is(request()->user())) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        $user->delete();

        return response()->noContent();
    }

    /**
     * Assign a role (and, optionally, a school/district scope) to the user.
     *
     * A caller can only hand out a role whose permissions are a subset of
     * their own (no privilege escalation), and can only place the target
     * user inside the caller's own school/district scope.
     */
    public function assignRole(AssignRoleRequest $request, User $user): UserResource
    {
        $actor = $request->user();

        $schoolId = $request->filled('school_id') ? $request->integer('school_id') : $user->school_id;
        $districtId = $this->resolveDistrictId($request, $schoolId, $user->district_id);

        $this->assertAssignable($actor, $request->integer('role_id'), $schoolId, $districtId);

        $user->update([
            'role_id' => $request->integer('role_id'),
            'school_id' => $schoolId,
            'district_id' => $districtId,
        ]);

        return new UserResource($user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Resolve the effective district_id: an explicit district_id wins (and
     * gets cross-checked against school_id in assertAssignable()); absent
     * that, a given school_id's real district is authoritative rather than
     * trusting a stale/absent client value; absent both, fall back.
     */
    private function resolveDistrictId(FormRequest $request, ?int $schoolId, ?int $fallback): ?int
    {
        if ($request->filled('district_id')) {
            return $request->integer('district_id');
        }

        if ($schoolId !== null) {
            return School::find($schoolId)?->district_id;
        }

        return $fallback;
    }

    /**
     * Shared guard for store() and assignRole(): no privilege escalation
     * (the role's permissions must be a subset of the actor's own) and no
     * placing a user outside the actor's own school/district scope.
     */
    private function assertAssignable(User $actor, ?int $roleId, ?int $schoolId, ?int $districtId): void
    {
        if ($roleId !== null) {
            $role = Role::with('permissions')->findOrFail($roleId);
            $rolePermissionNames = $role->permissions->pluck('name');
            $actorPermissionNames = $actor->role?->permissions->pluck('name') ?? collect();

            if ($rolePermissionNames->diff($actorPermissionNames)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'role_id' => ['You cannot assign a role with more permissions than your own.'],
                ]);
            }
        }

        // A submitted school_id must actually belong to the submitted
        // district_id — otherwise a district-scoped actor could pass their
        // own district_id while pointing school_id at a school in a
        // different district entirely, bypassing the scope check below.
        if ($schoolId !== null && School::where('id', $schoolId)->where('district_id', $districtId)->doesntExist()) {
            throw ValidationException::withMessages([
                'school_id' => ['The selected school does not belong to the selected district.'],
            ]);
        }

        if (! $this->inScope($actor, $schoolId, $districtId)) {
            throw ValidationException::withMessages([
                'school_id' => ['You can only assign users within your own school or district.'],
            ]);
        }
    }
}
