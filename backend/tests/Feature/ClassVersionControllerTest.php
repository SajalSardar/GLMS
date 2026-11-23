<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ClassVersionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    protected School $school;

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

    public function test_store_rejects_an_unknown_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $allowed = $this->actorWithPermissions(['class_versions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'version_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_store_rejects_an_inactive_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $inactive = Version::where('name', 'English')->first();
        $inactive->update(['is_active' => false]);

        $allowed = $this->actorWithPermissions(['class_versions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'version_id' => $inactive->id,
        ])->assertStatus(422);
    }

    public function test_update_rejects_an_unknown_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $bangla = Version::where('name', 'Bangla')->first();
        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'version_id' => $bangla->id,
            'is_active' => true,
        ]);

        $allowed = $this->actorWithPermissions(['class_versions.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$classVersion->id}", [
            'version_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_update_rejects_an_inactive_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $bangla = Version::where('name', 'Bangla')->first();
        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'version_id' => $bangla->id,
            'is_active' => true,
        ]);
        $inactive = Version::where('name', 'English')->first();
        $inactive->update(['is_active' => false]);

        $allowed = $this->actorWithPermissions(['class_versions.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$classVersion->id}", [
            'version_id' => $inactive->id,
        ])->assertStatus(422);
    }
}
