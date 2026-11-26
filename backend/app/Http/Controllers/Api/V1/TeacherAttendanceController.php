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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        $profiles = TeacherProfile::where('school_id', $school->id)->with('user')->get();

        $attendanceByTeacher = TeacherAttendance::where('school_id', $school->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('teacher_profile_id');

        $teacherRows = $profiles->map(function (TeacherProfile $profile) use ($start, $end, $attendanceByTeacher) {
            $own = $attendanceByTeacher->get($profile->id, collect())->keyBy(fn (TeacherAttendance $row) => $row->date->toDateString());
            $statuses = [];

            for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                $dateString = $cursor->toDateString();
                $existing = $own->get($dateString);

                if ($existing !== null) {
                    $statuses[$dateString] = $existing->status;

                    continue;
                }

                if (Holiday::isHoliday($cursor)) {
                    continue;
                }

                $statuses[$dateString] = $this->onApprovedLeave($profile->user_id, $cursor) ? 'leave' : 'absent';
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
            // exists at all.
            $status = $record?->status;

            if ($status === null && $holidayName === null) {
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

        $attributes = [
            'school_id' => $profile->school_id,
            'district_id' => $profile->district_id,
            'status' => $request->string('status')->toString(),
            'corrected_by_id' => $request->user()->id,
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
