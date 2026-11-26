<?php

namespace Tests\Feature;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_management_actor_can_view_their_own_profile(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Admin Person']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJson(['data' => [
                'name' => 'Admin Person',
                'email' => $user->email,
                'role' => $role->name,
                'role_label' => 'School Admin',
            ]]);
    }

    public function test_a_management_actor_can_update_their_own_name_email_and_phone(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
            'phone' => '01700000099',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name', 'email' => 'updated@example.test']);
    }

    public function test_updating_the_own_email_to_one_already_taken_by_someone_else_fails_validation(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        User::factory()->create(['role_id' => $role->id, 'email' => 'taken@example.test']);
        $actor = User::factory()->create(['role_id' => $role->id, 'email' => 'actor@example.test']);

        $this->actingAs($actor, 'sanctum')->patchJson('/api/v1/me', [
            'name' => $actor->name,
            'email' => 'taken@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_updating_the_own_email_to_the_same_value_it_already_has_succeeds(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'me@example.test']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', [
            'name' => $user->name,
            'email' => 'me@example.test',
        ])->assertOk();
    }

    public function test_a_management_actor_can_change_their_own_password_with_the_correct_current_password(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        $user = User::factory()->create(['role_id' => $role->id, 'password' => 'old-password']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_changing_password_with_the_wrong_current_password_fails(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'School Admin']);
        $user = User::factory()->create(['role_id' => $role->id, 'password' => 'old-password']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a_student_can_set_a_four_character_password_but_not_shorter(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'Student']);
        $student = StudentUser::factory()->create(['role_id' => $role->id, 'password' => 'old-pin']);

        $this->actingAs($student, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'old-pin',
            'password' => 'pin1',
            'password_confirmation' => 'pin1',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('pin1', $student->fresh()->password));

        $this->actingAs($student, 'sanctum')->patchJson('/api/v1/me/password', [
            'current_password' => 'pin1',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_teacher_can_view_and_update_their_own_profile_including_bn_name(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'Teacher']);
        $teacher = TeacherUser::factory()->create(['role_id' => $role->id, 'name' => 'Teacher Name']);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.name', 'Teacher Name');

        $this->actingAs($teacher, 'sanctum')->patchJson('/api/v1/me', [
            'name' => 'Teacher Name',
            'bn_name' => 'শিক্ষকের নাম',
        ])->assertOk()->assertJsonPath('data.bn_name', 'শিক্ষকের নাম');

        $this->assertDatabaseHas('teacher_users', ['id' => $teacher->id, 'bn_name' => 'শিক্ষকের নাম']);
    }

    public function test_a_guardian_can_view_and_update_their_own_profile(): void
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'label' => 'Guardian']);
        $guardian = GuardianUser::factory()->create(['role_id' => $role->id, 'name' => 'Guardian Name']);

        $this->actingAs($guardian, 'sanctum')->patchJson('/api/v1/me', [
            'name' => 'Updated Guardian Name',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Guardian Name');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}
