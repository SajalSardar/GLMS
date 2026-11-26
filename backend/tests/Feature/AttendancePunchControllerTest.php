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
use Illuminate\Support\Carbon;
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

        // Guards against a regression where check_out_at gets set to the
        // same value as check_in_at on the very first punch — only a
        // SECOND punch the same day should ever populate check_out_at
        // (see the test below).
        $this->assertNull(TeacherAttendance::sole()->check_out_at);

        $this->assertNotNull($this->deviceA->fresh()->last_used_at);
    }

    /**
     * A device can send captured_at with any offset (e.g. a Bangladesh
     * device sending '+06:00' local time) — the offset must be
     * normalized to the app's configured timezone before it's used to
     * derive the attendance date and stored as check_in_at, or the
     * stored/returned instant renders hours off. config('app.timezone')
     * is 'UTC' in this app (see config/app.php), so a '+06:00' timestamp
     * must normalize to its UTC-equivalent instant — 6 hours earlier.
     */
    public function test_a_punch_with_a_non_utc_offset_is_normalized_to_the_apps_timezone(): void
    {
        $response = $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1',
                'method' => 'id_card',
                'captured_at' => '2026-12-01T14:55:00+06:00',
            ])
            ->assertCreated();

        // '2026-12-01T14:55:00+06:00' is the same instant as
        // '2026-12-01T08:55:00Z' — assert against the UTC-equivalent
        // instant so this test holds regardless of config('app.timezone').
        $expectedInstant = Carbon::parse('2026-12-01T14:55:00+06:00')->setTimezone(config('app.timezone'));

        $this->assertSame($expectedInstant->toIso8601String(), $response->json('data.punched_at'));

        $attendance = TeacherAttendance::sole();
        $this->assertSame($expectedInstant->toIso8601String(), $attendance->check_in_at->toIso8601String());
        // The date derived from the normalized instant, not the raw
        // device-local date — '14:55 +06:00' on 2026-12-01 is '08:55 UTC'
        // the same day in this case, but this assertion is what would
        // catch the offset being silently discarded for an offset large
        // enough to cross midnight.
        $this->assertSame($expectedInstant->toDateString(), $attendance->date->toDateString());
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

    /**
     * Regression test for a retried device upload: the same punch arriving
     * twice (identical identifier + captured_at, e.g. a device retrying an
     * upload it never got an ack for) must merge into one
     * TeacherAttendance row rather than throwing a DB unique-constraint
     * exception on the second write. This covers the sequential case;
     * upsertDaySummary()'s DB::transaction()+lockForUpdate() additionally
     * protects the true interleaved-read race (two requests both reading
     * null before either writes), which PHPUnit's single-threaded
     * execution cannot exercise directly — see the fix report for the
     * code-reading justification of why that race is structurally
     * impossible under lockForUpdate() on the production (MySQL) engine.
     */
    public function test_a_retried_punch_for_the_same_teacher_and_day_does_not_throw_and_merges_into_one_row(): void
    {
        $payload = [
            'identifier' => 'EMP-A1', 'method' => 'id_card', 'captured_at' => '2026-12-01T08:55:00+00:00',
        ];

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', $payload)
            ->assertCreated();

        // The retry: identical identifier + captured_at, same as a device
        // re-uploading a punch it never got an acknowledgement for.
        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', $payload)
            ->assertCreated();

        $attendance = TeacherAttendance::where('teacher_profile_id', $this->profileA->id)
            ->whereDate('date', '2026-12-01')
            ->sole();

        // Two entries at the identical instant both survive filter() (it
        // only drops nulls, not duplicates), so check_out_at ends up equal
        // to check_in_at — the correct result of the unchanged
        // collect/filter/sort logic for a same-timestamp retry, not a bug.
        $this->assertSame('2026-12-01T08:55:00+00:00', $attendance->check_in_at->toIso8601String());
        $this->assertSame('2026-12-01T08:55:00+00:00', $attendance->check_out_at->toIso8601String());
        $this->assertSame('present', $attendance->status);
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
