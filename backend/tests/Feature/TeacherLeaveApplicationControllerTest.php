<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherLeaveApplication;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherLeaveApplicationControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

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
    }

    public function test_teacher_can_submit_a_leave_application_scoped_to_their_own_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.delegate_teacher_id', null);

        $this->assertDatabaseHas('teacher_leave_applications', [
            'teacher_id' => $teacher->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'status' => 'pending',
        ]);
    }

    public function test_teacher_can_name_a_colleague_at_the_same_school_as_substitute(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $colleague = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
            'delegate_teacher_id' => $colleague->id,
        ])->assertCreated()->assertJsonPath('data.delegate.id', $colleague->id);
    }

    public function test_store_rejects_naming_yourself_as_substitute(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
            'delegate_teacher_id' => $teacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('delegate_teacher_id');
    }

    public function test_store_rejects_a_substitute_from_a_different_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherSchoolTeacher = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
            'delegate_teacher_id' => $otherSchoolTeacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('delegate_teacher_id');
    }

    public function test_store_rejects_to_date_before_from_date(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-05', 'to_date' => '2026-12-01', 'reason' => 'Family event',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    public function test_store_rejects_an_overlapping_existing_request(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-10', 'reason' => 'First request',
        ])->assertCreated();

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-05', 'to_date' => '2026-12-15', 'reason' => 'Overlapping request',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');
    }

    public function test_store_rejects_a_teacher_with_no_school_assigned(): void
    {
        $teacher = $this->teacherActorWithPermissions([], null, null);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
        ])->assertUnprocessable()->assertJsonValidationErrors('teacher');
    }

    public function test_store_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
        ])->assertForbidden();
    }

    public function test_mine_returns_only_this_teachers_own_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $this->actingAs($teacherA, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'A');
    }

    public function test_colleagues_returns_only_other_teachers_at_the_same_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $colleague = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherSchoolTeacher = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications/colleagues')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($colleague->id));
        $this->assertFalse($ids->contains($teacher->id));
        $this->assertFalse($ids->contains($otherSchoolTeacher->id));
    }

    public function test_school_admin_sees_only_their_own_schools_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $schoolAdminA = $this->actorWithPermissions(['teacher_leave_applications.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'A');
    }

    public function test_national_admin_sees_every_schools_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $nationalAdmin = $this->actorWithPermissions(['teacher_leave_applications.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertForbidden();
    }

    public function test_school_admin_can_approve_an_application(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $schoolAdmin = $this->actorWithPermissions(['teacher_leave_applications.view', 'teacher_leave_applications.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", [
                'status' => 'approved',
                'admin_comment' => 'Get well soon',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.admin_comment', 'Get well soon')
            ->assertJsonPath('data.reviewed_by.id', $schoolAdmin->id);
    }

    public function test_review_is_forbidden_for_a_teacher_without_the_update_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($otherTeacher, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_review_rejects_an_invalid_status(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $schoolAdmin = $this->actorWithPermissions(['teacher_leave_applications.view', 'teacher_leave_applications.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_index_can_filter_to_currently_active_applications(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $active = TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id,
            'from_date' => now()->subDay()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Currently on leave', 'status' => 'approved',
        ]);

        TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id,
            'from_date' => now()->addDays(10)->toDateString(), 'to_date' => now()->addDays(12)->toDateString(),
            'reason' => 'Future leave, still pending', 'status' => 'pending',
        ]);

        $nationalAdmin = $this->actorWithPermissions(['teacher_leave_applications.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications?current=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }
}
