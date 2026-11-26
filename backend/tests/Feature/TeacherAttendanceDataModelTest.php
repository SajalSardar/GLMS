<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAttendanceDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_device_teacher_attendance_and_punch_rows_can_be_created_with_their_relations(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $teacherUser = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'school_id' => $school->id,
            'district_id' => $district->id,
            'employee_id' => 'EMP-0001',
        ]);

        $device = KioskDevice::create([
            'school_id' => $school->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', 'plain-token'),
            'is_active' => true,
        ]);

        $attendance = TeacherAttendance::create([
            'teacher_profile_id' => $profile->id,
            'school_id' => $school->id,
            'district_id' => $district->id,
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $punch = AttendancePunch::create([
            'teacher_profile_id' => $profile->id,
            'kiosk_device_id' => $device->id,
            'method' => 'id_card',
            'punched_at' => '2026-12-01 08:55:00',
        ]);

        $this->assertTrue($device->school->is($school));
        $this->assertTrue($attendance->teacherProfile->is($profile));
        $this->assertSame('2026-12-01', $attendance->date->toDateString());
        $this->assertTrue($punch->teacherProfile->is($profile));
        $this->assertTrue($punch->kioskDevice->is($device));
    }
}
