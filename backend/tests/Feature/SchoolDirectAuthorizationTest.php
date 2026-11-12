<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SchoolDirectAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

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
    }

    public function test_school_store_and_update_are_authorized(): void
    {
        $payload = [
            'division_id' => $this->school->division_id,
            'district_id' => $this->school->district_id,
            'upazila_id' => $this->school->upazila_id,
            'name' => 'New School',
            'type' => 'government',
            'level' => 'secondary',
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/schools', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['schools.create', 'schools.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/schools', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_classroom_store_and_update_are_authorized(): void
    {
        $classroom = Classroom::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Room']);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Room'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classrooms', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classrooms.create', 'classrooms.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classrooms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_academic_year_store_and_update_are_authorized(): void
    {
        $year = AcademicYear::create([
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Existing Year',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Year', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/academic-years', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['academic_years.create', 'academic_years.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/academic-years', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_teacher_profile_store_and_update_are_authorized(): void
    {
        $existingTarget = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = TeacherProfile::create([
            'user_id' => $existingTarget->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $newTarget = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $payload = ['user_id' => $newTarget->id];

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['teacher_profiles.create', 'teacher_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }
}
