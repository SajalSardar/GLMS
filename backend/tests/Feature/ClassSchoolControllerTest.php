<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ClassSchoolControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $classSix;

    private School $schoolA;

    private School $schoolB;

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

        // school_classes still requires school_id/district_id (NOT NULL,
        // FK-constrained) at this point in the plan — unchanged by this
        // task. Which school "owns" the school_classes row is irrelevant
        // to class_schools, a separate table this test exercises.
        $this->classSix = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class Six', 'numeric_level' => 6]);
    }

    public function test_store_and_destroy_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_schools.create', 'class_schools.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $created = $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertCreated()
            ->assertJsonPath('data.class_id', $this->classSix->id)
            ->assertJsonPath('data.school_id', $this->schoolA->id);

        $classSchoolId = $created->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/class-schools/{$classSchoolId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('class_schools', ['id' => $classSchoolId]);
    }

    public function test_index_is_tenant_scoped(): void
    {
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $actor = $this->actorWithPermissions(['class_schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-schools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.school_id', $this->schoolA->id);
    }

    public function test_store_rejects_a_duplicate_assignment(): void
    {
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $allowed = $this->actorWithPermissions(['class_schools.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertUnprocessable();
    }

    public function test_destroy_is_blocked_while_a_class_version_exists_for_this_assignment(): void
    {
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassVersion::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);

        $allowed = $this->actorWithPermissions(['class_schools.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/class-schools/{$assignment->id}")
            ->assertUnprocessable();
    }
}
