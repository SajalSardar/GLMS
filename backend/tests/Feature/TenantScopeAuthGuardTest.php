<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TenantScopeAuthGuardTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

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

        $class = SchoolClass::create(['name' => 'Class 6']);
        $banglaId = Version::where('name', 'Bangla')->value('id');

        $this->versionA = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => $banglaId]);
        $this->versionB = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => $banglaId]);
    }

    public function test_anonymous_request_sees_every_school_unrestricted(): void
    {
        $response = $this->getJson('/api/v1/class-versions?per_page=50')->assertOk();

        $ids = $response->json('data.*.id');
        $this->assertContains($this->versionA->id, $ids);
        $this->assertContains($this->versionB->id, $ids);
    }

    public function test_national_admin_sees_every_school_unrestricted(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $ids = $response->json('data.*.id');
        $this->assertContains($this->versionA->id, $ids);
        $this->assertContains($this->versionB->id, $ids);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_on_a_public_route(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->versionA->id);
    }

    public function test_district_scoped_actor_sees_only_their_own_district(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], null, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->versionA->id);
    }

    public function test_school_scoped_actor_cannot_findorfail_another_schools_class_version(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($actor, 'sanctum');

        $this->expectException(ModelNotFoundException::class);
        ClassVersion::findOrFail($this->versionB->id);
    }

    /**
     * Guards against the re-entrancy regression the TenantScope class's own
     * doc comment warns about: resolving Auth::guard('sanctum')->user()
     * itself queries the `users` table (via Sanctum's PersonalAccessToken
     * -> tokenable lookup), and User is itself TenantScoped — if the
     * re-entrancy guard didn't hold for the new guard() call the same way
     * it held for the old default-guard call, this would recurse until PHP's
     * memory limit is hit. A simple authenticated request against any
     * TenantScoped model completing at all (not timing out/erroring) is the
     * proof.
     */
    public function test_authenticated_request_does_not_recurse_resolving_the_actor(): void
    {
        $actor = $this->actorWithPermissions(['schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/schools')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_school_scoped_actor_sees_only_their_own_school_via_school_model(): void
    {
        $actor = $this->actorWithPermissions(['schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/schools')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->schoolA->id);
    }

    public function test_school_scoped_actor_sees_only_their_own_district_via_district_model(): void
    {
        $actor = $this->actorWithPermissions(['districts.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/districts')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->schoolA->district_id);
    }

    public function test_district_scoped_actor_sees_only_their_own_district_via_district_model(): void
    {
        $actor = $this->actorWithPermissions(['districts.view'], null, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/districts')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->schoolA->district_id);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_shifts_and_sections(): void
    {
        $shiftA = Shift::create(['class_version_id' => $this->versionA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $shiftB = Shift::create(['class_version_id' => $this->versionB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        Section::create(['shift_id' => $shiftA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'A']);
        Section::create(['shift_id' => $shiftB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'A']);

        $actor = $this->actorWithPermissions(['shifts.view', 'sections.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/shifts?per_page=50')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/sections?per_page=50')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_school_scoped_actor_sees_only_their_own_school_subject_teacher_assignments(): void
    {
        // Unlike Shift/Section, SubjectTeacherAssignment has no
        // class_version_id column — it belongs to a Section (and,
        // through that, a Teacher), both required (non-nullable) FKs —
        // see the subject_teacher_assignments migration.
        $subject = Subject::create(['name' => 'Mathematics']);

        $shiftA = Shift::create(['class_version_id' => $this->versionA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionA = Section::create(['shift_id' => $shiftA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'A']);
        $teacherA = TeacherUser::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $shiftB = Shift::create(['class_version_id' => $this->versionB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionB = Section::create(['shift_id' => $shiftB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'A']);
        $teacherB = TeacherUser::factory()->create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $sectionB->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $actor = $this->actorWithPermissions(['subject_teacher_assignments.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/subject-teacher-assignments?per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
