<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\StudentProfile;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AttendanceDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Demo data for the device-attendance feature: one kiosk device at the
     * sample school (plaintext token documented below, for manually
     * POSTing a real punch to /api/v1/attendance-punches), plus a run of
     * recent school days' worth of present/absent/leave records for the
     * sample teacher (EMP-0001) and the demo students — so the Teacher
     * Attendance Report, a teacher's own "My Attendance" page, and the
     * existing student attendance report/calendar all show real data
     * instead of a blank "absent every day" grid.
     *
     * Dates are computed relative to today (never hardcoded) and skip the
     * weekly Friday/Saturday holiday (see HolidaySeeder), so this stays
     * correct whenever it's actually run — and re-run: every write looks
     * the existing row up via whereDate() first (never a raw date string
     * in an updateOrCreate() match array, which would bypass the `date`
     * cast's storage format and silently try to insert a duplicate — the
     * exact bug this feature's own fix history already hit once).
     */
    public function run(): void
    {
        $school = School::find(1);
        $teacherProfile = TeacherProfile::where('employee_id', 'EMP-0001')->first();
        // section_id/shift_id/class_version_id are all NOT NULL on
        // attendances (see its migration), so every one of the three must
        // already be set on the profile — matching
        // AttendancePunchController::upsertStudentDaySummary()'s own
        // section_id-only guard would still let a shift_id-less profile
        // (section backfilled without the Version/Shift/Section structure)
        // through and crash the insert.
        $studentProfiles = StudentProfile::where('school_id', 1)
            ->whereNotNull('section_id')
            ->whereNotNull('shift_id')
            ->whereNotNull('class_version_id')
            ->get();

        if ($school === null || $teacherProfile === null || $studentProfiles->isEmpty()) {
            return;
        }

        // Token: 'demo-front-gate-token' — shown here (not retrievable
        // from the app itself, same one-time-reveal rule real devices
        // follow) for anyone who wants to try a real punch by hand:
        // curl -X POST .../api/v1/attendance-punches \
        //   -H 'X-Kiosk-Token: demo-front-gate-token' \
        //   -d '{"identifier":"EMP-0001","method":"id_card"}'
        $device = KioskDevice::where('school_id', $school->id)->where('name', 'Front Gate (Demo)')->first();

        if ($device === null) {
            KioskDevice::create([
                'school_id' => $school->id,
                'name' => 'Front Gate (Demo)',
                'token' => hash('sha256', 'demo-front-gate-token'),
                'is_active' => true,
            ]);
        }

        $schoolDays = $this->lastSchoolDays(6);

        foreach ($schoolDays as $index => $date) {
            $teacherStatus = match (true) {
                $index === 1 => 'absent',
                $index === 3 => 'leave',
                default => 'present',
            };

            $this->upsertTeacherAttendance($teacherProfile, $date, [
                'school_id' => $teacherProfile->school_id,
                'district_id' => $teacherProfile->district_id,
                'status' => $teacherStatus,
                'check_in_at' => $teacherStatus === 'present' ? $date->copy()->setTime(8, 52) : null,
                'check_out_at' => $teacherStatus === 'present' ? $date->copy()->setTime(16, 5) : null,
            ]);

            // Scattered, not the same day for every student — each
            // student gets exactly one absence and one leave somewhere
            // across the window, offset by their position in the roster.
            foreach ($studentProfiles as $studentIndex => $profile) {
                $status = match (($studentIndex + $index) % 7) {
                    0 => 'absent',
                    3 => 'leave',
                    default => 'present',
                };

                $this->upsertStudentAttendance($profile, $date, $status);
            }
        }
    }

    /**
     * @return list<Carbon>
     */
    private function lastSchoolDays(int $count): array
    {
        $days = [];
        $cursor = Carbon::yesterday();

        while (count($days) < $count) {
            if (! in_array($cursor->format('l'), ['Friday', 'Saturday'], true)) {
                $days[] = $cursor->copy();
            }
            $cursor->subDay();
        }

        return array_reverse($days);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertTeacherAttendance(TeacherProfile $teacherProfile, Carbon $date, array $attributes): void
    {
        $existing = TeacherAttendance::where('teacher_profile_id', $teacherProfile->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            TeacherAttendance::create([
                ...$attributes,
                'teacher_profile_id' => $teacherProfile->id,
                'date' => $date->toDateString(),
            ]);
        }
    }

    private function upsertStudentAttendance(StudentProfile $profile, Carbon $date, string $status): void
    {
        $existing = Attendance::where('student_profile_id', $profile->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        $attributes = [
            'school_id' => $profile->school_id,
            'district_id' => $profile->district_id,
            'class_id' => $profile->class_id,
            'section_id' => $profile->section_id,
            'shift_id' => $profile->shift_id,
            'class_version_id' => $profile->class_version_id,
            'status' => $status,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            Attendance::create([
                ...$attributes,
                'student_profile_id' => $profile->id,
                'date' => $date->toDateString(),
            ]);
        }
    }
}
