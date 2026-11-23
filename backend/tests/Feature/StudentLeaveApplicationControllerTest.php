<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentLeaveApplicationControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Shift $shift;

    private ClassVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $this->shift = Shift::create(['class_version_id' => $this->version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);
    }

    private function studentInSection(): StudentUser
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->version->class_id,
            'section_id' => $this->section->id,
            'shift_id' => $this->shift->id,
            'class_version_id' => $this->version->id,
        ]);

        return $student;
    }

    public function test_student_can_submit_a_leave_application_scoped_to_their_own_profile(): void
    {
        $student = $this->studentInSection();

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.school_id', $this->school->id)
            ->assertJsonPath('data.section_id', $this->section->id)
            ->assertJsonPath('data.shift_id', $this->shift->id)
            ->assertJsonPath('data.class_version_id', $this->version->id);

        $this->assertDatabaseHas('student_leave_applications', [
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
            'status' => 'pending',
        ]);
    }

    public function test_store_rejects_a_student_with_no_section_assigned(): void
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create(['user_id' => $student->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
        ])->assertUnprocessable()->assertJsonValidationErrors('student_profile');
    }

    public function test_store_is_forbidden_for_a_non_student_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
        ])->assertForbidden();
    }

    public function test_store_rejects_to_date_before_from_date(): void
    {
        $student = $this->studentInSection();

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-01',
            'reason' => 'Family event',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    public function test_mine_returns_only_this_students_own_applications(): void
    {
        $studentA = $this->studentInSection();
        $studentB = $this->studentInSection();

        $this->actingAs($studentA, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($studentB, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $this->actingAs($studentA, 'sanctum')
            ->getJson('/api/v1/student-leave-applications/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'A');
    }

    public function test_the_class_teacher_of_the_section_can_review_the_application(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['student_leave_applications.view', 'student_leave_applications.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $applicationId = $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $this->actingAs($classTeacher, 'sanctum')
            ->patchJson("/api/v1/student-leave-applications/{$applicationId}/review", [
                'status' => 'approved',
                'teacher_comment' => 'Get well soon',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.teacher_comment', 'Get well soon')
            ->assertJsonPath('data.reviewed_by.id', $classTeacher->id);
    }

    public function test_a_teacher_who_is_not_the_class_teacher_cannot_review_the_application(): void
    {
        $otherTeacher = $this->teacherActorWithPermissions(['student_leave_applications.view', 'student_leave_applications.update'], $this->school->id, $this->school->district_id);
        // Note: $this->section has no class_teacher_id set, so $otherTeacher
        // is not the class teacher of any section.

        $student = $this->studentInSection();
        $applicationId = $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $this->actingAs($otherTeacher, 'sanctum')
            ->patchJson("/api/v1/student-leave-applications/{$applicationId}/review", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_review_rejects_an_invalid_status(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['student_leave_applications.view', 'student_leave_applications.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $applicationId = $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $this->actingAs($classTeacher, 'sanctum')
            ->patchJson("/api/v1/student-leave-applications/{$applicationId}/review", ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    /**
     * Any teacher with the .view permission sees every application in
     * their own school (TenantScope), not just their own section's —
     * per the explicit product decision that other teachers get
     * view-only access, while only the section's own class teacher gets
     * `can_review: true` and may actually act on it.
     */
    public function test_a_non_class_teacher_can_view_but_not_review(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['student_leave_applications.view', 'student_leave_applications.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $otherTeacher = $this->teacherActorWithPermissions(['student_leave_applications.view', 'student_leave_applications.update'], $this->school->id, $this->school->district_id);

        $otherSection = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section B']);
        $otherStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $otherStudent->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->version->class_id,
            'section_id' => $otherSection->id,
            'shift_id' => $this->shift->id,
            'class_version_id' => $this->version->id,
        ]);

        $student = $this->studentInSection();
        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'In my section',
        ])->assertCreated();

        $this->actingAs($otherStudent, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Other section',
        ])->assertCreated();

        // otherTeacher sees BOTH applications (view-only, school-wide) —
        // not narrowed to their own section, since they aren't the class
        // teacher of either one.
        $response = $this->actingAs($otherTeacher, 'sanctum')
            ->getJson('/api/v1/student-leave-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['can_review']);
        }

        // classTeacher's own row in that same listing is reviewable;
        // the other section's row isn't.
        $asClassTeacher = $this->actingAs($classTeacher, 'sanctum')
            ->getJson('/api/v1/student-leave-applications')
            ->assertOk()
            ->json('data');

        $ownRow = collect($asClassTeacher)->firstWhere('reason', 'In my section');
        $otherRow = collect($asClassTeacher)->firstWhere('reason', 'Other section');
        $this->assertTrue($ownRow['can_review']);
        $this->assertFalse($otherRow['can_review']);
    }

    /**
     * Regression test: `schoolClass` was missing from the controller's
     * own WITH list despite StudentLeaveApplicationResource always
     * referencing it — `class` silently came back empty on every
     * response (whenLoaded() omits an unloaded relation rather than
     * erroring) until a UI finally rendered it and the gap became
     * visible.
     */
    public function test_index_includes_the_students_class(): void
    {
        $teacher = $this->teacherActorWithPermissions(['student_leave_applications.view'], $this->school->id, $this->school->district_id);

        $student = $this->studentInSection();
        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->assertCreated();

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/student-leave-applications')
            ->assertOk()
            ->assertJsonPath('data.0.class.id', $this->version->class_id)
            ->assertJsonPath('data.0.class.name', 'Class 6');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/student-leave-applications')
            ->assertForbidden();
    }
}
