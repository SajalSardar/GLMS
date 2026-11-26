<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Holiday;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherAttendanceControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private School $schoolB;

    private TeacherUser $teacher;

    private TeacherProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $district->id]);
        $this->profile = TeacherProfile::create([
            'user_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $district->id,
            'employee_id' => 'EMP-0001',
        ]);

        // A second school, mirroring KioskDeviceControllerTest's schoolA/
        // schoolB setup — used by the cross-tenant isolation tests below.
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);
    }

    public function test_report_marks_a_day_with_no_record_as_absent(): void
    {
        // A genuinely past month relative to any real run — avoids the
        // future-date clamp report() now applies (a date after today is
        // left out of `statuses` entirely rather than synthesized).
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2020&month=1")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('absent', $teacherRow['statuses']['2020-01-01']);
    }

    public function test_report_leaves_a_future_date_out_of_statuses_entirely(): void
    {
        $futureYear = (int) now()->addYear()->format('Y');

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year={$futureYear}&month=1")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertArrayNotHasKey("{$futureYear}-01-01", $teacherRow['statuses']);
    }

    public function test_report_leaves_today_out_of_statuses_when_no_row_exists(): void
    {
        // Today must not be synthesized as absent/leave before the day is
        // over — a teacher who hasn't punched in yet this morning
        // shouldn't be marked 'absent' while the day is still in
        // progress. Only a date strictly before today is eligible for
        // synthesis (see report()'s cursor skip condition: gte, not gt).
        $today = now();
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year={$today->year}&month={$today->month}")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertArrayNotHasKey($today->toDateString(), $teacherRow['statuses']);
    }

    public function test_report_returns_a_stored_rows_actual_status_for_today(): void
    {
        // report()'s actual primary happy path — a real stored row always
        // wins and is returned as-is, even for today, where synthesis is
        // otherwise skipped entirely (see the test above). None of the
        // other report() tests ever create a real TeacherAttendance row.
        $today = now();

        TeacherAttendance::create([
            'teacher_profile_id' => $this->profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'date' => $today->toDateString(),
            'status' => 'present',
            'check_in_at' => $today->copy()->setTime(8, 55),
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year={$today->year}&month={$today->month}")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('present', $teacherRow['statuses'][$today->toDateString()]);
    }

    public function test_mine_reports_today_as_null_status_when_no_row_exists(): void
    {
        // Same invariant as report()'s equivalent test above, from the
        // teacher's own "My Attendance" view: today must not be
        // synthesized as absent/leave before the day is over.
        $today = now();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/mine?year={$today->year}&month={$today->month}")
            ->assertOk();

        $day = collect($response->json('data.days'))->firstWhere('date', $today->toDateString());
        $this->assertNull($day['status']);
    }

    public function test_mine_reports_a_future_date_as_null_status_and_excludes_it_from_the_summary(): void
    {
        $futureYear = (int) now()->addYear()->format('Y');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/mine?year={$futureYear}&month=1")
            ->assertOk();

        $day = collect($response->json('data.days'))->firstWhere('date', "{$futureYear}-01-01");
        $this->assertNull($day['status']);
        $this->assertSame(0, $response->json('data.summary.present'));
        $this->assertSame(0, $response->json('data.summary.absent'));
        $this->assertSame(0, $response->json('data.summary.leave'));
    }

    public function test_report_marks_a_day_covered_by_an_approved_leave_as_leave(): void
    {
        // A genuinely past month — relies on the synthesis path (no stored
        // row), which only runs for dates on or before today.
        TeacherLeaveApplication::create([
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => '2020-01-01',
            'to_date' => '2020-01-02',
            'reason' => 'Sick',
            'status' => 'approved',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2020&month=1")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('leave', $teacherRow['statuses']['2020-01-01']);
    }

    public function test_report_overlays_a_holiday_instead_of_marking_absent(): void
    {
        // A genuinely past date — the future-date clamp alone would also
        // leave the key out of `statuses`, which would mask this test's
        // actual intent (the holiday overlay, not the future-date clamp).
        Holiday::create([
            'type' => 'other',
            'start_date' => '2020-01-01',
            'end_date' => '2020-01-01',
            'name' => 'Special Holiday',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2020&month=1")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertArrayNotHasKey('2020-01-01', $teacherRow['statuses']);
        $dateInfo = collect($response->json('data.dates'))->firstWhere('date', '2020-01-01');
        $this->assertSame('Special Holiday', $dateInfo['holiday_name']);
    }

    public function test_report_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertForbidden();
    }

    public function test_mine_returns_the_teachers_own_record_with_check_in_and_out_times(): void
    {
        TeacherAttendance::create([
            'teacher_profile_id' => $this->profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/teacher-attendances/mine?year=2026&month=12')
            ->assertOk();

        $day = collect($response->json('data.days'))->firstWhere('date', '2026-12-01');
        $this->assertSame('present', $day['status']);
        $this->assertNotNull($day['check_in_at']);
        $this->assertSame(1, $response->json('data.summary.present'));
    }

    public function test_mine_shows_a_stored_rows_real_status_even_on_a_holiday_date(): void
    {
        // A stored row always wins over a holiday — same invariant report()
        // already enforces. holiday_name should still be reported alongside
        // the real status, not null it out.
        Holiday::create([
            'type' => 'other',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'name' => 'Special Holiday',
        ]);

        TeacherAttendance::create([
            'teacher_profile_id' => $this->profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/teacher-attendances/mine?year=2026&month=12')
            ->assertOk();

        $day = collect($response->json('data.days'))->firstWhere('date', '2026-12-01');
        $this->assertSame('present', $day['status']);
        $this->assertSame('Special Holiday', $day['holiday_name']);
        $this->assertSame(1, $response->json('data.summary.present'));
    }

    public function test_mine_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/teacher-attendances/mine')
            ->assertForbidden();
    }

    public function test_school_admin_can_manually_correct_a_days_status(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2025-12-01',
                'status' => 'present',
                'correction_note' => 'Device was offline',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        // 'date' is cast as 'date' but still serializes to storage as
        // 'Y-m-d H:i:s' (not a bare 'Y-m-d' string) — a known quirk of this
        // codebase's 'date' cast (see AttendanceControllerTest and
        // AttendancePunchController). Assert the date via the model's own
        // ->toDateString() rather than a raw assertDatabaseHas() value.
        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profile->id,
            'status' => 'present',
            'corrected_by_id' => $schoolAdmin->id,
            'correction_note' => 'Device was offline',
        ]);
        $this->assertSame('2025-12-01', TeacherAttendance::first()->date->toDateString());
    }

    public function test_correcting_a_day_that_already_has_a_row_updates_it_in_place(): void
    {
        // Simulates a day already recorded by a device punch (or an earlier
        // correction), then corrected again by an admin. Must update the
        // existing row rather than attempt a second insert and collide with
        // the (teacher_profile_id, date) unique index.
        TeacherAttendance::create([
            'teacher_profile_id' => $this->profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'date' => '2025-12-01',
            'status' => 'present',
            'check_in_at' => '2025-12-01 08:55:00',
            'check_out_at' => '2025-12-01 16:05:00',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2025-12-01',
                'status' => 'leave',
                'correction_note' => 'Device mis-logged this as present',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'leave');

        $this->assertSame(1, TeacherAttendance::count());
        $updated = TeacherAttendance::first();
        $this->assertSame('leave', $updated->status);
        $this->assertSame($schoolAdmin->id, $updated->corrected_by_id);
        // The device's own check-in/out timestamps are left untouched by a
        // status correction.
        $this->assertNotNull($updated->check_in_at);

        // Correcting the SAME day a second time must still update in place,
        // not throw a unique-constraint violation.
        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2025-12-01',
                'status' => 'present',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertSame(1, TeacherAttendance::count());
    }

    public function test_correct_is_forbidden_without_the_update_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2025-12-01',
                'status' => 'present',
            ])
            ->assertForbidden();
    }

    public function test_correct_rejects_an_invalid_status(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2025-12-01',
                'status' => 'not-a-real-status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_correct_rejects_a_future_date(): void
    {
        // Backend-side enforcement of the "never synthesize or allow a
        // future status" invariant, independent of whatever the frontend
        // grid allows — a future-dated correction must be rejected with a
        // 422 regardless.
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);
        $futureDate = now()->addDay()->toDateString();

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => $futureDate,
                'status' => 'present',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_school_admin_cannot_report_another_schools_attendance(): void
    {
        // TenantScope on School hides schoolB's row from
        // School::findOrFail() for an actor scoped to school A — proving
        // this protection stays in place rather than only working because
        // nothing has removed it yet.
        $schoolAdminA = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->schoolB->id}&year=2020&month=1")
            ->assertNotFound();
    }

    public function test_school_admin_cannot_correct_another_schools_teacher_attendance(): void
    {
        // TenantScope on TeacherProfile hides schoolB's profile from
        // TeacherProfile::findOrFail() for an actor scoped to school A.
        $teacherB = TeacherUser::factory()->create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);
        $profileB = TeacherProfile::create([
            'user_id' => $teacherB->id,
            'school_id' => $this->schoolB->id,
            'district_id' => $this->schoolB->district_id,
            'employee_id' => 'EMP-0002',
        ]);

        $schoolAdminA = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $profileB->id,
                'date' => '2025-12-01',
                'status' => 'present',
            ])
            ->assertNotFound();
    }
}
