<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ClassAndVersionAuthorizationTest extends TestCase
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

    public function test_class_store_update_and_update_subjects_are_authorized(): void
    {
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Class']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Class'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classes', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertOk();
    }

    public function test_class_version_store_update_and_update_subjects_are_authorized(): void
    {
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'name' => 'New Version'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/class-versions', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_versions.create', 'class_versions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertOk();
    }
}
