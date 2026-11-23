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
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->classSix = SchoolClass::create(['name' => 'Class Six', 'numeric_level' => 6]);
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

    /**
     * Regression test: StoreClassSchoolRequest::authorize() used to only
     * check can('create', ClassSchool::class) with no school resolved —
     * the only thing stopping a school-A actor from creating an
     * assignment for school B was the controller's
     * School::findOrFail($request->integer('school_id')) happening to run
     * under School's own TenantScope, which produced a confusing 404
     * rather than an intentional 403. authorize() now resolves the school
     * directly, so this is an explicit 403.
     */
    public function test_store_rejects_an_assignment_for_a_school_the_actor_is_not_scoped_to(): void
    {
        $allowed = $this->actorWithPermissions(['class_schools.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolB->id])
            ->assertForbidden();
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
        ClassVersion::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);

        $allowed = $this->actorWithPermissions(['class_schools.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/class-schools/{$assignment->id}")
            ->assertUnprocessable();
    }

    public function test_update_subjects_is_authorized_and_syncs_the_schools_offering(): void
    {
        $subjectMath = Subject::create(['name' => 'Math']);
        $subjectScience = Subject::create(['name' => 'Science']);
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignment->id}/subjects", ['subject_ids' => [$subjectMath->id]])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_schools.updateSubjects'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignment->id}/subjects", ['subject_ids' => [$subjectMath->id, $subjectScience->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.subjects');

        $this->assertDatabaseHas('class_subjects', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subjectMath->id]);
    }

    public function test_update_subjects_does_not_affect_another_schools_offering_of_the_same_class(): void
    {
        $subject = Subject::create(['name' => 'Math']);
        $assignmentA = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $allowed = $this->actorWithPermissions(['class_schools.updateSubjects'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignmentA->id}/subjects", ['subject_ids' => [$subject->id]])
            ->assertOk();

        $this->assertDatabaseCount('class_subjects', 1);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subject->id]);
    }

    /**
     * Regression test: index() used to eager-load only ['schoolClass',
     * 'school'] and never populate `subjects`, so the response always
     * reported an empty subjects list regardless of what was synced via
     * class_subjects.
     */
    public function test_index_includes_each_rows_subjects(): void
    {
        $subjectMath = Subject::create(['name' => 'Math']);
        $subjectScience = Subject::create(['name' => 'Science']);
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        DB::table('class_subjects')->insert([
            ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subjectMath->id, 'created_at' => now(), 'updated_at' => now()],
            ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subjectScience->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $actor = $this->actorWithPermissions(['class_schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-schools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonCount(2, 'data.0.subjects');

        $subjectIds = collect($response->json('data.0.subjects'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$subjectMath->id, $subjectScience->id], $subjectIds);
    }

    /**
     * Regression test: show() used to eager-load only ['schoolClass',
     * 'school'] and never populate `subjects`, so a single-row fetch
     * always reported an empty subjects list.
     */
    public function test_show_includes_the_rows_subjects(): void
    {
        $subject = Subject::create(['name' => 'Math']);
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        DB::table('class_subjects')->insert([
            'class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subject->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $actor = $this->actorWithPermissions(['class_schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/class-schools/{$assignment->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.id', $subject->id);
    }
}
