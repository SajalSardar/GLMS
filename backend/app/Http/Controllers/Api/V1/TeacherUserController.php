<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeacherUserRequest;
use App\Http\Requests\Api\V1\UpdateTeacherUserRequest;
use App\Http\Resources\Api\V1\TeacherUserResource;
use App\Models\Role;
use App\Models\School;
use App\Models\TeacherUser;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TeacherUserController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherUser::class);

        $teachers = TeacherUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return TeacherUserResource::collection($teachers);
    }

    /**
     * Display the specified resource.
     */
    public function show(TeacherUser $teacher_user): TeacherUserResource
    {
        $this->authorize('view', $teacher_user);

        return new TeacherUserResource($teacher_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "teacher" — fixed by this endpoint, never
     * caller-supplied (unlike UserController::store(), which lets a
     * caller pick any role up to their own privilege level). Omitted
     * school/district place the new row in the caller's own scope, same
     * convention as UserController::store().
     */
    public function store(StoreTeacherUserRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'teacher')->first();

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

        $teacher = TeacherUser::create([
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $role?->id,
            'school_id' => $schoolId,
            'district_id' => $districtId,
        ]);

        return (new TeacherUserResource($teacher->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeacherUserRequest $request, TeacherUser $teacher_user): TeacherUserResource
    {
        $this->authorize('update', $teacher_user);

        $teacher_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new TeacherUserResource($teacher_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TeacherUser $teacher_user): Response
    {
        $this->authorize('delete', $teacher_user);

        $teacher_user->delete();

        return response()->noContent();
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
}
