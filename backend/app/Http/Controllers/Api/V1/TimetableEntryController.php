<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTimetableEntryRequest;
use App\Http\Requests\Api\V1\UpdateTimetableEntryRequest;
use App\Http\Resources\Api\V1\TimetableEntryResource;
use App\Models\Section;
use App\Models\Shift;
use App\Models\TimetableEntry;
use App\Support\TimetableConflictChecker;
use App\Support\TimetableGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TimetableEntryController extends Controller
{
    public function __construct(private TimetableConflictChecker $conflictChecker) {}

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

        $entry = TimetableEntry::createOrRestore($attributes);

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
            'start_time' => $data['start_time'] ?? $timetable_entry->start_time?->format('H:i'),
            'end_time' => $data['end_time'] ?? $timetable_entry->end_time?->format('H:i'),
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
     * Fill every section under this shift with conflict-free draft
     * entries for each assigned subject's full required_weekly_periods.
     * Published entries are never touched; a section's own pre-existing
     * draft entries are replaced on success and left untouched on
     * failure (TimetableGenerator handles both per-section, inside its
     * own transaction).
     */
    public function generate(Shift $shift, TimetableGenerator $generator): JsonResponse
    {
        $this->authorize('generate', TimetableEntry::class);

        return response()->json(['data' => $generator->generate($shift)]);
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
     * capacity must be sufficient. Delegates to TimetableConflictChecker,
     * shared with TimetableGenerator.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(Section $section, array $attributes, ?int $exceptId = null): void
    {
        $error = $this->conflictChecker->invalidReason($section, $attributes, $exceptId);

        if ($error !== null) {
            throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
        }
    }

    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. The section/day/period triplet
     * itself is enforced by a DB unique index instead (mirrors
     * ClassRoutineController's conflict checks). Delegates to
     * TimetableConflictChecker, shared with TimetableGenerator.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoConflicts(array $attributes, ?int $exceptId = null): void
    {
        $error = $this->conflictChecker->conflictReason($attributes, $exceptId);

        if ($error !== null) {
            throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
        }
    }
}
