<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_student_user_store_and_update_are_authorized(): void
    {
        $target = StudentUser::factory()->create();
        $payload = ['name' => 'New Student', 'password' => 'pin1'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/student-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-users/{$target->id}", [])->assertOk();
    }

    public function test_school_scoped_actor_cannot_create_student_in_different_school(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $actorScopedToA = $this->actorWithPermissions(['users.create'], $schoolA->id, $district->id);

        $payload = ['name' => 'New Student', 'password' => 'pin1', 'school_id' => $schoolB->id];

        $this->actingAs($actorScopedToA, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertUnprocessable();
    }

    public function test_district_scoped_actor_cannot_bypass_district_with_out_of_district_school(): void
    {
        $division1 = Division::create(['name' => 'Division 1', 'bn_name' => 'বিভাগ ১']);
        $district1 = District::create(['division_id' => $division1->id, 'name' => 'District A', 'bn_name' => 'জেলা ক']);
        $upazila1 = Upazila::create(['district_id' => $district1->id, 'name' => 'Upazila 1', 'bn_name' => 'উপজেলা ১']);

        $division2 = Division::create(['name' => 'Division 2', 'bn_name' => 'বিভাগ ২']);
        $district2 = District::create(['division_id' => $division2->id, 'name' => 'District B', 'bn_name' => 'জেলা খ']);
        $upazila2 = Upazila::create(['district_id' => $district2->id, 'name' => 'Upazila 2', 'bn_name' => 'উপজেলা ২']);

        $schoolInDistrictB = School::create([
            'division_id' => $division2->id,
            'district_id' => $district2->id,
            'upazila_id' => $upazila2->id,
            'name' => 'School in District B',
        ]);

        $actorScopedToDistrictA = $this->actorWithPermissions(['users.create'], null, $district1->id);

        $payload = [
            'name' => 'New Student',
            'password' => 'pin1',
            'school_id' => $schoolInDistrictB->id,
        ];

        $this->actingAs($actorScopedToDistrictA, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertUnprocessable();
    }

    public function test_student_user_store_resolves_district_from_school_for_unscoped_actor(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $unscopedActor = $this->actorWithPermissions(['users.create']);

        $payload = [
            'name' => 'New Student',
            'email' => 'new-student@example.test',
            'password' => 'pin1',
            'school_id' => $school->id,
        ];

        $response = $this->actingAs($unscopedActor, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();

        $response->assertJsonPath('data.district.id', $district->id);
        $this->assertDatabaseHas('student_users', [
            'email' => 'new-student@example.test',
            'school_id' => $school->id,
            'district_id' => $district->id,
        ]);
    }

    public function test_recreating_a_soft_deleted_student_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $payload = ['name' => 'New Student', 'email' => 'new-student@example.test', 'password' => 'pin1'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();
        $studentId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/student-users/{$studentId}")->assertNoContent();

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();

        $this->assertDatabaseHas('student_users', [
            'id' => $studentId,
            'email' => 'new-student@example.test',
            'deleted_at' => null,
        ]);
    }

    public function test_recreating_a_soft_deleted_student_matched_by_phone_restores_it_in_place(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'First Student', 'email' => 'first-student@example.test', 'phone' => '01913000000', 'password' => 'pin1'];

        $created = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $firstPayload)->assertCreated();
        $studentId = $created->json('data.id');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/student-users/{$studentId}")->assertNoContent();

        $secondPayload = ['name' => 'Second Student', 'email' => 'second-student@example.test', 'phone' => '01913000000', 'password' => 'pin1'];

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $secondPayload)->assertCreated();

        $this->assertDatabaseHas('student_users', [
            'id' => $studentId,
            'email' => 'second-student@example.test',
            'phone' => '01913000000',
            'deleted_at' => null,
        ]);

        $this->assertSame(1, StudentUser::withTrashed()->where('phone', '01913000000')->count());
    }

    public function test_store_rejects_a_restore_that_would_collide_with_a_different_trashed_student_on_phone(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.delete']);

        $firstPayload = ['name' => 'Student A', 'email' => 'student-a@example.test', 'phone' => '01913000001', 'password' => 'pin1'];
        $createdA = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $firstPayload)->assertCreated();
        $studentAId = $createdA->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/student-users/{$studentAId}")->assertNoContent();

        $secondPayload = ['name' => 'Student B', 'email' => 'student-b@example.test', 'phone' => '01913000002', 'password' => 'pin1'];
        $createdB = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $secondPayload)->assertCreated();
        $studentBId = $createdB->json('data.id');
        $this->actingAs($actor, 'sanctum')->deleteJson("/api/v1/student-users/{$studentBId}")->assertNoContent();

        // Matches student A by email, but the phone collides with student B's phone.
        $conflictingPayload = ['name' => 'New Student', 'email' => 'student-a@example.test', 'phone' => '01913000002', 'password' => 'pin1'];
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/student-users', $conflictingPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseHas('student_users', [
            'id' => $studentAId,
            'email' => 'student-a@example.test',
            'phone' => '01913000001',
        ]);
        $this->assertSoftDeleted('student_users', ['id' => $studentAId]);

        $this->assertDatabaseHas('student_users', [
            'id' => $studentBId,
            'email' => 'student-b@example.test',
            'phone' => '01913000002',
        ]);
        $this->assertSoftDeleted('student_users', ['id' => $studentBId]);
    }

    public function test_recreating_a_soft_deleted_student_from_a_different_school_is_rejected(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $actorA = $this->actorWithPermissions(['users.create', 'users.delete'], $schoolA->id, $district->id);
        $actorB = $this->actorWithPermissions(['users.create'], $schoolB->id, $district->id);

        $payload = ['name' => 'New Student', 'email' => 'new-student@example.test', 'password' => 'pin1'];

        $created = $this->actingAs($actorA, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();
        $studentId = $created->json('data.id');

        $this->actingAs($actorA, 'sanctum')->deleteJson("/api/v1/student-users/{$studentId}")->assertNoContent();

        $this->actingAs($actorB, 'sanctum')->postJson('/api/v1/student-users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('student_users', [
            'id' => $studentId,
            'school_id' => $schoolA->id,
        ]);
        $this->assertSoftDeleted('student_users', ['id' => $studentId]);
    }

    public function test_index_can_be_filtered_by_school_id_and_search_and_orders_newest_first(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $studentA1 = StudentUser::factory()->create(['name' => 'Rafiq Islam', 'email' => 'rafiq@example.test', 'school_id' => $schoolA->id, 'district_id' => $district->id]);
        $studentA2 = StudentUser::factory()->create(['name' => 'Karim Ahmed', 'school_id' => $schoolA->id, 'district_id' => $district->id]);
        StudentUser::factory()->create(['school_id' => $schoolB->id, 'district_id' => $district->id]);

        $unscopedActor = $this->actorWithPermissions(['users.view']);

        $bySchool = $this->actingAs($unscopedActor, 'sanctum')
            ->getJson("/api/v1/student-users?school_id={$schoolA->id}")
            ->assertOk();
        $bySchool->assertJsonCount(2, 'data');
        $bySchool->assertJsonPath('data.0.id', $studentA2->id);

        $this->actingAs($unscopedActor, 'sanctum')
            ->getJson('/api/v1/student-users?search=rafiq@example.test')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentA1->id);
    }

    public function test_index_can_be_filtered_by_class_id_and_returns_the_current_class(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $classSix = SchoolClass::create(['name' => 'Class Six']);
        $classSeven = SchoolClass::create(['name' => 'Class Seven']);
        ClassSchool::create(['class_id' => $classSix->id, 'school_id' => $school->id, 'district_id' => $district->id]);
        ClassSchool::create(['class_id' => $classSeven->id, 'school_id' => $school->id, 'district_id' => $district->id]);

        $studentInSix = StudentUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);
        $studentInSeven = StudentUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);

        StudentProfile::create(['user_id' => $studentInSix->id, 'school_id' => $school->id, 'district_id' => $district->id, 'class_id' => $classSix->id]);
        StudentProfile::create(['user_id' => $studentInSeven->id, 'school_id' => $school->id, 'district_id' => $district->id, 'class_id' => $classSeven->id]);

        $actor = $this->actorWithPermissions(['users.view']);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/student-users?class_id={$classSix->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $studentInSix->id);
        $response->assertJsonPath('data.0.class.id', $classSix->id);
        $response->assertJsonPath('data.0.class.name', 'Class Six');
    }
}
