<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePunchControllerTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private KioskDevice $deviceA;

    private string $deviceAToken = 'plain-token-a';

    private TeacherProfile $profileA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->deviceA = KioskDevice::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', $this->deviceAToken),
            'is_active' => true,
        ]);

        $teacherA = TeacherUser::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $this->profileA = TeacherProfile::create([
            'user_id' => $teacherA->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'employee_id' => 'EMP-A1',
        ]);
    }

    public function test_a_punch_with_no_token_is_rejected(): void
    {
        $this->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_punch_with_an_invalid_token_is_rejected(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => 'wrong-token'])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_punch_from_an_inactive_device_is_rejected(): void
    {
        $this->deviceA->update(['is_active' => false]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_valid_punch_creates_a_present_record_and_touches_last_used_at(): void
    {
        $this->assertNull($this->deviceA->last_used_at);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1',
                'method' => 'id_card',
                'captured_at' => '2026-12-01T08:55:00+00:00',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('attendance_punches', [
            'teacher_profile_id' => $this->profileA->id,
            'kiosk_device_id' => $this->deviceA->id,
            'method' => 'id_card',
        ]);

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profileA->id,
            'status' => 'present',
        ]);
        // The 'date' column is DATE-cast, but Eloquent's fromDateTime()
        // always serializes casts using the full datetime format, so a
        // raw assertDatabaseHas(['date' => '2026-12-01']) would never
        // match the stored '2026-12-01 00:00:00' — assert via the cast
        // attribute instead, matching the convention already used for
        // the student-attendance Attendance model's equivalent test.
        $this->assertSame('2026-12-01', TeacherAttendance::sole()->date->toDateString());

        $this->assertNotNull($this->deviceA->fresh()->last_used_at);
    }

    public function test_a_second_punch_the_same_day_sets_check_out_without_changing_check_in(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'id_card', 'captured_at' => '2026-12-01T08:55:00+00:00',
            ])->assertCreated();

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'face', 'captured_at' => '2026-12-01T16:05:00+00:00',
            ])->assertCreated();

        // whereDate() (not a plain where('date', ...)) because the
        // underlying column stores a full datetime string for this
        // DATE-cast column — see the comment in the test above.
        $attendance = TeacherAttendance::where('teacher_profile_id', $this->profileA->id)->whereDate('date', '2026-12-01')->sole();

        $this->assertSame('2026-12-01T08:55:00+00:00', $attendance->check_in_at->toIso8601String());
        $this->assertSame('2026-12-01T16:05:00+00:00', $attendance->check_out_at->toIso8601String());
        $this->assertDatabaseCount('attendance_punches', 2);
    }

    public function test_an_unrecognized_identifier_is_rejected_with_404(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'NOPE', 'method' => 'id_card'])
            ->assertNotFound();
    }

    /**
     * A device cannot resolve a teacher from a different school — the
     * lookup is always scoped by the authenticated device's own
     * school_id, never request input.
     *
     * NOTE: teacher_profiles.employee_id is a GLOBAL unique index (by
     * design — see the "Regression test" in TeacherProfileControllerTest),
     * so a literal employee_id collision across two schools can never
     * occur in this codebase and isn't something to construct here. This
     * test instead proves the same security property the brief's
     * collision scenario was after: a real identifier that belongs to a
     * *different* school is still unreachable through deviceA's token,
     * which is exactly what would break if the controller ever looked up
     * TeacherProfile by employee_id alone, without scoping by
     * $device->school_id.
     */
    public function test_a_devices_token_cannot_resolve_a_teacher_from_a_different_school(): void
    {
        $teacherB = TeacherUser::factory()->create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);
        TeacherProfile::create([
            'user_id' => $teacherB->id,
            'school_id' => $this->schoolB->id,
            'district_id' => $this->schoolB->district_id,
            'employee_id' => 'EMP-B1',
        ]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-B1', 'method' => 'id_card'])
            ->assertNotFound();
    }

    /**
     * A punch is hard evidence of actual attendance and always wins over a
     * leave record — explicit test since this is the one place a future
     * maintainer might assume leave should take precedence.
     */
    public function test_a_punch_still_records_present_even_when_the_teacher_is_on_approved_leave(): void
    {
        TeacherLeaveApplication::create([
            'teacher_id' => $this->profileA->user_id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'reason' => 'Sick',
            'status' => 'approved',
        ]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'id_card', 'captured_at' => '2026-12-01T08:55:00+00:00',
            ])->assertCreated();

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profileA->id,
            'status' => 'present',
        ]);
        $this->assertSame('2026-12-01', TeacherAttendance::sole()->date->toDateString());
    }
}
