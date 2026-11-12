<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_teacher_user_store_and_update_are_authorized(): void
    {
        $target = TeacherUser::factory()->create();
        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertOk();
    }

    public function test_school_scoped_actor_cannot_create_teacher_in_different_school(): void
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

        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123', 'school_id' => $schoolB->id];

        $this->actingAs($actorScopedToA, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertUnprocessable();
    }
}
