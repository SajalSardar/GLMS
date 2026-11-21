<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeacherProfileRequest;
use App\Http\Requests\Api\V1\UpdateTeacherProfileRequest;
use App\Http\Resources\Api\V1\TeacherProfileResource;
use App\Models\Scopes\TenantScope;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherProfileController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherProfile::class);

        $profiles = TeacherProfile::query()
            ->with(['user', 'school', 'district'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return TeacherProfileResource::collection($profiles);
    }

    /**
     * Display the specified resource.
     */
    public function show(TeacherProfile $teacher_profile): TeacherProfileResource
    {
        $this->authorize('view', $teacher_profile);

        return new TeacherProfileResource($teacher_profile->load(['user', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * school_id/district_id are derived from the target user, never
     * trusted from client input. A soft-deleted profile matching the same
     * user_id, employee_id, or nid_number is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row. That trashed
     * row is only eligible for restoration if it already falls within the
     * acting actor's own school/district scope — otherwise the actor could
     * reassign (and inherit) a trashed profile belonging to a different
     * school/district merely by guessing its employee_id or nid_number, so
     * an out-of-scope match is rejected instead of silently restored. The
     * restore writes the COMPLETE attribute set (see completeAttributes()),
     * so the reused row keeps nothing of whoever it used to belong to.
     */
    public function store(StoreTeacherProfileRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherProfile::class);

        $actor = $request->user();
        $targetUser = TeacherUser::findOrFail($request->integer('user_id'));

        if (! $this->inScope($actor, $targetUser->school_id, $targetUser->district_id)) {
            throw ValidationException::withMessages([
                'user_id' => ['You can only create a teacher profile within your own school or district.'],
            ]);
        }

        $attributes = [
            ...$this->completeAttributes($request),
            'user_id' => $targetUser->id,
            'school_id' => $targetUser->school_id,
            'district_id' => $targetUser->district_id,
        ];

        // The tenant global scope would otherwise hide a trashed row that
        // belongs to a different school/district, making it invisible to
        // these lookups — which would just fall through to create() and
        // crash on the real DB unique index (employee_id and nid_number
        // are GLOBAL unique indexes, not per-school) instead of producing
        // a clean validation error. Bypass it here so the inScope() check
        // below is what actually decides, not an accident of query
        // visibility.
        $profile = TeacherProfile::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('user_id', $targetUser->id)->first();
        $matchedField = 'user_id';

        // employee_id and nid_number each carry their own unique index, and
        // their validation rules are withoutTrashed() (trusting this
        // restore-check to catch a trashed collision) — so a submission
        // that doesn't match an old profile by user_id may still collide
        // with a trashed row on one of these.
        if ($profile === null && $attributes['employee_id'] !== null) {
            $profile = TeacherProfile::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('employee_id', $attributes['employee_id'])->first();
            $matchedField = 'employee_id';
        }

        if ($profile === null && $attributes['nid_number'] !== null) {
            $profile = TeacherProfile::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('nid_number', $attributes['nid_number'])->first();
            $matchedField = 'nid_number';
        }

        if ($profile && ! $this->inScope($actor, $profile->school_id, $profile->district_id)) {
            throw ValidationException::withMessages([
                $matchedField => ["The {$matchedField} has already been taken."],
            ]);
        }

        if ($profile) {
            $this->guardAgainstConflictingRestore($profile, $attributes);

            // restore() and update() are two separate writes. If the
            // second one fails (e.g. a unique-index race the guard above
            // could not see), an unwrapped pair would leave the row
            // un-deleted — a request that returned 500 to the caller would
            // have silently resurrected a deleted teacher's profile.
            DB::transaction(function () use ($profile, $attributes) {
                $profile->restore();
                $profile->update($attributes);
            });
        } else {
            $profile = TeacherProfile::create($attributes);
        }

        return (new TeacherProfileResource($profile->load(['user', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Build the complete attribute set for this submission: every fillable
     * column the request did not include is explicitly set to null.
     *
     * A restore-check match can land on a trashed profile that belonged to
     * a COMPLETELY DIFFERENT teacher (it is matched by employee_id or
     * nid_number, not only by user_id). Updating such a row with just the
     * submitted keys is a partial merge, not a replacement: every fillable
     * column absent from the new submission silently keeps the previous
     * owner's value, so a new profile created with only
     * {user_id, employee_id} would inherit the old teacher's nid_number,
     * phone, designation and blood group — real PII leaking across
     * identities, not cosmetic staleness. Restoring a trashed row found
     * via ANY column match must therefore behave exactly like a fresh
     * create() with complete, honest data. user_id/school_id/district_id
     * are excluded because the caller resolves them from the target user
     * rather than from raw input.
     *
     * @return array<string, mixed>
     */
    private function completeAttributes(StoreTeacherProfileRequest $request): array
    {
        $resolvedColumns = ['user_id', 'school_id', 'district_id'];
        $attributes = [];

        foreach ((new TeacherProfile)->getFillable() as $column) {
            if (in_array($column, $resolvedColumns, true)) {
                continue;
            }

            $attributes[$column] = $request->has($column) ? $request->validated($column) : null;
        }

        return $attributes;
    }

    /**
     * The user_id/employee_id/nid_number match above can land on a trashed
     * profile that only partially matches the incoming data (e.g. matched
     * on employee_id while a *different* trashed or live profile already
     * holds the incoming nid_number). Restoring and updating in that case
     * would either collide with the still-present unique index (an
     * unhandled 500) or silently overwrite the wrong record. Reject it the
     * same way the create path would via validation.
     *
     * user_id itself needs no guard here: it genuinely identifies the
     * specific person this profile belongs to, and the lookup above keys
     * its first query on user_id (bypassing the tenant scope), so any
     * trashed row already holding the incoming user_id is the row being
     * restored — a user_id-matched restore is always correct to proceed.
     *
     * The tenant global scope is bypassed for the same reason it is above:
     * teacher_profiles.employee_id/nid_number are global unique indexes,
     * so a conflicting row in a different school/district is still a hard
     * DB collision and must not be invisible here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardAgainstConflictingRestore(TeacherProfile $profile, array $attributes): void
    {
        $errors = [];

        if (($attributes['employee_id'] ?? null) !== null
            && TeacherProfile::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $profile->id)->where('employee_id', $attributes['employee_id'])->exists()) {
            $errors['employee_id'] = ['The employee id has already been taken.'];
        }

        if (($attributes['nid_number'] ?? null) !== null
            && TeacherProfile::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $profile->id)->where('nid_number', $attributes['nid_number'])->exists()) {
            $errors['nid_number'] = ['The nid number has already been taken.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeacherProfileRequest $request, TeacherProfile $teacher_profile): TeacherProfileResource
    {
        $this->authorize('update', $teacher_profile);

        $teacher_profile->update($request->validated());

        return new TeacherProfileResource($teacher_profile->load(['user', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TeacherProfile $teacher_profile): Response
    {
        $this->authorize('delete', $teacher_profile);

        $teacher_profile->delete();

        return response()->noContent();
    }
}
