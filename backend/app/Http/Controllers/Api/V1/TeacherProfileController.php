<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeacherProfileRequest;
use App\Http\Requests\Api\V1\UpdateTeacherProfileRequest;
use App\Http\Resources\Api\V1\TeacherProfileResource;
use App\Models\TeacherProfile;
use App\Models\User;
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
     * trusted from client input. A soft-deleted profile for the same user
     * is restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreTeacherProfileRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherProfile::class);

        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));

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

        if ($profile) {
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
