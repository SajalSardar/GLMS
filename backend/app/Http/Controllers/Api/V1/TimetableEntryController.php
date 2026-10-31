<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTimetableEntryRequest;
use App\Http\Requests\Api\V1\UpdateTimetableEntryRequest;
use App\Http\Resources\Api\V1\TimetableEntryResource;
use App\Models\Classroom;
use App\Models\Section;
use App\Models\Shift;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TimetableEntryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Unauthenticated or unpermissioned requests only ever see published
     * entries; an actor with timetable_entries.view may additionally pass
     * `status=draft` scoped to their own school/district (never another
     * tenant's drafts).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous requests), so the user is resolved explicitly
        // from the sanctum guard rather than via $request->user().
        $actor = $request->user('sanctum');
        $canViewDrafts = $actor?->hasPermission('timetable_entries.view') ?? false;
        $status = $request->string('status')->toString();

        $entries = TimetableEntry::query()
            ->with(['section', 'subject', 'teacher', 'classroom'])
            ->when(
                ! $canViewDrafts || $status !== 'draft',
                fn ($query) => $query->where('status', 'published'),
                fn ($query) => $query->where('status', 'draft')
                    ->when($actor->school_id, fn ($q, $schoolId) => $q->where('school_id', $schoolId))
                    ->when(! $actor->school_id && $actor->district_id, fn ($q, $districtId) => $q->where('district_id', $actor->district_id))
            )
            ->when($request->integer('section_id'), fn ($query, $id) => $query->where('section_id', $id))
            ->when($request->integer('teacher_id'), fn ($query, $id) => $query->where('teacher_id', $id))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->string('day_of_week')->toString(), fn ($query, $day) => $query->where('day_of_week', $day))
            ->orderBy('section_id')
            ->orderBy('period_no')
            ->get();

        return TimetableEntryResource::collection($entries);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, TimetableEntry $timetable_entry): TimetableEntryResource
    {
        $actor = $request->user('sanctum');
        $canView = $timetable_entry->status === 'published'
            || ($actor?->hasPermission('timetable_entries.view') && $actor->can('view', $timetable_entry));

        abort_unless($canView, Response::HTTP_FORBIDDEN);

        return new TimetableEntryResource($timetable_entry->load(['section', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * New entries always start as 'draft' — use the publish action to
     * make them visible on the public read routes.
     */
    public function store(StoreTimetableEntryRequest $request): JsonResponse
    {
        $this->authorize('create', TimetableEntry::class);

        $section = Section::findOrFail($request->integer('section_id'));

        $attributes = [
            'subject_id' => $request->integer('subject_id'),
            'teacher_id' => $request->integer('teacher_id'),
            'classroom_id' => $request->filled('classroom_id') ? $request->integer('classroom_id') : $section->default_classroom_id,
            'day_of_week' => $request->string('day_of_week')->toString(),
            'period_no' => $request->integer('period_no'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'section_id' => $section->id,
            'academic_year_id' => $section->shift->academic_year_id,
            'school_id' => $section->school_id,
            'district_id' => $section->district_id,
            'status' => 'draft',
        ];

        $this->assertValid($section, $attributes);
        $this->assertNoConflicts($attributes);

        $entry = TimetableEntry::onlyTrashed()
            ->where('section_id', $section->id)
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->first();

        if ($entry) {
            $entry->restore();
            $entry->update([...$attributes, 'status' => 'draft', 'published_at' => null, 'published_by' => null]);
        } else {
            $entry = TimetableEntry::create($attributes);
        }

        return (new TimetableEntryResource($entry->load(['section', 'subject', 'teacher', 'classroom', 'academicYear'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     *
     * A published entry can't be silently overwritten — editing one
     * requires timetable_entries.publish in addition to the normal
     * update ability (hard constraint #10).
     */
    public function update(UpdateTimetableEntryRequest $request, TimetableEntry $timetable_entry): TimetableEntryResource
    {
        $this->assertEditable($request, $timetable_entry);

        $data = $request->validated();
        $section = $timetable_entry->section;

        $merged = [
            'subject_id' => $data['subject_id'] ?? $timetable_entry->subject_id,
            'teacher_id' => $data['teacher_id'] ?? $timetable_entry->teacher_id,
            'classroom_id' => array_key_exists('classroom_id', $data) ? $data['classroom_id'] : $timetable_entry->classroom_id,
            'day_of_week' => $data['day_of_week'] ?? $timetable_entry->day_of_week,
            'period_no' => $data['period_no'] ?? $timetable_entry->period_no,
        ];

        $this->assertValid($section, $merged);
        $this->assertNoConflicts($merged, $timetable_entry->id);

        $timetable_entry->update($data);

        return new TimetableEntryResource($timetable_entry->load(['section', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, TimetableEntry $timetable_entry): Response
    {
        $this->assertEditable($request, $timetable_entry);

        $timetable_entry->delete();

        return response()->noContent();
    }

    /**
     * Publish a draft entry, making it visible on the public read routes.
     */
    public function publish(Request $request, TimetableEntry $timetable_entry): TimetableEntryResource
    {
        $this->authorize('publish', $timetable_entry);

        $timetable_entry->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $request->user()->id,
        ]);

        return new TimetableEntryResource($timetable_entry->load(['section', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * Revert a published entry to draft so it can be revised.
     */
    public function unpublish(TimetableEntry $timetable_entry): TimetableEntryResource
    {
        $this->authorize('publish', $timetable_entry);

        $timetable_entry->update(['status' => 'draft', 'published_at' => null, 'published_by' => null]);

        return new TimetableEntryResource($timetable_entry->load(['section', 'subject', 'teacher', 'classroom', 'academicYear']));
    }

    /**
     * A published entry requires timetable_entries.publish to edit or
     * delete, on top of the normal update ability — hard constraint #10.
     */
    private function assertEditable(Request $request, TimetableEntry $entry): void
    {
        $this->authorize('update', $entry);

        if ($entry->status === 'published' && ! $request->user()->hasPermission('timetable_entries.publish')) {
            throw ValidationException::withMessages([
                'status' => ['This entry is published; revise it via unpublish or an account with publish rights.'],
            ]);
        }
    }

    /**
     * Configuration guards: the subject must have a valid teacher
     * assignment for this section (hard constraint #9), the day must be
     * one the section's shift actually runs, the period's time (if given)
     * must fall within the shift's hours and outside its breaks, the
     * weekly period cap for the subject must not be exceeded, and room
     * capacity must be sufficient.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(Section $section, array $attributes, ?int $exceptId = null): void
    {
        $shift = $section->shift;
        $classVersion = $shift->classVersion;

        $assignment = SubjectTeacherAssignment::where('section_id', $section->id)
            ->where('subject_id', $attributes['subject_id'])
            ->first();

        if (! $assignment || $assignment->teacher_id !== $attributes['teacher_id']) {
            throw ValidationException::withMessages([
                'teacher_id' => ['This teacher has no valid subject assignment for this section.'],
            ]);
        }

        if (! in_array($attributes['day_of_week'], $shift->days_of_week, true)) {
            throw ValidationException::withMessages([
                'day_of_week' => ['This day is not part of the section\'s shift schedule.'],
            ]);
        }

        if ($attributes['start_time'] !== null && $attributes['end_time'] !== null) {
            $this->assertWithinShiftHours($shift, $attributes['start_time'], $attributes['end_time']);
        }

        $pivot = $classVersion->subjects()->where('subjects.id', $attributes['subject_id'])->first()?->pivot;
        $requiredWeekly = $pivot?->required_weekly_periods;

        if ($requiredWeekly !== null) {
            $scheduledCount = TimetableEntry::where('section_id', $section->id)
                ->where('subject_id', $attributes['subject_id'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->count();

            if ($scheduledCount >= $requiredWeekly) {
                throw ValidationException::withMessages([
                    'subject_id' => ["This subject already has its configured {$requiredWeekly} weekly period(s) scheduled."],
                ]);
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $section->school_id) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['The selected classroom does not belong to this section\'s school.'],
                ]);
            }

            if ($classroom->capacity !== null && $section->capacity !== null && $classroom->capacity < $section->capacity) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['This classroom\'s capacity is smaller than the section\'s capacity.'],
                ]);
            }
        }

        if (User::where('id', $attributes['teacher_id'])->where('school_id', $section->school_id)->doesntExist()) {
            throw ValidationException::withMessages([
                'teacher_id' => ['The selected teacher does not belong to this section\'s school.'],
            ]);
        }
    }

    private function assertWithinShiftHours(Shift $shift, string $startTime, string $endTime): void
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $shiftStart = Carbon::parse($shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($shift->end_time->format('H:i'));

        if ($start->lessThan($shiftStart) || $end->greaterThan($shiftEnd)) {
            throw ValidationException::withMessages([
                'start_time' => ['This period falls outside the section\'s shift hours.'],
            ]);
        }

        foreach ($shift->breaks ?? [] as $break) {
            $breakStart = Carbon::parse($break['start_time']);
            $breakEnd = Carbon::parse($break['end_time']);

            if ($start->lessThan($breakEnd) && $end->greaterThan($breakStart)) {
                throw ValidationException::withMessages([
                    'start_time' => ['This period overlaps a configured break interval.'],
                ]);
            }
        }
    }

    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. The section/day/period triplet
     * itself is enforced by a DB unique index instead (mirrors
     * ClassRoutineController's conflict checks).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoConflicts(array $attributes, ?int $exceptId = null): void
    {
        $teacherConflict = TimetableEntry::where('teacher_id', $attributes['teacher_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($teacherConflict) {
            throw ValidationException::withMessages([
                'teacher_id' => ['This teacher is already assigned to another section in this period.'],
            ]);
        }

        if ($attributes['classroom_id'] !== null) {
            $classroomConflict = TimetableEntry::where('classroom_id', $attributes['classroom_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('period_no', $attributes['period_no'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($classroomConflict) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['This classroom is already booked for another section in this period.'],
                ]);
            }
        }
    }
}
