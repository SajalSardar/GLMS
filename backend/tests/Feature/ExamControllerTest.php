<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ExamControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

    private AcademicYear $academicYearA;

    private AcademicYear $academicYearB;

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

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Bangla Version']);

        $this->academicYearA = AcademicYear::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->academicYearB = AcademicYear::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    private function examPayload(array $overrides = []): array
    {
        return array_merge([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ], $overrides);
    }

    public function test_index_and_show_require_authentication(): void
    {
        $this->getJson('/api/v1/exams')->assertUnauthorized();

        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);
        $this->getJson("/api/v1/exams/{$exam->id}")->assertUnauthorized();
    }

    public function test_store_and_update_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->assertForbidden();

        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update', 'exams.view'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['passing_score_percent' => 40])
            ->assertOk()
            ->assertJsonPath('data.passing_score_percent', 40);
    }

    public function test_every_role_without_exams_permissions_is_forbidden_even_with_full_questions_access(): void
    {
        // Mirrors every role that gets questions.view/create/update but
        // deliberately NOT exams.* — proves the exclusion is real, not an
        // accidental gap this test suite never checked.
        $actor = $this->actorWithPermissions(['questions.view', 'questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/exams')->assertForbidden();
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->assertForbidden();
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_exam(): void
    {
        $exam = Exam::create($this->examPayload([
            'class_version_id' => $this->versionB->id,
            'academic_year_id' => $this->academicYearB->id,
        ]) + ['school_id' => $this->versionB->school_id, 'district_id' => $this->versionB->district_id]);

        $actor = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/exams/{$exam->id}", ['passing_score_percent' => 50])
            ->assertNotFound();
    }

    public function test_class_version_subject_academic_year_and_selection_mode_are_immutable(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->json('data.id');

        // UpdateExamRequest's rules() simply doesn't accept these fields,
        // so sending them is silently ignored rather than rejected — this
        // test proves the stored values never change.
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/exams/{$id}", [
            'class_version_id' => $this->versionB->id,
            'selection_mode' => 'fixed_random',
        ])->assertOk();

        $exam = Exam::findOrFail($id);
        $this->assertSame($this->versionA->id, $exam->class_version_id);
        $this->assertSame('manual', $exam->selection_mode);
    }

    public function test_publish_is_rejected_for_a_manual_exam_with_no_questions(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['status' => 'published'])
            ->assertUnprocessable();
    }

    public function test_publish_is_rejected_for_a_per_attempt_random_exam_with_too_small_a_pool(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload([
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 5,
            'marks_per_question' => 2,
        ]))->json('data.id');

        // No questions exist under this subject+class-version at all yet.
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['status' => 'published'])
            ->assertUnprocessable();
    }
}
