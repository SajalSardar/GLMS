<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassRoutineRequest;
use App\Http\Requests\Api\V1\UpdateClassRoutineRequest;
use App\Http\Resources\Api\V1\ClassRoutineResource;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassRoutine;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\TenantScope;
use App\Models\Section;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassRoutineController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $routines = ClassRoutine::query()
            // section/shift/classVersion/academicYear eager-loaded
            // alongside index()'s original subject/teacher/classroom so
            // the Management Portal's Teacher/Class Routine pages can
            // render the same grid the Teacher/Student portals already
            // do, without a second request per relation — same reasoning
            // as mine()'s own eager-load list.
            ->with(['schoolClass', 'subject', 'teacher', 'classroom', 'section', 'shift', 'classVersion', 'academicYear'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('class_version_id'), fn ($query, $classVersionId) => $query->where('class_version_id', $classVersionId))
            ->when($request->integer('shift_id'), fn ($query, $shiftId) => $query->where('shift_id', $shiftId))
            ->when($request->integer('section_id'), fn ($query, $sectionId) => $query->where('section_id', $sectionId))
            ->when($request->integer('teacher_id'), fn ($query, $teacherId) => $query->where('teacher_id', $teacherId))
            ->when($request->integer('academic_year_id'), fn ($query, $academicYearId) => $query->where('academic_year_id', $academicYearId))
            ->when($request->string('day_of_week')->toString(), fn ($query, $day) => $query->where('day_of_week', $day))
            ->orderBy('class_id')
            ->get()
            // Sorted in PHP rather than a MySQL-only orderByRaw('field(...)')
            // — same convention and reasoning as mine()'s own sort, and it
            // makes this endpoint testable against SQLite at all, which the
            // previous MySQL-only ORDER BY silently never was (no test ever
            // exercised index() before this). class_id stays the primary
            // key so a broad, unfiltered call still groups by class first.
            ->sortBy(fn (ClassRoutine $routine) => [$routine->class_id, array_search($routine->day_of_week, ClassRoutine::DAYS_OF_WEEK), $routine->period_no])
            ->values();

        return ClassRoutineResource::collection($routines);
    }

    /**
     * This actor's own weekly routine — a student's exact section (see
     * below) or, for a teacher, every period they're assigned to teach
     * across every class/section, optionally narrowed to one shift via
     * `shift_id`. A teacher who teaches in more than one shift otherwise
     * gets periods from different shifts' own period numbering mixed into
     * the same grid, which reads as a conflict even though it isn't one —
     * the frontend's shift filter (built from this same response's own
     * distinct shifts) is what lets them pick one shift's grid at a time.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        if ($actor instanceof TeacherUser) {
            $currentAcademicYearId = AcademicYear::where('is_current', true)->value('id');

            $routines = ClassRoutine::query()
                ->with(['subject', 'schoolClass', 'section', 'shift', 'classroom', 'academicYear', 'classVersion'])
                ->where('teacher_id', $actor->id)
                ->when($request->integer('shift_id'), fn ($query, $shiftId) => $query->where('shift_id', $shiftId))
                ->when($currentAcademicYearId, fn ($query, $yearId) => $query->where('academic_year_id', $yearId))
                ->orderBy('period_no')
                ->get()
                ->sortBy(fn (ClassRoutine $routine) => array_search($routine->day_of_week, ClassRoutine::DAYS_OF_WEEK) * 100 + $routine->period_no)
                ->values();

            return ClassRoutineResource::collection($routines);
        }

        abort_unless($actor instanceof StudentUser, 403);

        $profile = $actor->profile;

        if ($profile === null || $profile->section_id === null) {
            return ClassRoutineResource::collection(collect());
        }

        $currentAcademicYearId = AcademicYear::where('is_current', true)->value('id');

        $routines = ClassRoutine::query()
            // schoolClass/academicYear/section/shift/classVersion eager-
            // loaded (in addition to index()'s own subject/teacher/
            // classroom) so the printable routine page can show the full
            // year/class/version/shift/section line in its header
            // without a second request per relation.
            ->with(['subject', 'teacher', 'classroom', 'schoolClass', 'academicYear', 'section', 'shift', 'classVersion'])
            ->where('school_id', $profile->school_id)
            ->where('district_id', $profile->district_id)
            ->where('class_id', $profile->class_id)
            ->where('section_id', $profile->section_id)
            ->where('shift_id', $profile->shift_id)
            ->where('class_version_id', $profile->class_version_id)
            ->when($currentAcademicYearId, fn ($query, $yearId) => $query->where('academic_year_id', $yearId))
            ->orderBy('period_no')
            ->get()
            // Sorted in PHP rather than index()'s MySQL-only
            // orderByRaw('field(...)') — this result set is small (one
            // student's own week), so there's no need for the DB-specific
            // function just to apply the same canonical day order.
            ->sortBy(fn (ClassRoutine $routine) => array_search($routine->day_of_week, ClassRoutine::DAYS_OF_WEEK) * 100 + $routine->period_no)
            ->values();

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
     * A soft-deleted entry with the same (class_id, school_id, day_of_week,
     * period_no) is restored and updated in place, rather than attempting
     * a second insert that would collide with the still-present unique
     * index on a trashed row.
     */
    public function store(StoreClassRoutineRequest $request): JsonResponse
    {
        $this->authorize('create', ClassRoutine::class);

        $class = SchoolClass::findOrFail($request->integer('class_id'));
        $school = School::findOrFail($request->integer('school_id'));
        // withoutGlobalScope: TenantScope would otherwise silently filter
        // a different school's section down to null here (the actor's own
        // school_id, not the section's), turning the cross-school guard in
        // assertValid() below into a no-op instead of a rejection — same
        // class of gap as the email-keyed lookup TenantScope doesn't cover.
        $section = $request->filled('section_id')
            ? Section::withoutGlobalScope(TenantScope::class)->find($request->integer('section_id'))
            : null;

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
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            // shift_id/class_version_id are never accepted directly from
            // the request — denormalized from the section itself, same
            // reasoning as school_id/district_id above.
            'section_id' => $section?->id,
            'shift_id' => $section?->shift_id,
            'class_version_id' => $section?->shift?->class_version_id,
        ];

        $this->assertValid($class, $attributes, $school->id, $section);
        $this->assertNoConflicts($attributes);

        $routine = ClassRoutine::onlyTrashed()
            ->where('class_id', $class->id)
            ->where('school_id', $school->id)
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
        $this->authorize('update', $class_routine);

        $data = $request->validated();
        $class = $class_routine->schoolClass;

        // Re-resolved from $data['section_id'] when the caller touches it,
        // never trusted directly — same denormalization (and the same
        // withoutGlobalScope reasoning) as store().
        $section = array_key_exists('section_id', $data)
            ? ($data['section_id'] !== null ? Section::withoutGlobalScope(TenantScope::class)->find($data['section_id']) : null)
            : $class_routine->section;

        if (array_key_exists('section_id', $data)) {
            $data['shift_id'] = $section?->shift_id;
            $data['class_version_id'] = $section?->shift?->class_version_id;
        }

        $merged = [
            'subject_id' => $data['subject_id'] ?? $class_routine->subject_id,
            'teacher_id' => array_key_exists('teacher_id', $data) ? $data['teacher_id'] : $class_routine->teacher_id,
            'classroom_id' => array_key_exists('classroom_id', $data) ? $data['classroom_id'] : $class_routine->classroom_id,
            'academic_year_id' => array_key_exists('academic_year_id', $data) ? $data['academic_year_id'] : $class_routine->academic_year_id,
            'day_of_week' => $data['day_of_week'] ?? $class_routine->day_of_week,
            'period_no' => $data['period_no'] ?? $class_routine->period_no,
            'section_id' => $section?->id,
        ];

        $this->assertValid($class, $merged, $class_routine->school_id, $section);
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
     * configured for this school's offering of this class, a teacher/
     * classroom given by id must belong to the same school as the
     * routine, and a section given by id must belong to this same school
     * AND this same class (via section -> shift -> class_version ->
     * class_id) — never trusted from client input alone.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(SchoolClass $class, array $attributes, int $schoolId, ?Section $section = null): void
    {
        if ($section !== null) {
            $sectionClassId = $section->shift?->classVersion?->class_id;

            if ($section->school_id !== $schoolId || $sectionClassId !== $class->id) {
                throw ValidationException::withMessages([
                    'section_id' => ['The selected section does not belong to this class\'s school.'],
                ]);
            }
        }

        $subjectConfigured = DB::table('class_subjects')
            ->where('class_id', $class->id)
            ->where('school_id', $schoolId)
            ->where('subject_id', $attributes['subject_id'])
            ->exists();

        if (! $subjectConfigured) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject is not configured for the selected class.'],
            ]);
        }

        if ($attributes['teacher_id'] !== null) {
            $teacher = TeacherUser::find($attributes['teacher_id']);

            if (! $teacher || $teacher->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    'teacher_id' => ['The selected teacher does not belong to this class\'s school.'],
                ]);
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['The selected classroom does not belong to this class\'s school.'],
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
