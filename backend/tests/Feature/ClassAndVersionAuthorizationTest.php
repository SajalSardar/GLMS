<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassAndVersionAuthorizationTest extends TestCase
{
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

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
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
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertOk();
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
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertOk();
    }
}
