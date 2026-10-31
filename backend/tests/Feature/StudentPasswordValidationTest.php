<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithUsersCreateAndUpdate(): User
    {
        $role = Role::create(['name' => 'school_admin_spv', 'label' => 'School Admin']);
        $createPermission = Permission::create(['name' => 'users.create', 'label' => 'Create users']);
        $updatePermission = Permission::create(['name' => 'users.update', 'label' => 'Update users']);
        $role->permissions()->attach([$createPermission->id, $updatePermission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_student_account_can_be_created_with_a_four_digit_pin(): void
    {
        $studentRole = Role::create(['name' => 'student', 'label' => 'Student']);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Test Student',
            'email' => 'test-student@example.test',
            'password' => '1234',
            'role_id' => $studentRole->id,
        ]);

        $response->assertCreated();
    }

    public function test_a_non_student_account_still_requires_an_eight_character_password(): void
    {
        $teacherRole = Role::create(['name' => 'teacher_spv', 'label' => 'Teacher']);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Test Teacher',
            'email' => 'test-teacher@example.test',
            'password' => '1234',
            'role_id' => $teacherRole->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_students_pin_can_be_updated_to_another_short_pin(): void
    {
        $studentRole = Role::create(['name' => 'student', 'label' => 'Student']);
        $student = User::factory()->create(['role_id' => $studentRole->id]);
        $actor = $this->actorWithUsersCreateAndUpdate();

        $response = $this->actingAs($actor, 'sanctum')->patchJson("/api/v1/users/{$student->id}", [
            'password' => '5678',
        ]);

        $response->assertOk();
    }
}
