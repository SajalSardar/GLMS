<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassRoutineRequest;
use App\Http\Requests\Api\V1\UpdateClassRoutineRequest;
use App\Http\Resources\Api\V1\ClassRoutineResource;
use App\Models\Classroom;
use App\Models\ClassRoutine;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClassRoutineController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $routines = ClassRoutine::query()
            ->with(['schoolClass', 'subject', 'teacher', 'classroom'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('teacher_id'), fn ($query, $teacherId) => $query->where('teacher_id', $teacherId))
            ->when($request->integer('academic_year_id'), fn ($query, $academicYearId) => $query->where('academic_year_id', $academicYearId))
            ->when($request->string('day_of_week')->toString(), fn ($query, $day) => $query->where('day_of_week', $day))
            ->orderBy('class_id')
            ->orderByRaw('field(day_of_week, '.implode(',', array_fill(0, count(ClassRoutine::DAYS_OF_WEEK), '?')).')', ClassRoutine::DAYS_OF_WEEK)
            ->orderBy('period_no')
            ->get();

        return ClassRoutineResource::collection($routines);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClassRoutine $class_routine): ClassRoutineResource
    {
        return new ClassRoutineResource($class_routine->load(['schoolClass', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted entry with the same (class_id, day_of_week, period_no)
     * is restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreClassRoutineRequest $request): JsonResponse
    {
        $class = SchoolClass::findOrFail($request->integer('class_id'));

        $attributes = [
            'subject_id' => $request->integer('subject_id'),
            // Explicit null fallback rather than request->safe()->only():
            // a `nullable` (non-`sometimes`) rule drops the key entirely
            // from validated() when absent, which previously left these
            // missing from $attributes instead of set to null.
            'teacher_id' => $request->filled('teacher_id') ? $request->integer('teacher_id') : null,
            'classroom_id' => $request->filled('classroom_id') ? $request->integer('classroom_id') : null,
            'academic_year_id' => $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
            'day_of_week' => $request->string('day_of_week')->toString(),
            'period_no' => $request->integer('period_no'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'class_id' => $class->id,
            'school_id' => $class->school_id,
            'district_id' => $class->district_id,
        ];

        $this->assertValid($class, $attributes);
        $this->assertNoConflicts($attributes);

        $routine = ClassRoutine::onlyTrashed()
            ->where('class_id', $class->id)
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->first();

        if ($routine) {
            $routine->restore();
            $routine->update($attributes);
        } else {
            $routine = ClassRoutine::create($attributes);
        }

        return (new ClassRoutineResource($routine->load(['schoolClass', 'subject', 'teacher', 'classroom', 'academicYear'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClassRoutineRequest $request, ClassRoutine $class_routine): ClassRoutineResource
    {
        $data = $request->validated();
        $class = $class_routine->schoolClass;

        $merged = [
            'subject_id' => $data['subject_id'] ?? $class_routine->subject_id,
            'teacher_id' => array_key_exists('teacher_id', $data) ? $data['teacher_id'] : $class_routine->teacher_id,
            'classroom_id' => array_key_exists('classroom_id', $data) ? $data['classroom_id'] : $class_routine->classroom_id,
            'academic_year_id' => array_key_exists('academic_year_id', $data) ? $data['academic_year_id'] : $class_routine->academic_year_id,
            'day_of_week' => $data['day_of_week'] ?? $class_routine->day_of_week,
            'period_no' => $data['period_no'] ?? $class_routine->period_no,
        ];

        $this->assertValid($class, $merged);
        $this->assertNoConflicts($merged, $class_routine->id);

        $class_routine->update($data);

        return new ClassRoutineResource($class_routine->load(['schoolClass', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClassRoutine $class_routine): Response
    {
        $this->authorize('delete', $class_routine);

        $class_routine->delete();

        return response()->noContent();
    }

    /**
     * Cross-tenant and configuration guards: the subject must actually be
     * configured for this class, and a teacher/classroom/academic year
     * given by id must belong to the same school as the class — never
     * trusted from client input alone.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(SchoolClass $class, array $attributes): void
    {
        if (! $class->subjects()->where('subjects.id', $attributes['subject_id'])->exists()) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject is not configured for the selected class.'],
            ]);
        }

        if ($attributes['teacher_id'] !== null) {
            $teacher = User::find($attributes['teacher_id']);

            if (! $teacher || $teacher->school_id !== $class->school_id) {
                throw ValidationException::withMessages([
                    'teacher_id' => ['The selected teacher does not belong to this class\'s school.'],
                ]);
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $class->school_id) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['The selected classroom does not belong to this class\'s school.'],
                ]);
            }
        }

        if ($attributes['academic_year_id'] !== null) {
            $academicYear = $class->school->academicYears()->find($attributes['academic_year_id']);

            if (! $academicYear) {
                throw ValidationException::withMessages([
                    'academic_year_id' => ['The selected academic year does not belong to this class\'s school.'],
                ]);
            }
        }
    }

    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. This is deliberately NOT scoped by
     * academic_year_id — a teacher physically can't be in two classes at
     * once regardless of which year label either row carries, and the
     * class/day/period DB unique index itself has no year dimension
     * either (this app models one standing routine per class, not
     * parallel per-year routines).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoConflicts(array $attributes, ?int $exceptId = null): void
    {
        if ($attributes['teacher_id'] !== null) {
            $conflict = ClassRoutine::where('teacher_id', $attributes['teacher_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('period_no', $attributes['period_no'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'teacher_id' => ['This teacher is already assigned to another class in this period.'],
                ]);
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $conflict = ClassRoutine::where('classroom_id', $attributes['classroom_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('period_no', $attributes['period_no'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['This classroom is already booked for another class in this period.'],
                ]);
            }
        }
    }
}
