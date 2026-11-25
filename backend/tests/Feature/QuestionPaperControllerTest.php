<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionPaperControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private SchoolClass $schoolClass;

    private Subject $subject;

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

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'code' => 'BAN-101']);
    }

    private function createQuestion(array $overrides = []): int
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        return $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', array_merge([
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
            'question_type' => 'cq',
            'question_text' => 'Read the passage...',
            'marks' => 10,
        ], $overrides))->json('data.id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemOverrides
     * @return array<string, mixed>
     */
    private function paperPayload(array $itemOverrides = []): array
    {
        return [
            'title' => 'Mid-term Bangla Paper',
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'time_duration_minutes' => 150,
            'full_marks' => 70,
            'instructions' => 'Answer all questions.',
            'items' => $itemOverrides,
        ];
    }

    public function test_teacher_can_create_a_question_paper_scoped_to_their_own_school(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        // Create the question before entering the teacher's actingAs() block to avoid nested authentication context
        $questionId = $this->createQuestion();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                ['type' => 'heading', 'heading_text' => 'Prose'],
                ['type' => 'question', 'academic_question_id' => $questionId],
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.item_type', 'heading')
            ->assertJsonPath('data.items.1.item_type', 'question');

        $this->assertDatabaseHas('question_papers', ['teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id]);
    }

    public function test_store_rejects_a_teacher_with_no_school_assigned(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], null, null);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload())
            ->assertForbidden();
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload())
            ->assertForbidden();
    }

    public function test_store_rejects_a_question_item_missing_academic_question_id(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'question'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.academic_question_id');
    }

    public function test_store_rejects_a_heading_item_missing_heading_text(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'heading'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.heading_text');
    }

    public function test_store_rejects_an_inactive_academic_question(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $inactiveQuestionId = $this->createQuestion(['is_active' => false]);

        $payload = $this->paperPayload([
            ['type' => 'question', 'academic_question_id' => $inactiveQuestionId],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.academic_question_id');
    }

    public function test_a_colleague_at_the_same_school_can_view_but_not_edit_the_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $questionId = $this->createQuestion();
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId],
        ]))->json('data.id');

        $colleague = $this->teacherActorWithPermissions(['question_papers.view', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($colleague, 'sanctum')
            ->getJson("/api/v1/question-papers/{$paperId}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false);

        $questionId2 = $this->createQuestion();
        $this->actingAs($colleague, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $this->paperPayload([
                ['type' => 'question', 'academic_question_id' => $questionId2],
            ]))
            ->assertForbidden();
    }

    public function test_creator_can_update_their_own_paper_replacing_its_items(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);
        $questionId = $this->createQuestion();
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId],
        ]))->json('data.id');

        $newQuestionId = $this->createQuestion(['chapter_name' => 'Poetry']);
        $payload = $this->paperPayload([
            ['type' => 'question', 'academic_question_id' => $newQuestionId],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.academic_question.id', $newQuestionId);

        $this->assertDatabaseCount('question_paper_items', 1);
    }

    public function test_creator_can_delete_their_own_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $questionId = $this->createQuestion();
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId],
        ]))->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/question-papers/{$paperId}")
            ->assertNoContent();

        $this->assertSoftDeleted('question_papers', ['id' => $paperId]);
    }

    public function test_school_admin_can_view_every_teachers_paper_at_their_school_but_not_edit_or_delete(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $questionId1 = $this->createQuestion();
        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId1],
        ]))->assertCreated();

        $questionId2 = $this->createQuestion();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId2],
        ]))->assertCreated();

        $schoolAdmin = $this->actorWithPermissions(['question_papers.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstId = $response->json('data.0.id');

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/question-papers/{$firstId}")
            ->assertForbidden();
    }

    public function test_school_admin_sees_only_their_own_schools_papers(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolB->id, $this->schoolB->district_id);

        $questionId1 = $this->createQuestion();
        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId1],
        ]))->assertCreated();

        $questionId2 = $this->createQuestion();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId2],
        ]))->assertCreated();

        $schoolAdminA = $this->actorWithPermissions(['question_papers.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_national_admin_sees_every_schools_papers(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolB->id, $this->schoolB->district_id);

        $questionId1 = $this->createQuestion();
        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId1],
        ]))->assertCreated();

        $questionId2 = $this->createQuestion();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId2],
        ]))->assertCreated();

        $nationalAdmin = $this->actorWithPermissions(['question_papers.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertForbidden();
    }

    public function test_update_succeeds_unchanged_after_one_of_its_questions_is_deactivated(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);
        $questionId = $this->createQuestion();
        $payload = $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId],
        ]);

        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $payload)->json('data.id');

        $nationalAdmin = $this->actorWithPermissions(['academic_questions.update']);
        $this->actingAs($nationalAdmin, 'sanctum')
            ->putJson("/api/v1/academic-questions/{$questionId}", array_merge($this->createQuestionUpdatePayload(), ['is_active' => false]))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Re-saving the same, now-deactivated, item must still succeed —
        // only brand-new papers (StoreQuestionPaperRequest) require
        // is_active=true on their questions.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $payload)
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.academic_question.id', $questionId);
    }

    public function test_a_soft_deleted_questions_content_still_appears_on_a_paper_that_references_it(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.view'], $this->schoolA->id, $this->schoolA->district_id);
        $questionId = $this->createQuestion();
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'heading_text' => 'Prose'],
            ['type' => 'question', 'academic_question_id' => $questionId],
        ]))->json('data.id');

        $nationalAdmin = $this->actorWithPermissions(['academic_questions.delete']);
        $this->actingAs($nationalAdmin, 'sanctum')
            ->deleteJson("/api/v1/academic-questions/{$questionId}")
            ->assertNoContent();
        $this->assertSoftDeleted('academic_questions', ['id' => $questionId]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/question-papers/{$paperId}")
            ->assertOk()
            ->assertJsonPath('data.items.1.academic_question.id', $questionId)
            ->assertJsonPath('data.items.1.academic_question.question_text', 'Read the passage...');
    }

    /**
     * @return array<string, mixed>
     */
    private function createQuestionUpdatePayload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
            'question_type' => 'cq',
            'question_text' => 'Read the passage...',
            'marks' => 10,
        ];
    }
}
