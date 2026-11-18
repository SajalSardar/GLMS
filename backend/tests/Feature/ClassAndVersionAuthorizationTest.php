<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
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

    public function test_class_store_and_update_are_authorized(): void
    {
        $class = SchoolClass::create(['name' => 'Existing Class']);
        $payload = ['name' => 'New Class'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classes', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_class_version_store_update_and_update_subjects_are_authorized(): void
    {
        $class = SchoolClass::create(['name' => 'Class']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'school_id' => $this->school->id, 'name' => 'New Version'];

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

    /**
     * Regression test: StoreClassVersionRequest::authorize() used to
     * resolve a ClassSchool row and return false (-> 403) when the
     * (class_id, school_id) pair hadn't adopted this class, which made
     * withValidator()'s 422 check for the exact same condition
     * unreachable. authorize() now resolves the school directly, so an
     * actor who otherwise has class_versions.create and is scoped to
     * school_id sees the business-rule 422, not a 403.
     */
    public function test_class_version_store_rejects_a_class_school_pair_missing_from_class_schools(): void
    {
        $unadoptedClass = SchoolClass::create(['name' => 'Unadopted Class']);

        $allowed = $this->actorWithPermissions(['class_versions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $unadoptedClass->id,
            'school_id' => $this->school->id,
            'name' => 'New Version',
        ])->assertStatus(422);
    }

    /**
     * Regression test: ClassController::destroy() must block deleting a
     * (national) class while any school still has it adopted via
     * class_schools — deleting it now orphans every adopting school at
     * once, not just one.
     */
    public function test_class_destroy_is_blocked_while_a_class_school_assignment_exists(): void
    {
        $class = SchoolClass::create(['name' => 'Adopted Class']);
        $assignment = ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $allowed = $this->actorWithPermissions(['classes.delete']);

        $this->actingAs($allowed, 'sanctum')->deleteJson("/api/v1/classes/{$class->id}")->assertStatus(422);

        $assignment->delete();

        $this->actingAs($allowed, 'sanctum')->deleteJson("/api/v1/classes/{$class->id}")->assertNoContent();
    }
}
