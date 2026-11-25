<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicQuestionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private Subject $subject;

    private int $versionId;

    private Chapter $chapter;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'bn_name' => 'ষষ্ঠ শ্রেণি', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'bn_name' => 'বাংলা', 'code' => 'BAN-101']);
        $this->versionId = Version::where('name', 'Bangla')->value('id');
        $this->chapter = $this->chapterFor($this->schoolClass, $this->subject);
    }

    private function chapterFor(SchoolClass $schoolClass, Subject $subject): Chapter
    {
        $classVersion = ClassVersion::create([
            'class_id' => $schoolClass->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'version_id' => $this->versionId,
        ]);
        $book = Book::create(['class_version_id' => $classVersion->id, 'subject_id' => $subject->id, 'name' => "{$subject->name} Book"]);

        return Chapter::create(['book_id' => $book->id, 'name' => 'Prose']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cqPayload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'version_id' => $this->versionId,
            'chapter_id' => $this->chapter->id,
            'question_type' => 'cq',
            'question_text' => 'Read the passage and answer the following...',
            'marks' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqPayload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'version_id' => $this->versionId,
            'chapter_id' => $this->chapter->id,
            'question_type' => 'mcq',
            'question_text' => 'Which of the following is correct?',
            'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
            'marks' => 1,
        ];
    }

    public function test_national_admin_can_create_a_cq_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->cqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'cq')
            ->assertJsonPath('data.options', null);

        $this->assertDatabaseHas('academic_questions', ['chapter_id' => $this->chapter->id, 'question_type' => 'cq']);
    }

    public function test_national_admin_can_create_an_mcq_question_with_four_options(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'mcq')
            ->assertJsonCount(4, 'data.options');
    }

    public function test_store_rejects_mcq_without_four_options(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->mcqPayload();
        unset($payload['options']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_store_rejects_cq_with_options_present(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->cqPayload();
        $payload['options'] = ['A', 'B', 'C', 'D'];

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_store_rejects_a_chapter_that_does_not_belong_to_the_selected_class_version_and_subject(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $otherSubject = Subject::create(['name' => 'Mathematics', 'code' => 'MAT-101']);

        $payload = $this->cqPayload();
        $payload['subject_id'] = $otherSubject->id;

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chapter_id');
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->cqPayload())
            ->assertForbidden();
    }

    public function test_teacher_can_view_the_bank_filtered_by_criteria(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->assertCreated();

        $otherClass = SchoolClass::create(['name' => 'Class 7', 'numeric_level' => 7]);
        $otherChapter = $this->chapterFor($otherClass, $this->subject);
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', array_merge(
            $this->cqPayload(),
            ['school_class_id' => $otherClass->id, 'chapter_id' => $otherChapter->id],
        ))->assertCreated();

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/academic-questions?school_class_id={$this->schoolClass->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/academic-questions')
            ->assertForbidden();
    }

    public function test_school_admin_cannot_view_the_bank(): void
    {
        // school_admin never gets academic_questions.view (see
        // RolePermissionSeeder) — it only prints already-saved question
        // papers, never browses the raw bank.
        $schoolAdmin = $this->actorWithPermissions([]);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/academic-questions')
            ->assertForbidden();
    }

    public function test_national_admin_can_update_a_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.update']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $payload = $this->cqPayload();
        $payload['marks'] = 15;

        $this->actingAs($nationalAdmin, 'sanctum')
            ->putJson("/api/v1/academic-questions/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.marks', 15);
    }

    public function test_updating_a_deactivated_question_without_is_active_keeps_it_inactive(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.update']);
        $payload = $this->cqPayload();
        $payload['is_active'] = false;
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $payload)->json('data.id');

        $updatePayload = $this->cqPayload();
        $updatePayload['marks'] = 15;
        // Deliberately omit `is_active` — a caller only changing `marks`
        // must not reactivate a previously deactivated question.

        $this->actingAs($nationalAdmin, 'sanctum')
            ->putJson("/api/v1/academic-questions/{$id}", $updatePayload)
            ->assertOk()
            ->assertJsonPath('data.marks', 15)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('academic_questions', ['id' => $id, 'marks' => 15, 'is_active' => false]);
    }

    public function test_national_admin_can_delete_a_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.delete']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $this->actingAs($nationalAdmin, 'sanctum')
            ->deleteJson("/api/v1/academic-questions/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('academic_questions', ['id' => $id]);
    }

    public function test_delete_is_forbidden_without_the_delete_permission(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/academic-questions/{$id}")
            ->assertForbidden();
    }

    public function test_soft_deleted_questions_are_excluded_from_the_index(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.delete']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');
        $this->actingAs($nationalAdmin, 'sanctum')->deleteJson("/api/v1/academic-questions/{$id}")->assertNoContent();

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/academic-questions')->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_inactive_questions_are_excluded_from_the_index(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->cqPayload();
        $payload['is_active'] = false;
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $payload)->assertCreated();

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/academic-questions')->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_an_actor_with_the_update_permission_sees_inactive_questions_in_the_index(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.update', 'academic_questions.view']);
        $payload = $this->cqPayload();
        $payload['is_active'] = false;
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $payload)->assertCreated();

        $response = $this->actingAs($nationalAdmin, 'sanctum')->getJson('/api/v1/academic-questions')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_active'));
    }
}
