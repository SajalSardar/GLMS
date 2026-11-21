<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuardianUserRequest;
use App\Http\Requests\Api\V1\UpdateGuardianUserRequest;
use App\Http\Resources\Api\V1\GuardianUserResource;
use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\TenantScope;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
     * row. That trashed row is only eligible for restoration if it
     * already falls within the acting actor's own school/district
     * scope — otherwise the actor could reassign (and inherit) a
     * trashed account that belongs to a different school/district
     * merely by guessing its email, so an out-of-scope match is
     * rejected instead of silently restored.
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
            ...$this->completeAttributes($request),
            'role_id' => $role?->id,
            'school_id' => $schoolId,
            'district_id' => $districtId,
        ];

        // The tenant global scope would otherwise hide a trashed row that
        // belongs to a different school/district, making it invisible to
        // this lookup — which would just fall through to create() and
        // crash on the real DB unique index instead of producing a clean
        // validation error. Bypass it here so the inScope() check below
        // is what actually decides, not an accident of query visibility.
        $trashedGuardian = GuardianUser::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('email', $attributes['email'])->first();
        $matchedField = 'email';

        // The unique index also covers phone, and the phone validation
        // rule is deliberately withoutTrashed() to let a legitimate
        // restore proceed — so a trashed row matched only on phone (a
        // different email) has to be found here too, or it falls
        // through to create() and crashes on the live unique index.
        if ($trashedGuardian === null && ! empty($attributes['phone'])) {
            $trashedGuardian = GuardianUser::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('phone', $attributes['phone'])->first();
            $matchedField = 'phone';
        }

        if ($trashedGuardian && ! $this->inScope($actor, $trashedGuardian->school_id, $trashedGuardian->district_id)) {
            throw ValidationException::withMessages([
                $matchedField => ["The {$matchedField} has already been taken."],
            ]);
        }

        if ($trashedGuardian) {
            $this->guardAgainstConflictingRestore($trashedGuardian, $attributes);

            DB::transaction(function () use ($trashedGuardian, $attributes) {
                $trashedGuardian->restore();
                $trashedGuardian->update($attributes);
            });
            $guardian = $trashedGuardian;
        } else {
            $guardian = GuardianUser::create($attributes);
        }

        return (new GuardianUserResource($guardian->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * The email/phone match above can land on a trashed guardian that only
     * partially matches the incoming data (e.g. matched on email while a
     * *different* trashed or live guardian already holds the incoming
     * phone). Restoring and updating in that case would either collide
     * with the still-present unique index (an unhandled 500) or silently
     * overwrite the wrong record. Reject it the same way the create path
     * would via validation.
     *
     * The tenant global scope is bypassed for the same reason it is
     * above: a conflicting row in a different school/district is still
     * invisible to the real unique index, so it must not be invisible
     * here either.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardAgainstConflictingRestore(GuardianUser $guardian, array $attributes): void
    {
        $errors = [];

        if (($attributes['email'] ?? null) !== null
            && GuardianUser::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $guardian->id)->where('email', $attributes['email'])->exists()) {
            $errors['email'] = ['The email has already been taken.'];
        }

        if (($attributes['phone'] ?? null) !== null
            && GuardianUser::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $guardian->id)->where('phone', $attributes['phone'])->exists()) {
            $errors['phone'] = ['The phone has already been taken.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Build the complete attribute set for this submission: every fillable
     * column the request did not include is explicitly set to null.
     *
     * A restore-check match can land on a trashed guardian that is a
     * COMPLETELY DIFFERENT person (it is matched by phone, not only by
     * email). Updating such a row with just the submitted keys is a
     * partial merge, not a replacement: every fillable column absent from
     * the new submission silently keeps the previous owner's value — a
     * new guardian created without bn_name would come back still carrying
     * the old guardian's bn_name. Restoring a trashed row found via ANY
     * column match must therefore behave exactly like a fresh create()
     * with complete, honest data.
     *
     * role_id/school_id/district_id are excluded because the caller
     * resolves them from elsewhere (the fixed "parent_guardian" role, and
     * the actor's/explicit scope) rather than from raw input.
     *
     * @return array<string, mixed>
     */
    private function completeAttributes(StoreGuardianUserRequest $request): array
    {
        $resolvedColumns = ['role_id', 'school_id', 'district_id'];
        $attributes = [];

        foreach ((new GuardianUser)->getFillable() as $column) {
            if (in_array($column, $resolvedColumns, true)) {
                continue;
            }

            $attributes[$column] = $request->has($column) ? $request->validated($column) : null;
        }

        return $attributes;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGuardianUserRequest $request, GuardianUser $guardian_user): GuardianUserResource
    {
        $this->authorize('update', $guardian_user);

        $guardian_user->update($request->safe()->only(['name', 'bn_name', 'email', 'phone', 'password']));

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
