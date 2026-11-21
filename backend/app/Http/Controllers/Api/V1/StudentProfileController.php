<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttachGuardianRequest;
use App\Http\Requests\Api\V1\StoreStudentProfileRequest;
use App\Http\Requests\Api\V1\TransferStudentSectionRequest;
use App\Http\Requests\Api\V1\UpdateStudentProfileRequest;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Http\Resources\Api\V1\StudentSectionTransferResource;
use App\Models\ClassSchool;
use App\Models\Scopes\TenantScope;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentSectionTransfer;
use App\Models\StudentUser;
use App\Policies\Concerns\ChecksScope;
use App\Support\StudentDashboardBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentProfileController extends Controller
{
    use ChecksScope;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentProfile::class);

        $profiles = StudentProfile::query()
            ->with(['user', 'school', 'district', 'schoolClass', 'guardians'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return StudentProfileResource::collection($profiles);
    }

    /**
     * Display the specified resource.
     */
    public function show(StudentProfile $student_profile): StudentProfileResource
    {
        $this->authorize('view', $student_profile);

        return new StudentProfileResource($student_profile->load(['user', 'school', 'district', 'schoolClass', 'guardians']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * school_id/district_id are derived from the target user, never
     * trusted from client input. class_id must belong to that same
     * school. A soft-deleted profile for the same user is restored and
     * updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed row.
     */
    public function store(StoreStudentProfileRequest $request): JsonResponse
    {
        $this->authorize('create', StudentProfile::class);

        $actor = $request->user();
        $targetUser = StudentUser::findOrFail($request->integer('user_id'));

        if (! $this->inScope($actor, $targetUser->school_id, $targetUser->district_id)) {
            throw ValidationException::withMessages([
                'user_id' => ['You can only create a student profile within your own school or district.'],
            ]);
        }

        $classId = $request->integer('class_id') ?: null;
        $this->assertClassBelongsToSchool($classId, $targetUser->school_id);

        $attributes = [
            // student_id excluded here too: StoreStudentProfileRequest
            // already rejects it outright (`prohibited`), but excluding
            // it from the spread as well means this stays correct even
            // if that rule is ever relaxed — it's always server-
            // generated below, never client-supplied.
            ...$request->safe()->except(['user_id', 'class_id', 'student_id']),
            'user_id' => $targetUser->id,
            'school_id' => $targetUser->school_id,
            'district_id' => $targetUser->district_id,
            'class_id' => $classId,
            'student_id' => StudentProfile::nextStudentId(),
        ];

        $profile = StudentProfile::onlyTrashed()->where('user_id', $targetUser->id)->first();

        if ($profile) {
            $profile->restore();
            $profile->update($attributes);
        } else {
            $profile = StudentProfile::create($attributes);
        }

        return (new StudentProfileResource($profile->load(['user', 'school', 'district', 'schoolClass'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStudentProfileRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $this->authorize('update', $student_profile);

        $data = $request->validated();

        if (array_key_exists('class_id', $data)) {
            $this->assertClassBelongsToSchool($data['class_id'], $student_profile->school_id);
        }

        $student_profile->update($data);

        return new StudentProfileResource($student_profile->load(['user', 'school', 'district', 'schoolClass']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StudentProfile $student_profile): Response
    {
        $this->authorize('delete', $student_profile);

        $student_profile->delete();

        return response()->noContent();
    }

    /**
     * Link a guardian to this student with a relation type.
     */
    public function attachGuardian(AttachGuardianRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $this->authorize('attachGuardian', $student_profile);

        $data = $request->validated();

        $student_profile->guardians()->syncWithoutDetaching([
            $data['guardian_id'] => [
                'relation' => $data['relation'],
                'is_primary' => $data['is_primary'] ?? false,
            ],
        ]);

        return new StudentProfileResource($student_profile->load('guardians'));
    }

    /**
     * Unlink a guardian from this student.
     */
    public function detachGuardian(StudentProfile $student_profile, int $guardian): Response
    {
        $this->authorize('update', $student_profile);

        $student_profile->guardians()->detach($guardian);

        return response()->noContent();
    }

    /**
     * Move the student into a different section, recording the move in
     * student_section_transfers (§2.5 "proper history"). Rejects the
     * move if it would exceed the target section's capacity, unless the
     * actor holds sections.override_capacity and explicitly asked for
     * the override.
     */
    public function transferSection(TransferStudentSectionRequest $request, StudentProfile $student_profile): StudentSectionTransferResource
    {
        $this->authorize('transferSection', $student_profile);

        $toSection = Section::findOrFail($request->integer('to_section_id'));

        if ($toSection->school_id !== $student_profile->school_id) {
            throw ValidationException::withMessages([
                'to_section_id' => ['The selected section does not belong to the student\'s school.'],
            ]);
        }

        $overrideRequested = $request->boolean('override_capacity');
        $actor = $request->user();

        if ($overrideRequested && ! $actor->hasPermission('sections.override_capacity')) {
            throw ValidationException::withMessages([
                'override_capacity' => ['You are not authorized to override section capacity.'],
            ]);
        }

        if (! $overrideRequested && $toSection->capacity !== null && $toSection->enrolledCount() >= $toSection->capacity) {
            throw ValidationException::withMessages([
                'to_section_id' => ['This section is at capacity.'],
            ]);
        }

        $toClassId = $toSection->shift?->classVersion?->class_id;

        $this->assertNoRollNumberCollision($student_profile, $toClassId);

        $fromSectionId = $student_profile->section_id;
        $reason = $request->input('reason');

        // Both writes go in one transaction: the audit row must never be
        // committed without the profile move it claims happened (or vice
        // versa), whatever makes either write fail.
        $transfer = DB::transaction(function () use ($student_profile, $fromSectionId, $toSection, $toClassId, $actor, $reason) {
            $transfer = StudentSectionTransfer::create([
                'student_profile_id' => $student_profile->id,
                'from_section_id' => $fromSectionId,
                'to_section_id' => $toSection->id,
                'transferred_by' => $actor->id,
                'reason' => $reason,
                'transferred_at' => now(),
            ]);

            // shift_id/class_version_id/class_id are all denormalized from
            // section_id — this is the only place section_id ever changes, so
            // it's the only place that needs to keep them in sync. class_id is
            // resolved from the target section's own relations, the same way
            // StudentPromotionController::store() resolves it for a batch move.
            $student_profile->update([
                'section_id' => $toSection->id,
                'shift_id' => $toSection->shift_id,
                'class_version_id' => $toSection->shift?->class_version_id,
                'class_id' => $toClassId,
            ]);

            return $transfer;
        });

        return new StudentSectionTransferResource($transfer->load(['fromSection', 'toSection']));
    }

    /**
     * A read-only rollup of this student's video/material/exam progress
     * per chapter and subject. No Resource class — the payload is a
     * synthetic aggregate computed by StudentDashboardBuilder, not a 1:1
     * transform of this model.
     */
    public function dashboard(StudentProfile $student_profile): JsonResponse
    {
        $this->authorize('viewDashboard', $student_profile);

        return response()->json(['data' => (new StudentDashboardBuilder)->build($student_profile)]);
    }

    /**
     * Reject the move up front if the student's roll_number already
     * belongs to some OTHER student already sitting in the target class.
     * transferSection() carries roll_number over unchanged (same
     * convention as StudentPromotionController), and student_profiles
     * enforces unique(['class_id', 'roll_number']) — since the target
     * section only has to be in the same SCHOOL, not the same class, two
     * different classes' short sequential roll numbers collide routinely.
     * Without this pre-check that collision surfaces as an unhandled 500
     * on the profile update, after the transfer's audit row has already
     * been written.
     *
     * withTrashed(): the unique(['class_id', 'roll_number']) index does
     * NOT exclude soft-deleted rows, so a trashed student in the target
     * class still blocks it — this pre-check must see it too.
     * withoutGlobalScope(TenantScope): student_profiles.class_id
     * references school_classes, a NATIONAL table, so the unique index is
     * effectively global across schools, not per-school — a school-scoped
     * actor's query must not be narrowed to their own school here or it
     * misses cross-school collisions a national_admin would catch, giving
     * the same request different behavior by actor.
     */
    private function assertNoRollNumberCollision(StudentProfile $profile, ?int $toClassId): void
    {
        if ($toClassId === null || $profile->roll_number === null || $profile->roll_number === '') {
            return;
        }

        $collides = StudentProfile::withTrashed()
            ->withoutGlobalScope(TenantScope::class)
            ->where('class_id', $toClassId)
            ->where('roll_number', $profile->roll_number)
            ->whereKeyNot($profile->getKey())
            ->exists();

        if ($collides) {
            throw ValidationException::withMessages([
                'to_section_id' => ['Roll number '.$profile->roll_number.' already exists in the target class. Resolve the conflict before transferring this student.'],
            ]);
        }
    }

    /**
     * A class_id, when given, must belong to the profile's own school —
     * otherwise a student could be assigned into another school's class.
     */
    private function assertClassBelongsToSchool(?int $classId, ?int $schoolId): void
    {
        if ($classId === null) {
            return;
        }

        if (ClassSchool::where('class_id', $classId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'class_id' => ['The selected class has not been adopted by the student\'s school.'],
            ]);
        }
    }
}
