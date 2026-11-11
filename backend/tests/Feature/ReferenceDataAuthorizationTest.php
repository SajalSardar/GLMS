<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ReferenceDataAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_division_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Existing Division', 'bn_name' => 'বিদ্যমান বিভাগ']);
        $payload = ['name' => 'New Division', 'bn_name' => 'নতুন বিভাগ'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/divisions', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['divisions.create', 'divisions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/divisions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }

    public function test_district_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'Existing District', 'bn_name' => 'বিদ্যমান জেলা']);
        $payload = ['division_id' => $division->id, 'name' => 'New District', 'bn_name' => 'নতুন জেলা'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/districts', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['districts.create', 'districts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/districts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }

    public function test_upazila_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Existing Upazila', 'bn_name' => 'বিদ্যমান উপজেলা']);
        $payload = ['district_id' => $district->id, 'name' => 'New Upazila', 'bn_name' => 'নতুন উপজেলা'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/upazilas', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['upazilas.create', 'upazilas.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/upazilas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }

    public function test_subject_store_and_update_are_authorized(): void
    {
        $subject = Subject::create(['name' => 'Existing Subject']);
        $payload = ['name' => 'New Subject'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/subjects', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['subjects.create', 'subjects.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subjects', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }

    public function test_guardian_store_and_update_are_authorized(): void
    {
        $guardian = Guardian::create(['name' => 'Existing Guardian', 'phone' => '01700000001']);
        $payload = ['name' => 'New Guardian', 'phone' => '01700000002'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/guardians', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['guardians.create', 'guardians.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardians', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
}
