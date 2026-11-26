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
    }

    public function test_report_marks_a_day_with_no_record_as_absent(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('absent', $teacherRow['statuses']['2026-12-01']);
    }

    public function test_report_marks_a_day_covered_by_an_approved_leave_as_leave(): void
    {
        TeacherLeaveApplication::create([
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'reason' => 'Sick',
            'status' => 'approved',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('leave', $teacherRow['statuses']['2026-12-01']);
    }

    public function test_report_overlays_a_holiday_instead_of_marking_absent(): void
    {
        Holiday::create([
            'type' => 'other',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'name' => 'Special Holiday',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertArrayNotHasKey('2026-12-01', $teacherRow['statuses']);
        $dateInfo = collect($response->json('data.dates'))->firstWhere('date', '2026-12-01');
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
                'date' => '2026-12-01',
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
        $this->assertSame('2026-12-01', TeacherAttendance::first()->date->toDateString());
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
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2026-12-01',
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
                'date' => '2026-12-01',
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
                'date' => '2026-12-01',
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
                'date' => '2026-12-01',
                'status' => 'not-a-real-status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
