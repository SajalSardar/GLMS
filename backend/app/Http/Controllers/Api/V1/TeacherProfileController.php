<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeacherProfileRequest;
use App\Http\Requests\Api\V1\UpdateTeacherProfileRequest;
use App\Http\Resources\Api\V1\TeacherProfileResource;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
     * with the still-present unique index on a trashed row.
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
            ...$request->safe()->except('user_id'),
            'user_id' => $targetUser->id,
            'school_id' => $targetUser->school_id,
            'district_id' => $targetUser->district_id,
        ];

        $profile = TeacherProfile::onlyTrashed()->where('user_id', $targetUser->id)->first();

        // employee_id and nid_number each carry their own unique index, and
        // their validation rules are withoutTrashed() (trusting this
        // restore-check to catch a trashed collision) — so a submission
        // that doesn't match an old profile by user_id may still collide
        // with a trashed row on one of these. $attributes below is always
        // the full submitted payload, so restoring and overwriting here
        // leaves no stale field from whoever the row used to belong to.
        if ($profile === null && ($attributes['employee_id'] ?? null) !== null) {
            $profile = TeacherProfile::onlyTrashed()->where('employee_id', $attributes['employee_id'])->first();
        }

        if ($profile === null && ($attributes['nid_number'] ?? null) !== null) {
            $profile = TeacherProfile::onlyTrashed()->where('nid_number', $attributes['nid_number'])->first();
        }

        if ($profile) {
            $this->guardAgainstConflictingRestore($profile, $attributes);

            $profile->restore();
            $profile->update($attributes);
        } else {
            $profile = TeacherProfile::create($attributes);
        }

        return (new TeacherProfileResource($profile->load(['user', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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
     * specific person this profile belongs to, so a user_id-matched
     * restore is always correct to proceed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardAgainstConflictingRestore(TeacherProfile $profile, array $attributes): void
    {
        $errors = [];

        if (($attributes['employee_id'] ?? null) !== null
            && TeacherProfile::withTrashed()->where('id', '!=', $profile->id)->where('employee_id', $attributes['employee_id'])->exists()) {
            $errors['employee_id'] = ['The employee id has already been taken.'];
        }

        if (($attributes['nid_number'] ?? null) !== null
            && TeacherProfile::withTrashed()->where('id', '!=', $profile->id)->where('nid_number', $attributes['nid_number'])->exists()) {
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
