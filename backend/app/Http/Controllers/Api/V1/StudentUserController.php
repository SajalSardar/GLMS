<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStudentUserRequest;
use App\Http\Requests\Api\V1\UpdateStudentUserRequest;
use App\Http\Resources\Api\V1\StudentUserResource;
use App\Models\Role;
use App\Models\School;
use App\Models\Scopes\TenantScope;
use App\Models\StudentUser;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentUserController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentUser::class);

        $students = StudentUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return StudentUserResource::collection($students);
    }

    /**
     * Display the specified resource.
     */
    public function show(StudentUser $student_user): StudentUserResource
    {
        $this->authorize('view', $student_user);

        return new StudentUserResource($student_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "student" — fixed by this endpoint, never
     * caller-supplied. Omitted school/district place the new row in the
     * caller's own scope, same convention as UserController::store().
     *
     * A soft-deleted student with the same email is restored and
     * updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed
     * row. That trashed row is only eligible for restoration if it
     * already falls within the acting actor's own school/district
     * scope — otherwise the actor could reassign (and inherit) a
     * trashed account that belongs to a different school/district
     * merely by guessing its email, so an out-of-scope match is
     * rejected instead of silently restored.
     */
    public function store(StoreStudentUserRequest $request): JsonResponse
    {
        $this->authorize('create', StudentUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'student')->first();

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

        // The tenant global scope would otherwise hide a trashed row that
        // belongs to a different school/district, making it invisible to
        // this lookup — which would just fall through to create() and
        // crash on the real DB unique index instead of producing a clean
        // validation error. Bypass it here so the inScope() check below
        // is what actually decides, not an accident of query visibility.
        $trashedStudent = empty($attributes['email'])
            ? null
            : StudentUser::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('email', $attributes['email'])->first();

        if ($trashedStudent && ! $this->inScope($actor, $trashedStudent->school_id, $trashedStudent->district_id)) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        if ($trashedStudent) {
            DB::transaction(function () use ($trashedStudent, $attributes) {
                $trashedStudent->restore();
                $trashedStudent->update($attributes);
            });
            $student = $trashedStudent;
        } else {
            $student = StudentUser::create($attributes);
        }

        return (new StudentUserResource($student->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStudentUserRequest $request, StudentUser $student_user): StudentUserResource
    {
        $this->authorize('update', $student_user);

        $student_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new StudentUserResource($student_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StudentUser $student_user): Response
    {
        $this->authorize('delete', $student_user);

        $student_user->delete();

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
