<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubjectTeacherAssignmentRequest;
use App\Http\Requests\Api\V1\UpdateSubjectTeacherAssignmentRequest;
use App\Http\Resources\Api\V1\SubjectTeacherAssignmentResource;
use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubjectTeacherAssignmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = SubjectTeacherAssignment::query()
            ->with(['section.shift.classVersion.schoolClass', 'subject', 'teacher'])
            ->when($request->integer('section_id'), fn ($query, $id) => $query->where('section_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('teacher_id'), fn ($query, $id) => $query->where('teacher_id', $id))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when(
                $request->integer('class_id'),
                fn ($query, $classId) => $query->whereHas(
                    'section.shift.classVersion',
                    fn ($versionQuery) => $versionQuery->where('class_id', $classId),
                ),
            )
            ->orderBy('section_id')
            ->paginate($request->integer('per_page', 15));

        return SubjectTeacherAssignmentResource::collection($assignments);
    }

    /**
     * Display the specified resource.
     */
    public function show(SubjectTeacherAssignment $subject_teacher_assignment): SubjectTeacherAssignmentResource
    {
        return new SubjectTeacherAssignmentResource($subject_teacher_assignment->load(['section.shift.classVersion.schoolClass', 'subject', 'teacher']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The subject must actually be configured for the section's class
     * version, and the teacher must belong to the section's school — both
     * never trusted from client input alone. A soft-deleted assignment for
     * the same (section_id, subject_id) is restored and updated in place.
     */
    public function store(StoreSubjectTeacherAssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', SubjectTeacherAssignment::class);

        $section = Section::findOrFail($request->integer('section_id'));
        $subjectId = $request->integer('subject_id');
        $teacherId = $request->integer('teacher_id');

        $this->assertSubjectConfiguredForSection($section, $subjectId);
        $this->assertTeacherBelongsToSchool($teacherId, $section->school_id);

        $attributes = [
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'section_id' => $section->id,
            'school_id' => $section->school_id,
            'district_id' => $section->district_id,
        ];

        $assignment = SubjectTeacherAssignment::onlyTrashed()
            ->where('section_id', $section->id)
            ->where('subject_id', $subjectId)
            ->first();

        if ($assignment) {
            $assignment->restore();
            $assignment->update($attributes);
        } else {
            $assignment = SubjectTeacherAssignment::create($attributes);
        }

        return (new SubjectTeacherAssignmentResource($assignment->load(['section.shift.classVersion.schoolClass', 'subject', 'teacher'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSubjectTeacherAssignmentRequest $request, SubjectTeacherAssignment $subject_teacher_assignment): SubjectTeacherAssignmentResource
    {
        $this->authorize('update', $subject_teacher_assignment);

        $this->assertTeacherBelongsToSchool($request->integer('teacher_id'), $subject_teacher_assignment->school_id);

        $subject_teacher_assignment->update($request->validated());

        return new SubjectTeacherAssignmentResource($subject_teacher_assignment->load(['section.shift.classVersion.schoolClass', 'subject', 'teacher']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SubjectTeacherAssignment $subject_teacher_assignment): Response
    {
        $this->authorize('delete', $subject_teacher_assignment);

        $subject_teacher_assignment->delete();

        return response()->noContent();
    }

    private function assertSubjectConfiguredForSection(Section $section, int $subjectId): void
    {
        $classVersion = $section->shift->classVersion;

        if (! $classVersion->subjects()->where('subjects.id', $subjectId)->exists()) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject is not configured for the selected section\'s version.'],
            ]);
        }
    }

    private function assertTeacherBelongsToSchool(int $teacherId, int $schoolId): void
    {
        if (TeacherUser::where('id', $teacherId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'teacher_id' => ['The selected teacher does not belong to this section\'s school.'],
            ]);
        }
    }
}
