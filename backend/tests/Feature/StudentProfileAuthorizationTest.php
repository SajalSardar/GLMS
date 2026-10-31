<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentProfileAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
        ]);
    }

    public function test_store_update_attach_guardian_and_transfer_section_are_authorized(): void
    {
        $existingTargetUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = StudentProfile::create([
            'user_id' => $existingTargetUser->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
        ]);

        $newTargetUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['name' => 'Guardian', 'phone' => '01700000001']);

        $storePayload = ['user_id' => $newTargetUser->id];
        $attachPayload = ['guardian_id' => $guardian->id, 'relation' => 'father'];
        $transferPayload = ['to_section_id' => $this->section->id];

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/transfer-section", $transferPayload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['student_profiles.create', 'student_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertOk();
    }
}
