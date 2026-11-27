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

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A', 'address' => '12 Main Road, Dhaka']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'code' => 'BAN-101']);
    }

    /**
     * A 'question' item's own full, self-contained content — no
     * academic_question_id, since an item no longer references the bank
     * at all (see 2026_11_27_143843_embed_question_content_in_question_paper_items_and_add_indent_level).
     *
     * @return array<string, mixed>
     */
    private function questionItem(array $overrides = []): array
    {
        return array_merge([
            'type' => 'question',
            'question_type' => 'cq',
            'question_text' => 'Read the passage and answer in your own words.',
            'marks' => 10,
        ], $overrides);
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

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                ['type' => 'heading', 'text_content' => 'Prose'],
                $this->questionItem(),
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.school.name', 'School A')
            ->assertJsonPath('data.school.address', '12 Main Road, Dhaka')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.item_type', 'heading')
            ->assertJsonPath('data.items.1.item_type', 'question')
            ->assertJsonPath('data.items.1.question_text', 'Read the passage and answer in your own words.')
            ->assertJsonPath('data.items.1.marks', 10);

        $this->assertDatabaseHas('question_papers', ['teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id]);
    }

    public function test_a_text_item_and_a_questions_serial_label_and_indent_level_round_trip(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                ['type' => 'text', 'text_content' => 'Read the following note before answering.'],
                $this->questionItem(['marks' => 15, 'serial_label' => '1(a)', 'indent_level' => 1]),
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.items.0.item_type', 'text')
            ->assertJsonPath('data.items.0.text_content', 'Read the following note before answering.')
            ->assertJsonPath('data.items.1.item_type', 'question')
            ->assertJsonPath('data.items.1.marks', 15)
            ->assertJsonPath('data.items.1.serial_label', '1(a)')
            ->assertJsonPath('data.items.1.indent_level', 1);
    }

    public function test_an_mcq_question_items_options_round_trip(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                $this->questionItem(['question_type' => 'mcq', 'marks' => 1, 'options' => ['0', '1', '-1', '10']]),
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.items.0.question_type', 'mcq')
            ->assertJsonPath('data.items.0.options', ['0', '1', '-1', '10']);
    }

    public function test_store_rejects_an_mcq_question_item_with_no_options(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                $this->questionItem(['question_type' => 'mcq']),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.options');
    }

    public function test_store_rejects_a_cq_question_item_carrying_options(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload([
                $this->questionItem(['options' => ['a', 'b', 'c', 'd']]),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.options');
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

    public function test_store_rejects_a_question_item_missing_its_required_fields(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'question'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.question_type', 'items.0.question_text', 'items.0.marks']);
    }

    public function test_store_rejects_a_heading_item_missing_text_content(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'heading'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.text_content');
    }

    public function test_a_colleague_at_the_same_school_can_view_but_not_edit_the_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
        ]))->json('data.id');

        $colleague = $this->teacherActorWithPermissions(['question_papers.view', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($colleague, 'sanctum')
            ->getJson("/api/v1/question-papers/{$paperId}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false);

        $this->actingAs($colleague, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $this->paperPayload([$this->questionItem()]))
            ->assertForbidden();
    }

    public function test_creator_can_update_their_own_paper_replacing_its_items(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
        ]))->json('data.id');

        $payload = $this->paperPayload([
            $this->questionItem(['question_text' => 'A brand new question replacing the old one.', 'marks' => 5]),
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.question_text', 'A brand new question replacing the old one.')
            ->assertJsonPath('data.items.0.marks', 5);

        $this->assertDatabaseCount('question_paper_items', 1);
    }

    public function test_a_question_items_wording_and_marks_can_be_edited_for_this_paper_alone(): void
    {
        // The whole point of embedding a question's content instead of
        // referencing the bank by id — editing it here is just an
        // ordinary update to this paper's own row, nothing more.
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            $this->questionItem(['question_text' => 'Original wording.', 'marks' => 10]),
        ]))->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $this->paperPayload([
                $this->questionItem(['question_text' => 'Edited wording for this paper only.', 'marks' => 12]),
            ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.question_text', 'Edited wording for this paper only.')
            ->assertJsonPath('data.items.0.marks', 12);
    }

    public function test_creator_can_delete_their_own_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
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

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
        ]))->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
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

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
        ]))->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
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

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
        ]))->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload([
            ['type' => 'heading', 'text_content' => 'Prose'],
            $this->questionItem(),
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
}
