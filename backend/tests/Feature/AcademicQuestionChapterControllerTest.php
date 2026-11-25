<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicQuestionChapterControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'code' => 'BAN-101']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
        ];
    }

    public function test_national_admin_can_create_a_chapter(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_question_chapters.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-question-chapters', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.chapter_name', 'Prose')
            ->assertJsonPath('data.chapter_number', 1);

        $this->assertDatabaseHas('academic_question_chapters', ['chapter_name' => 'Prose', 'chapter_number' => 1]);
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_question_chapters.view']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/academic-question-chapters', $this->payload())
            ->assertForbidden();
    }

    public function test_teacher_can_view_the_chapter_list_filtered_by_class_and_subject(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_question_chapters.view']);
        $nationalAdmin = $this->actorWithPermissions(['academic_question_chapters.create']);

        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-question-chapters', $this->payload())->assertCreated();

        $otherSubject = Subject::create(['name' => 'English', 'code' => 'ENG-101']);
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-question-chapters', array_merge(
            $this->payload(),
            ['subject_id' => $otherSubject->id],
        ))->assertCreated();

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/academic-question-chapters?school_class_id={$this->schoolClass->id}&subject_id={$this->subject->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/academic-question-chapters')
            ->assertForbidden();
    }

    public function test_school_admin_cannot_view_the_chapter_list(): void
    {
        $schoolAdmin = $this->actorWithPermissions([]);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/academic-question-chapters')
            ->assertForbidden();
    }

    public function test_national_admin_can_update_a_chapter(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_question_chapters.create', 'academic_question_chapters.update']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-question-chapters', $this->payload())->json('data.id');

        $payload = $this->payload();
        $payload['chapter_name'] = 'Prose (Revised)';

        $this->actingAs($nationalAdmin, 'sanctum')
            ->putJson("/api/v1/academic-question-chapters/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.chapter_name', 'Prose (Revised)');
    }

    public function test_national_admin_can_delete_a_chapter(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_question_chapters.create', 'academic_question_chapters.delete']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-question-chapters', $this->payload())->json('data.id');

        $this->actingAs($nationalAdmin, 'sanctum')
            ->deleteJson("/api/v1/academic-question-chapters/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('academic_question_chapters', ['id' => $id]);
    }

    public function test_delete_is_forbidden_without_the_delete_permission(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_question_chapters.create']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-question-chapters', $this->payload())->json('data.id');

        $teacher = $this->teacherActorWithPermissions(['academic_question_chapters.view']);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/academic-question-chapters/{$id}")
            ->assertForbidden();
    }
}
