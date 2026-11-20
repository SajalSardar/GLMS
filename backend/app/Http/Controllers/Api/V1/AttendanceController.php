<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAttendanceRequest;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * Sections this teacher may touch attendance for — the ones they're
     * the class teacher of (can_record: true) plus any they only hold a
     * SubjectTeacherAssignment in (can_record: false, view only) — the
     * same two-tier split AttendancePolicy enforces per-section,
     * flattened into one list so the teacher portal can build its
     * section picker in a single call.
     */
    public function mySections(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $sections = Section::query()
            ->with(['shift.classVersion.schoolClass', 'shift.academicYear'])
            ->where(function ($query) use ($teacher) {
                $query->where('class_teacher_id', $teacher->id)
                    ->orWhereHas('subjectTeacherAssignments', fn ($assignments) => $assignments->where('teacher_id', $teacher->id));
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sections->map(fn (Section $section) => [
                'id' => $section->id,
                'name' => $section->bn_name ?? $section->name,
                'class_name' => $section->shift?->classVersion?->schoolClass?->name,
                'class_version' => $section->shift?->classVersion?->name,
                'shift' => $section->shift?->name,
                'academic_year' => $section->shift?->academicYear?->name,
                'can_record' => $section->class_teacher_id === $teacher->id,
            ])->values(),
        ]);
    }

    /**
     * The full day roster for a section — every active student plus
     * whatever attendance row already exists for `date`, so the caller
     * can render one row per student with its current status pre-filled
     * in a single call. `can_record` lets the frontend hide the save
     * controls for a view-only teacher, same convention as `can_review`
     * on student leave applications.
     */
    public function roster(Request $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $this->authorize('viewSection', [Attendance::class, $section]);

        $date = $this->resolveDate($request);
        $holiday = $this->holidayCovering($date);

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'date' => $date->toDateString(),
                'can_record' => $request->user()->can('recordSection', [Attendance::class, $section]),
                'holiday_name' => $holiday?->name,
                'students' => $this->rosterRows($section, $date),
            ],
        ]);
    }

    /**
     * Bulk-record/update one day's attendance for a section — only the
     * section's own class teacher (or a staff override) may call this,
     * per RecordAttendanceRequest::authorize(). Rejected outright for a
     * holiday date (weekly Friday/Saturday or a government holiday):
     * there is nothing to mark, and the report reads the holiday's name
     * for that date from the Holiday calendar instead.
     */
    public function record(RecordAttendanceRequest $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $date = Carbon::parse($request->string('date')->toString());

        $holiday = $this->holidayCovering($date);

        if ($holiday !== null) {
            throw ValidationException::withMessages([
                'date' => ["Attendance can't be recorded on a holiday ({$holiday->name})."],
            ]);
        }

        $shift = $section->shift;
        $validStudentIds = StudentProfile::where('section_id', $section->id)->pluck('id');
        $actor = $request->user();

        foreach ($request->array('records') as $record) {
            $studentProfileId = (int) ($record['student_profile_id'] ?? 0);

            if (! $validStudentIds->contains($studentProfileId)) {
                continue;
            }

            // Looked up via whereDate() rather than passed through
            // updateOrCreate()'s own match array: that array is used
            // as literal query-builder WHERE conditions, bypassing the
            // `date` cast's set-mutator entirely — it would never match
            // an existing row (whose stored value went through that
            // mutator on its own insert) and silently double-insert,
            // tripping the unique index on the second day recorded.
            $existing = Attendance::where('student_profile_id', $studentProfileId)
                ->whereDate('date', $date->toDateString())
                ->first();

            $attributes = [
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
                'class_id' => $shift?->classVersion?->class_id,
                'section_id' => $section->id,
                'shift_id' => $section->shift_id,
                'class_version_id' => $shift?->class_version_id,
                'academic_year_id' => $shift?->academic_year_id,
                'status' => $record['status'],
                'marked_by_id' => $actor instanceof TeacherUser ? $actor->id : null,
            ];

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                Attendance::create([
                    ...$attributes,
                    'student_profile_id' => $studentProfileId,
                    'date' => $date->toDateString(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'date' => $date->toDateString(),
                'can_record' => true,
                'holiday_name' => null,
                'students' => $this->rosterRows($section, $date),
            ],
        ]);
    }

    /**
     * Monthly attendance grid for a section — every active student's
     * daily status across the month, plus a per-date holiday name for
     * days nothing was (or could be) recorded on. This is the data
     * behind the teacher-portal report grid.
     */
    public function report(Request $request): JsonResponse
    {
        $section = Section::with(['shift.classVersion.schoolClass', 'shift.academicYear', 'school', 'classTeacher'])
            ->findOrFail($request->integer('section_id'));
        $this->authorize('viewSection', [Attendance::class, $section]);

        [$year, $month, $start, $end] = $this->resolveMonth($request);
        $dates = $this->dateRange($start, $end);

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $attendanceByStudent = Attendance::where('section_id', $section->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('student_profile_id');

        $studentRows = $students->map(function (StudentProfile $profile) use ($attendanceByStudent) {
            $own = $attendanceByStudent->get($profile->id, collect())->keyBy(fn (Attendance $row) => $row->date->toDateString());

            return [
                'student_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'roll_number' => $profile->roll_number,
                'statuses' => $own->map(fn (Attendance $row) => $row->status)->all(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->bn_name ?? $section->name,
                    'school_name' => $section->school?->name,
                    'school_address' => $section->school?->address,
                    'class_name' => $section->shift?->classVersion?->schoolClass?->name,
                    'class_version' => $section->shift?->classVersion?->name,
                    'shift' => $section->shift?->name,
                    'academic_year' => $section->shift?->academicYear?->name,
                    'class_teacher' => $section->classTeacher?->name,
                ],
                'year' => $year,
                'month' => $month,
                'dates' => $dates,
                'students' => $studentRows,
            ],
        ]);
    }

    /**
     * This student's own monthly attendance — the source data for the
     * student portal's calendar view.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = $request->user();
        abort_unless($student instanceof StudentUser, 403);

        $profile = $student->profile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        [$year, $month, $start, $end] = $this->resolveMonth($request);

        $records = Attendance::where('student_profile_id', $profile->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $row) => $row->date->toDateString());

        $holidays = Holiday::where('is_active', true)->get();

        $days = [];
        $summary = ['present' => 0, 'absent' => 0, 'leave' => 0];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $record = $records->get($cursor->toDateString());
            $holiday = $holidays->first(fn (Holiday $item) => $item->coversDate($cursor));

            if ($record !== null) {
                $summary[$record->status]++;
            }

            $days[] = [
                'date' => $cursor->toDateString(),
                'status' => $record?->status,
                'holiday_name' => $holiday?->name,
            ];
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'days' => $days,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * @return array<int, array{student_profile_id: int, name: ?string, roll_number: ?string, status: ?string}>
     */
    private function rosterRows(Section $section, Carbon $date): array
    {
        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $existing = Attendance::where('section_id', $section->id)
            ->whereDate('date', $date->toDateString())
            ->get()
            ->keyBy('student_profile_id');

        return $students->map(fn (StudentProfile $profile) => [
            'student_profile_id' => $profile->id,
            'name' => $profile->user?->name,
            'roll_number' => $profile->roll_number,
            'status' => $existing->get($profile->id)?->status,
        ])->values()->all();
    }

    private function holidayCovering(Carbon $date): ?Holiday
    {
        return Holiday::where('is_active', true)->get()->first(fn (Holiday $holiday) => $holiday->coversDate($date));
    }

    private function resolveDate(Request $request): Carbon
    {
        $raw = $request->string('date')->toString();

        return $raw !== '' ? Carbon::parse($raw) : Carbon::today();
    }

    /**
     * @return array{0: int, 1: int, 2: Carbon, 3: Carbon}
     */
    private function resolveMonth(Request $request): array
    {
        $year = $request->integer('year') ?: (int) now()->format('Y');
        $month = $request->integer('month') ?: (int) now()->format('n');

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$year, $month, $start, $end];
    }

    /**
     * @return array<int, array{date: string, day: int, holiday_name: ?string}>
     */
    private function dateRange(Carbon $start, Carbon $end): array
    {
        $holidays = Holiday::where('is_active', true)->get();
        $dates = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $holiday = $holidays->first(fn (Holiday $item) => $item->coversDate($cursor));

            $dates[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'holiday_name' => $holiday?->name,
            ];
        }

        return $dates;
    }
}
