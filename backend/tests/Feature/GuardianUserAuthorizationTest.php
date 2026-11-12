<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\GuardianUser;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class GuardianUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_guardian_user_store_and_update_are_authorized(): void
    {
        $target = GuardianUser::factory()->create();
        $payload = ['name' => 'New Guardian', 'email' => 'new-guardian@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertOk();
    }

    public function test_school_scoped_actor_cannot_create_guardian_in_different_school(): void
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

        $payload = [
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $schoolB->id,
        ];

        $this->actingAs($actorScopedToA, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertUnprocessable();
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
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $schoolInDistrictB->id,
        ];

        $this->actingAs($actorScopedToDistrictA, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertUnprocessable();
    }

    public function test_guardian_user_store_resolves_district_from_school_for_unscoped_actor(): void
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
            'name' => 'New Guardian',
            'email' => 'new-guardian@example.test',
            'password' => 'password123',
            'school_id' => $school->id,
        ];

        $response = $this->actingAs($unscopedActor, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();

        $response->assertJsonPath('data.district.id', $district->id);
        $this->assertDatabaseHas('guardian_users', [
            'email' => 'new-guardian@example.test',
            'school_id' => $school->id,
            'district_id' => $district->id,
        ]);
    }
}
