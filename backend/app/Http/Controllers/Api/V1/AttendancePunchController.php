<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAttendancePunchRequest;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Holiday;
use App\Models\KioskDevice;
use App\Models\StudentAttendancePunch;
use App\Models\StudentProfile;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendancePunchController extends Controller
{
    /**
     * Receives one punch from a device and derives the day's attendance
     * from it. The identifier is tried against TeacherProfile.employee_id
     * first, then StudentProfile.student_id — both scoped by the
     * authenticated device's own school_id, never request input. Each
     * person type has its own raw punch log (AttendancePunch /
     * StudentAttendancePunch) and writes into its own summary table
     * (teacher_attendances / the existing attendances table), matching
     * this codebase's established convention of separate teacher/student
     * domain tables rather than a shared polymorphic one.
     */
    public function store(RecordAttendancePunchRequest $request): JsonResponse
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');
        $identifier = $request->string('identifier')->toString();
        $method = $request->string('method')->toString();

        // Normalize to the app's configured timezone — a device-sent
        // offset (e.g. '+06:00') is preserved by Carbon::parse() as-is,
        // but everything downstream (the derived attendance date,
        // check_in_at/check_out_at) must agree on one timezone or a
        // non-UTC offset silently renders hours off.
        $punchedAt = $request->filled('captured_at')
            ? Carbon::parse($request->string('captured_at')->toString())->setTimezone(config('app.timezone'))
            : now();

        $teacherProfile = TeacherProfile::where('school_id', $device->school_id)
            ->where('employee_id', $identifier)
            ->first();

        if ($teacherProfile !== null) {
            DB::transaction(function () use ($teacherProfile, $device, $method, $punchedAt): void {
                AttendancePunch::create([
                    'teacher_profile_id' => $teacherProfile->id,
                    'kiosk_device_id' => $device->id,
                    'method' => $method,
                    'punched_at' => $punchedAt,
                ]);

                $this->upsertTeacherDaySummary($teacherProfile, $punchedAt);
            });

            return response()->json([
                'data' => [
                    'name' => $teacherProfile->user?->name,
                    'punched_at' => $punchedAt->toIso8601String(),
                ],
            ], 201);
        }

        $studentProfile = StudentProfile::where('school_id', $device->school_id)
            ->where('student_id', $identifier)
            ->first();

        if ($studentProfile !== null) {
            DB::transaction(function () use ($studentProfile, $device, $method, $punchedAt): void {
                StudentAttendancePunch::create([
                    'student_profile_id' => $studentProfile->id,
                    'kiosk_device_id' => $device->id,
                    'method' => $method,
                    'punched_at' => $punchedAt,
                ]);

                $this->upsertStudentDaySummary($studentProfile, $punchedAt);
            });

            return response()->json([
                'data' => [
                    'name' => $studentProfile->user?->name,
                    'punched_at' => $punchedAt->toIso8601String(),
                ],
            ], 201);
        }

        abort(404, 'Unrecognized ID.');
    }

    /**
     * check_in_at is the earliest punch of the day, check_out_at the
     * latest — recomputed from (existing check_in, existing check_out,
     * this new punch) rather than assuming punches arrive in order, so a
     * late-arriving earlier timestamp (e.g. a retried/delayed device
     * upload) still corrects check_in_at rather than being ignored.
     * Always writes status 'present' — a punch is hard evidence of actual
     * attendance and always wins over a leave record.
     *
     * Wrapped in a transaction with a locking read: two near-simultaneous
     * punches for the same teacher+date (e.g. a retried device upload
     * arriving twice) must not both see the read-then-write's first()
     * return null and both attempt create(), which would otherwise throw
     * on the teacher_attendances (teacher_profile_id, date) unique
     * constraint instead of merging into one row. lockForUpdate() forces
     * the second transaction to wait for the first to commit, then it
     * re-reads and sees the row the first transaction just created.
     */
    private function upsertTeacherDaySummary(TeacherProfile $teacherProfile, Carbon $punchedAt): void
    {
        DB::transaction(function () use ($teacherProfile, $punchedAt): void {
            $attendance = TeacherAttendance::where('teacher_profile_id', $teacherProfile->id)
                ->whereDate('date', $punchedAt->toDateString())
                ->lockForUpdate()
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
        });
    }

    /**
     * Writes directly into the pre-existing `attendances` table a teacher
     * would otherwise fill in by hand on the roster — status only, no
     * check-in/out times (this feature never asked for a time clock on
     * students, just a daily present/absent/leave status). Always writes
     * 'present' and clears any prior marked_by_id, for the same reason the
     * teacher side always wins: a punch is hard evidence of actual
     * attendance.
     *
     * Two things intentionally produce no Attendance row (the raw punch
     * above still recorded either way, so there is always an audit trail):
     * a holiday date — mirrors AttendanceController::record()'s own rule
     * that a holiday is never recorded for; and a student with no
     * section_id — attendances.section_id/class_id/shift_id/
     * class_version_id are all NOT NULL, and a school that hasn't adopted
     * the Version/Shift/Section structure leaves those null on the
     * profile, so there is nothing valid to attribute the day to.
     */
    private function upsertStudentDaySummary(StudentProfile $studentProfile, Carbon $punchedAt): void
    {
        if ($studentProfile->section_id === null) {
            return;
        }

        if (Holiday::isHoliday($punchedAt)) {
            return;
        }

        DB::transaction(function () use ($studentProfile, $punchedAt): void {
            $existing = Attendance::where('student_profile_id', $studentProfile->id)
                ->whereDate('date', $punchedAt->toDateString())
                ->lockForUpdate()
                ->first();

            $attributes = [
                'school_id' => $studentProfile->school_id,
                'district_id' => $studentProfile->district_id,
                'class_id' => $studentProfile->class_id,
                'section_id' => $studentProfile->section_id,
                'shift_id' => $studentProfile->shift_id,
                'class_version_id' => $studentProfile->class_version_id,
                'academic_year_id' => $studentProfile->shift?->academic_year_id,
                'status' => 'present',
                'marked_by_id' => null,
            ];

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                Attendance::create([
                    ...$attributes,
                    'student_profile_id' => $studentProfile->id,
                    'date' => $punchedAt->toDateString(),
                ]);
            }
        });
    }
}
