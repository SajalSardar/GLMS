<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAttendancePunchRequest;
use App\Models\AttendancePunch;
use App\Models\KioskDevice;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AttendancePunchController extends Controller
{
    /**
     * Receives one punch from a device and derives the day's attendance
     * from it. This contract only resolves against TeacherProfile today —
     * the later student-attendance sub-project adds a second lookup
     * branch (falling back to StudentProfile.student_id) and its own
     * summary table; the URL, auth mechanism, and request/response shape
     * do not need to change.
     */
    public function store(RecordAttendancePunchRequest $request): JsonResponse
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');

        $teacherProfile = TeacherProfile::where('school_id', $device->school_id)
            ->where('employee_id', $request->string('identifier')->toString())
            ->first();

        if ($teacherProfile === null) {
            abort(404, 'Unrecognized ID.');
        }

        $punchedAt = $request->filled('captured_at')
            ? Carbon::parse($request->string('captured_at')->toString())
            : now();

        AttendancePunch::create([
            'teacher_profile_id' => $teacherProfile->id,
            'kiosk_device_id' => $device->id,
            'method' => $request->string('method')->toString(),
            'punched_at' => $punchedAt,
        ]);

        $this->upsertDaySummary($teacherProfile, $punchedAt);

        return response()->json([
            'data' => [
                'name' => $teacherProfile->user?->name,
                'punched_at' => $punchedAt->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * check_in_at is the earliest punch of the day, check_out_at the
     * latest — recomputed from (existing check_in, existing check_out,
     * this new punch) rather than assuming punches arrive in order, so a
     * late-arriving earlier timestamp (e.g. a retried/delayed device
     * upload) still corrects check_in_at rather than being ignored.
     * Always writes status 'present' — a punch is hard evidence of actual
     * attendance and always wins over a leave record.
     */
    private function upsertDaySummary(TeacherProfile $teacherProfile, Carbon $punchedAt): void
    {
        $attendance = TeacherAttendance::where('teacher_profile_id', $teacherProfile->id)
            ->whereDate('date', $punchedAt->toDateString())
            ->first();

        if ($attendance === null) {
            TeacherAttendance::create([
                'teacher_profile_id' => $teacherProfile->id,
                'school_id' => $teacherProfile->school_id,
                'district_id' => $teacherProfile->district_id,
                'date' => $punchedAt->toDateString(),
                'status' => 'present',
                'check_in_at' => $punchedAt,
                'check_out_at' => null,
            ]);

            return;
        }

        $times = collect([$attendance->check_in_at, $attendance->check_out_at, $punchedAt])
            ->filter()
            ->sortBy(fn (Carbon $time) => $time->getTimestamp())
            ->values();

        $attendance->update([
            'status' => 'present',
            'check_in_at' => $times->first(),
            'check_out_at' => $times->count() > 1 ? $times->last() : null,
        ]);
    }
}
