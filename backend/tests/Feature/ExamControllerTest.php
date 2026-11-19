<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
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

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Bangla Version']);

        $this->academicYearA = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->academicYearB = AcademicYear::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
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

    public function test_index_and_show_degrade_to_published_and_available_for_an_unauthenticated_viewer(): void
    {
        $draft = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $published = Exam::create($this->examPayload(['name' => 'Final']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
        ]);

        $notYetAvailable = Exam::create($this->examPayload(['name' => 'Future Exam']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'available_from' => now()->addDay(),
        ]);

        $expired = Exam::create($this->examPayload(['name' => 'Past Exam']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'available_until' => now()->subDay(),
        ]);

        $index = $this->getJson('/api/v1/exams')->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $published->id);

        $this->getJson("/api/v1/exams/{$published->id}")->assertOk();
        $this->getJson("/api/v1/exams/{$draft->id}")->assertNotFound();
        $this->getJson("/api/v1/exams/{$notYetAvailable->id}")->assertNotFound();
        $this->getJson("/api/v1/exams/{$expired->id}")->assertNotFound();
    }

    public function test_store_and_update_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->assertForbidden();

        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update', 'exams.view'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['passing_score_percent' => 40])
            ->assertOk()
            ->assertJsonPath('data.passing_score_percent', 40)
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_every_role_without_exams_permissions_cannot_create_even_with_full_questions_access(): void
    {
        // Mirrors every role that gets questions.view/create/update but
        // deliberately NOT exams.* — proves the exclusion is real, not an
        // accidental gap this test suite never checked.
        $actor = $this->actorWithPermissions(['questions.view', 'questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

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

    public function test_publish_guard_reapplies_on_a_follow_up_update_with_no_status_in_the_body(): void
    {
        $book = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);

        // Exactly 2 matching questions available — pool size exactly 2.
        Question::create(['chapter_id' => $chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'Q1']);
        Question::create(['chapter_id' => $chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'Q2']);

        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload([
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 2,
            'marks_per_question' => 2,
        ]))->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // No `status` key in this second request's body — the guard must
        // still re-run because the exam's resulting status is published.
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['random_question_count' => 5])
            ->assertUnprocessable();
    }

    public function test_show_404s_a_draft_exam_for_an_actor_with_no_exams_permission(): void
    {
        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertNotFound();
    }

    public function test_destroy_is_forbidden_without_exams_delete_permission(): void
    {
        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $actor = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/exams/{$exam->id}")
            ->assertForbidden();
    }

    public function test_destroy_succeeds_with_exams_delete_permission(): void
    {
        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $actor = $this->actorWithPermissions(['exams.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/exams/{$exam->id}")
            ->assertNoContent();
    }

    public function test_is_correct_is_hidden_from_an_exam_viewer_without_questions_update(): void
    {
        $book = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);
        $question = Question::create(['chapter_id' => $chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'What is 2+2?']);
        QuestionOption::create(['question_id' => $question->id, 'option_text' => '4', 'is_correct' => true]);
        QuestionOption::create(['question_id' => $question->id, 'option_text' => '3', 'is_correct' => false]);

        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);
        $exam->questions()->attach($question->id, ['order_index' => 0]);

        $viewer = $this->actorWithPermissions(['exams.view', 'questions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertOk();
        $this->assertArrayNotHasKey('is_correct', $response->json('data.questions.0.question.options.0'));

        $manager = $this->actorWithPermissions(['exams.view', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertOk();
        $this->assertArrayHasKey('is_correct', $response->json('data.questions.0.question.options.0'));
    }

    public function test_index_and_show_still_respect_tenant_scope_for_an_actor_with_exams_view(): void
    {
        $ownDraft = Exam::create($this->examPayload(['name' => 'Own Draft']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
        ]);

        $otherSchoolExam = Exam::create($this->examPayload(['name' => 'Other School', 'class_version_id' => $this->versionB->id]) + [
            'school_id' => $this->versionB->school_id,
            'district_id' => $this->versionB->district_id,
            'status' => 'published',
        ]);

        $actor = $this->actorWithPermissions(['exams.view'], $this->schoolA->id, $this->schoolA->district_id);

        $index = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/exams')->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $ownDraft->id);

        $this->actingAs($actor, 'sanctum')->getJson("/api/v1/exams/{$ownDraft->id}")->assertOk();
        $this->actingAs($actor, 'sanctum')->getJson("/api/v1/exams/{$otherSchoolExam->id}")->assertNotFound();
    }

    public function test_index_never_returns_a_practice_exam_for_any_actor(): void
    {
        $practiceExam = Exam::create($this->examPayload(['name' => 'Practice Session']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'is_practice' => true,
        ]);

        $published = Exam::create($this->examPayload(['name' => 'Real Final']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
        ]);

        $index = $this->getJson('/api/v1/exams')->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $published->id);

        $manager = $this->actorWithPermissions(['exams.view'], $this->versionA->school_id, $this->versionA->district_id);
        $managerIndex = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/exams')->assertOk();

        // assertJsonMissing(['id' => ...]) searches the whole response
        // fragment-wise and false-fails here: nested relations in the
        // resource payload (academic_year, class_version, subject, ...)
        // legitimately share small ids with $practiceExam, so a bare id
        // fragment can match one of those instead of an exam row. Scope
        // the check to the listed exams' own ids.
        $managerExamIds = collect($managerIndex->json('data'))->pluck('id');
        $this->assertNotContains($practiceExam->id, $managerExamIds);
    }
}
