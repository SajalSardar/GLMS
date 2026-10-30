<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttachGuardianRequest;
use App\Http\Requests\Api\V1\StoreStudentProfileRequest;
use App\Http\Requests\Api\V1\TransferStudentSectionRequest;
use App\Http\Requests\Api\V1\UpdateStudentProfileRequest;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Http\Resources\Api\V1\StudentSectionTransferResource;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentSectionTransfer;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
            ->visibleTo($request->user())
            ->with(['user', 'school', 'district', 'schoolClass'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
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
        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));

        if (! $this->inScope($actor, $targetUser->school_id, $targetUser->district_id)) {
            throw ValidationException::withMessages([
                'user_id' => ['You can only create a student profile within your own school or district.'],
            ]);
        }

        $classId = $request->integer('class_id') ?: null;
        $this->assertClassBelongsToSchool($classId, $targetUser->school_id);

        $attributes = [
            ...$request->safe()->except(['user_id', 'class_id']),
            'user_id' => $targetUser->id,
            'school_id' => $targetUser->school_id,
            'district_id' => $targetUser->district_id,
            'class_id' => $classId,
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

        $fromSectionId = $student_profile->section_id;

        $transfer = StudentSectionTransfer::create([
            'student_profile_id' => $student_profile->id,
            'from_section_id' => $fromSectionId,
            'to_section_id' => $toSection->id,
            'transferred_by' => $actor->id,
            'reason' => $request->input('reason'),
            'transferred_at' => now(),
        ]);

        $student_profile->update(['section_id' => $toSection->id]);

        return new StudentSectionTransferResource($transfer->load(['fromSection', 'toSection']));
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

        if (SchoolClass::where('id', $classId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'class_id' => ['The selected class does not belong to the student\'s school.'],
            ]);
        }
    }
}
