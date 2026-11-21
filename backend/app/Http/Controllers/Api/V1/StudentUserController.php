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
use Illuminate\Http\Request;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentUser::class);

        $students = StudentUser::query()
            ->with(['role.permissions', 'school', 'district', 'profile.schoolClass'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when(
                $request->integer('class_id'),
                fn ($query, $classId) => $query->whereHas('profile', fn ($profileQuery) => $profileQuery->where('class_id', $classId)),
            )
            ->when(
                $request->string('search')->toString(),
                fn ($query, $search) => $query->where(
                    fn ($nameOrEmail) => $nameOrEmail->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"),
                ),
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

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
            ...$request->safe()->only(['name', 'bn_name', 'email', 'phone', 'password']),
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
        $matchedField = 'email';

        // The unique index also covers phone, and the phone validation
        // rule is deliberately withoutTrashed() to let a legitimate
        // restore proceed — so a trashed row matched only on phone (a
        // different email) has to be found here too, or it falls
        // through to create() and crashes on the live unique index.
        if ($trashedStudent === null && ! empty($attributes['phone'])) {
            $trashedStudent = StudentUser::onlyTrashed()->withoutGlobalScope(TenantScope::class)->where('phone', $attributes['phone'])->first();
            $matchedField = 'phone';
        }

        if ($trashedStudent && ! $this->inScope($actor, $trashedStudent->school_id, $trashedStudent->district_id)) {
            throw ValidationException::withMessages([
                $matchedField => ["The {$matchedField} has already been taken."],
            ]);
        }

        if ($trashedStudent) {
            $this->guardAgainstConflictingRestore($trashedStudent, $attributes);

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
     * The email/phone match above can land on a trashed student that only
     * partially matches the incoming data (e.g. matched on email while a
     * *different* trashed or live student already holds the incoming
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
    private function guardAgainstConflictingRestore(StudentUser $student, array $attributes): void
    {
        $errors = [];

        if (($attributes['email'] ?? null) !== null
            && StudentUser::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $student->id)->where('email', $attributes['email'])->exists()) {
            $errors['email'] = ['The email has already been taken.'];
        }

        if (($attributes['phone'] ?? null) !== null
            && StudentUser::withTrashed()->withoutGlobalScope(TenantScope::class)->where('id', '!=', $student->id)->where('phone', $attributes['phone'])->exists()) {
            $errors['phone'] = ['The phone has already been taken.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStudentUserRequest $request, StudentUser $student_user): StudentUserResource
    {
        $this->authorize('update', $student_user);

        $student_user->update($request->safe()->only(['name', 'bn_name', 'email', 'phone', 'password']));

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
