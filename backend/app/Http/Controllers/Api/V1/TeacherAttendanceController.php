<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CorrectTeacherAttendanceRequest;
use App\Models\Holiday;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TeacherAttendanceController extends Controller
{
    /**
     * Monthly attendance grid for every teacher at a school — mirrors
     * AttendanceController::report()'s shape for students. A day with no
     * stored row is synthesized as leave/absent here (never pre-populated
     * in the database); a holiday day is left out of `statuses` entirely
     * and the caller reads the holiday name from `dates` instead, exactly
     * like the student report already does.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TeacherAttendance::class);

        $school = School::findOrFail($request->integer('school_id'));

        [$year, $month, $start, $end] = $this->resolveMonth($request);
        $dates = $this->dateRange($start, $end);
        $today = Carbon::today();

        // Precomputed once from $dates (which already evaluated every
        // active Holiday row against every date in range) rather than
        // re-querying Holiday::isHoliday() per teacher per day below.
        $holidayDates = collect($dates)
            ->filter(fn (array $date) => $date['holiday_name'] !== null)
            ->pluck('date')
            ->flip();

        $profiles = TeacherProfile::where('school_id', $school->id)->with('user')->get();

        $attendanceByTeacher = TeacherAttendance::where('school_id', $school->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('teacher_profile_id');

        // One query for every teacher's approved leave ranges overlapping
        // the requested month, instead of an onApprovedLeave() existence
        // query per teacher per day below.
        $approvedLeavesByTeacher = TeacherLeaveApplication::query()
            ->whereIn('teacher_id', $profiles->pluck('user_id'))
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $end)
            ->whereDate('to_date', '>=', $start)
            ->get()
            ->groupBy('teacher_id');

        $teacherRows = $profiles->map(function (TeacherProfile $profile) use ($start, $end, $today, $attendanceByTeacher, $holidayDates, $approvedLeavesByTeacher) {
            $own = $attendanceByTeacher->get($profile->id, collect())->keyBy(fn (TeacherAttendance $row) => $row->date->toDateString());
            $leaves = $approvedLeavesByTeacher->get($profile->user_id, collect());
            $statuses = [];

            for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                $dateString = $cursor->toDateString();
                $existing = $own->get($dateString);

                if ($existing !== null) {
                    $statuses[$dateString] = $existing->status;

                    continue;
                }

                // Today or a date after today has no status to synthesize
                // yet — status is only computed lazily for a date strictly
                // before today with no row (see this method's own doc
                // comment); leave it out of `statuses` entirely, same as a
                // holiday. Today is deliberately excluded here (not just
                // future dates) so a teacher who hasn't punched in yet this
                // morning isn't marked 'absent' before the day is over.
                if ($cursor->gte($today)) {
                    continue;
                }

                if ($holidayDates->has($dateString)) {
                    continue;
                }

                $onLeave = $leaves->contains(
                    fn (TeacherLeaveApplication $leave) => $leave->from_date->lte($cursor) && $leave->to_date->gte($cursor)
                );

                $statuses[$dateString] = $onLeave ? 'leave' : 'absent';
            }

            return [
                'teacher_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'employee_id' => $profile->employee_id,
                'statuses' => $statuses,
            ];
        })->values();

        return response()->json([
            'data' => [
                'school' => ['id' => $school->id, 'name' => $school->name, 'address' => $school->address],
                'year' => $year,
                'month' => $month,
                'dates' => $dates,
                'teachers' => $teacherRows,
            ],
        ]);
    }

    /**
     * This teacher's own monthly record, including check-in/out times —
     * the source data for the teacher portal's "My Attendance" page.
     * Self-service bypass, same convention as AttendanceController::mine()
     * for students — no permission check, just an instanceof guard.
     */
    public function mine(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $profile = $teacher->profile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        [$year, $month, $start, $end] = $this->resolveMonth($request);

        $records = TeacherAttendance::where('teacher_profile_id', $profile->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (TeacherAttendance $row) => $row->date->toDateString());

        $holidays = Holiday::where('is_active', true)->get();
        $today = Carbon::today();

        $days = [];
        $summary = ['present' => 0, 'absent' => 0, 'leave' => 0];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dateString = $cursor->toDateString();
            $record = $records->get($dateString);
            $holidayName = $holidays->first(fn (Holiday $holiday) => $holiday->coversDate($cursor))?->name;

            // A stored row always wins over a holiday — a real punch or
            // correction is hard evidence of what actually happened that
            // day, same invariant report() already enforces. holiday_name
            // being non-null never hides a row's own status; it only
            // supplies the synthesized status (leave/absent) when no row
            // exists at all. Today or a date after today has no status to
            // synthesize yet — it stays null and is excluded from the
            // summary below, same as report() leaves it out of `statuses`.
            // Today is deliberately excluded here (strictly-before, not
            // on-or-before) so a teacher who hasn't punched in yet this
            // morning isn't marked 'absent' before the day is over.
            $status = $record?->status;

            if ($status === null && $holidayName === null && $cursor->lt($today)) {
                $status = $this->onApprovedLeave($teacher->id, $cursor) ? 'leave' : 'absent';
            }

            if ($status !== null) {
                $summary[$status]++;
            }

            $days[] = [
                'date' => $dateString,
                'status' => $status,
                'check_in_at' => $record?->check_in_at?->toIso8601String(),
                'check_out_at' => $record?->check_out_at?->toIso8601String(),
                'holiday_name' => $holidayName,
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
     * Manual correction by school_admin/national_admin — device was down,
     * missed punch, etc. Overwrites (or creates) the day's final status;
     * check_in_at/check_out_at are left untouched (a correction is about
     * the day's status, not fabricating device timestamps).
     *
     * Looked up via whereDate() rather than passed through updateOrCreate()'s
     * own match array — same hazard, same fix, as
     * AttendanceController::record(): `date` is cast as 'date' but the value
     * actually stored went through that cast's set-mutator and serializes as
     * 'Y-m-d H:i:s', so a raw date string in updateOrCreate()'s match array
     * never equals the stored value, never finds the existing row, and
     * collides with the (teacher_profile_id, date) unique index on create().
     */
    public function correct(CorrectTeacherAttendanceRequest $request): JsonResponse
    {
        $profile = TeacherProfile::findOrFail($request->integer('teacher_profile_id'));
        $date = $request->string('date')->toString();

        // teacher_attendances.school_id/district_id are NOT NULL, but
        // teacher_profiles.school_id/district_id are nullable — without
        // this guard a profile with no school assigned yet would 500 on
        // the NOT NULL constraint violation below instead of a clean 422.
        if ($profile->school_id === null) {
            throw ValidationException::withMessages([
                'teacher_profile_id' => ['This teacher has no school assigned and cannot have attendance corrected.'],
            ]);
        }

        $attributes = [
            'school_id' => $profile->school_id,
            'district_id' => $profile->district_id,
            'status' => $request->string('status')->toString(),
            // corrected_by_id has no FK constraint (see the migration's
            // own doc comment) — the actor stamping a correction is
            // always a staff User in practice (teacher_attendances.update
            // is only ever granted to national_admin/school_admin roles
            // today), but this guard keeps the column defensively
            // nullable against a future role reassignment rather than
            // assuming $request->user() is always a User instance.
            'corrected_by_id' => $request->user() instanceof User ? $request->user()->id : null,
            'correction_note' => $request->string('correction_note')->toString() ?: null,
        ];

        $existing = TeacherAttendance::where('teacher_profile_id', $profile->id)
            ->whereDate('date', $date)
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);
            $attendance = $existing;
        } else {
            $attendance = TeacherAttendance::create([
                ...$attributes,
                'teacher_profile_id' => $profile->id,
                'date' => $date,
            ]);
        }

        return response()->json(['data' => [
            'teacher_profile_id' => $attendance->teacher_profile_id,
            'date' => $attendance->date->toDateString(),
            'status' => $attendance->status,
        ]]);
    }

    private function onApprovedLeave(int $teacherUserId, Carbon $date): bool
    {
        return TeacherLeaveApplication::query()
            ->where('teacher_id', $teacherUserId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
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
