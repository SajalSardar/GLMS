<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuardianUserRequest;
use App\Http\Requests\Api\V1\UpdateGuardianUserRequest;
use App\Http\Resources\Api\V1\GuardianUserResource;
use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\School;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class GuardianUserController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GuardianUser::class);

        $guardians = GuardianUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return GuardianUserResource::collection($guardians);
    }

    /**
     * Display the specified resource.
     */
    public function show(GuardianUser $guardian_user): GuardianUserResource
    {
        $this->authorize('view', $guardian_user);

        return new GuardianUserResource($guardian_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "parent_guardian" — fixed by this endpoint,
     * never caller-supplied. Omitted school/district place the new row
     * in the caller's own scope, same convention as
     * UserController::store().
     *
     * A soft-deleted guardian with the same email is restored and
     * updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed
     * row.
     */
    public function store(StoreGuardianUserRequest $request): JsonResponse
    {
        $this->authorize('create', GuardianUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'parent_guardian')->first();

        $schoolId = $request->filled('school_id') ? $request->integer('school_id') : $actor->school_id;
        $districtId = $this->resolveDistrictId($request, $schoolId, $actor->district_id);

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
                'school_id' => ['You cannot create an account outside your own school or district.'],
            ]);
        }

        $attributes = [
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $role?->id,
            'school_id' => $schoolId,
            'district_id' => $districtId,
        ];

        $guardian = GuardianUser::onlyTrashed()->where('email', $attributes['email'])->first();

        if ($guardian) {
            $guardian->restore();
            $guardian->update($attributes);
        } else {
            $guardian = GuardianUser::create($attributes);
        }

        return (new GuardianUserResource($guardian->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGuardianUserRequest $request, GuardianUser $guardian_user): GuardianUserResource
    {
        $this->authorize('update', $guardian_user);

        $guardian_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new GuardianUserResource($guardian_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GuardianUser $guardian_user): Response
    {
        $this->authorize('delete', $guardian_user);

        $guardian_user->delete();

        return response()->noContent();
    }

    /**
     * Resolve the effective district_id: an explicit district_id wins (and
     * gets cross-checked against school_id above); absent that, a given
     * school_id's real district is authoritative rather than trusting a
     * stale/absent client value; absent both, fall back.
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
}
