<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRoleRequest;
use App\Http\Requests\Api\V1\UpdateRolePermissionsRequest;
use App\Http\Requests\Api\V1\UpdateRoleRequest;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Role::class);

        return RoleResource::collection(Role::with('permissions')->orderBy('label')->get());
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role): RoleResource
    {
        $this->authorize('view', $role);

        return new RoleResource($role->load('permissions'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted role with the same name is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::onlyTrashed()->where('name', $data['name'])->first();

        if ($role) {
            $role->restore();
            $role->update($data);
        } else {
            $role = Role::create($data);
        }

        return (new RoleResource($role))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role->update($request->validated());

        return new RoleResource($role->load('permissions'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role): Response
    {
        $this->authorize('delete', $role);

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This role is still assigned to users and cannot be deleted.'],
            ]);
        }

        $role->delete();

        return response()->noContent();
    }

    /**
     * Replace the role's permission set.
     *
     * A caller can only grant permissions they themselves hold, so no role
     * can be escalated past the permission level of whoever edits it.
     */
    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): RoleResource
    {
        $requestedNames = collect($request->validated('permissions'));
        $actorPermissionNames = $request->user()->role?->permissions->pluck('name') ?? collect();

        if ($requestedNames->diff($actorPermissionNames)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['You cannot grant permissions you do not have yourself.'],
            ]);
        }

        $role->permissions()->sync(Permission::whereIn('name', $requestedNames)->pluck('id'));

        return new RoleResource($role->load('permissions'));
    }
}
