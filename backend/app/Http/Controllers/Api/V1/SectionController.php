<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSectionRequest;
use App\Http\Requests\Api\V1\UpdateSectionRequest;
use App\Http\Resources\Api\V1\SectionResource;
use App\Models\Classroom;
use App\Models\Section;
use App\Models\Shift;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $sections = Section::query()
            ->with(['shift.classVersion.schoolClass.school', 'classTeacher', 'defaultClassroom'])
            ->when($request->integer('shift_id'), fn ($query, $id) => $query->where('shift_id', $id))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when(
                $request->integer('class_id'),
                fn ($query, $classId) => $query->whereHas(
                    'shift.classVersion',
                    fn ($versionQuery) => $versionQuery->where('class_id', $classId),
                ),
            )
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return SectionResource::collection($sections);
    }

    /**
     * Display the specified resource.
     */
    public function show(Section $section): SectionResource
    {
        return new SectionResource($section->load(['shift.classVersion.schoolClass.school', 'classTeacher', 'defaultClassroom']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted section with the same (shift_id, name) is restored
     * and updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed row.
     */
    public function store(StoreSectionRequest $request): JsonResponse
    {
        $this->authorize('create', Section::class);

        $shift = Shift::findOrFail($request->integer('shift_id'));

        $classTeacherId = $request->integer('class_teacher_id') ?: null;
        $classroomId = $request->integer('default_classroom_id') ?: null;

        $this->assertTeacherBelongsToSchool($classTeacherId, $shift->school_id);
        $this->assertClassroomBelongsToSchool($classroomId, $shift->school_id);

        $attributes = [
            ...$request->safe()->only(['name', 'bn_name', 'code', 'capacity']),
            'class_teacher_id' => $classTeacherId,
            'default_classroom_id' => $classroomId,
            'is_active' => $request->boolean('is_active', true),
            'shift_id' => $shift->id,
            'school_id' => $shift->school_id,
            'district_id' => $shift->district_id,
        ];

        $section = Section::onlyTrashed()
            ->where('shift_id', $shift->id)
            ->where('name', $attributes['name'])
            ->first();

        if ($section) {
            $section->restore();
            $section->update($attributes);
        } else {
            $section = Section::create($attributes);
        }

        return (new SectionResource($section->load(['shift.classVersion.schoolClass.school', 'classTeacher', 'defaultClassroom'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSectionRequest $request, Section $section): SectionResource
    {
        $this->authorize('update', $section);

        $data = $request->validated();

        if (array_key_exists('class_teacher_id', $data)) {
            $this->assertTeacherBelongsToSchool($data['class_teacher_id'], $section->school_id);
        }

        if (array_key_exists('default_classroom_id', $data)) {
            $this->assertClassroomBelongsToSchool($data['default_classroom_id'], $section->school_id);
        }

        $section->update($data);

        return new SectionResource($section->load(['shift.classVersion.schoolClass.school', 'classTeacher', 'defaultClassroom']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Section $section): Response
    {
        $this->authorize('delete', $section);

        $section->delete();

        return response()->noContent();
    }

    private function assertTeacherBelongsToSchool(?int $teacherId, int $schoolId): void
    {
        if ($teacherId === null) {
            return;
        }

        if (TeacherUser::where('id', $teacherId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'class_teacher_id' => ['The selected teacher does not belong to this section\'s school.'],
            ]);
        }
    }

    private function assertClassroomBelongsToSchool(?int $classroomId, int $schoolId): void
    {
        if ($classroomId === null) {
            return;
        }

        if (Classroom::where('id', $classroomId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'default_classroom_id' => ['The selected classroom does not belong to this section\'s school.'],
            ]);
        }
    }
}
