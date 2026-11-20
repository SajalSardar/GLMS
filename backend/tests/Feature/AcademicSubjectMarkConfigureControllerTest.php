<?php

namespace Tests\Feature;

use App\Models\AcademicSubjectMarkConfigure;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicSubjectMarkConfigureControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $class;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $this->subject = Subject::create(['name' => 'Mathematics']);
    }

    public function test_index_is_public(): void
    {
        AcademicSubjectMarkConfigure::create([
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ]);

        $this->getJson('/api/v1/academic-subject-mark-configures')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cq_full_marks', 70);
    }

    public function test_show_is_public(): void
    {
        $configure = AcademicSubjectMarkConfigure::create([
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 50, 'mcq_full_marks' => 25, 'practical_full_marks' => 25,
        ]);

        $this->getJson("/api/v1/academic-subject-mark-configures/{$configure->id}")
            ->assertOk()
            ->assertJsonPath('data.practical_full_marks', 25);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ])->assertForbidden();
    }

    public function test_store_creates_a_configure_row(): void
    {
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ])->assertCreated()->assertJsonPath('data.cq_full_marks', 70);

        $this->assertDatabaseHas('academic_subject_mark_configures', ['class_id' => $this->class->id, 'subject_id' => $this->subject->id]);
    }

    public function test_store_rejects_all_three_components_null(): void
    {
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('cq_full_marks');
    }

    public function test_store_rejects_a_duplicate_class_subject_pair(): void
    {
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_update_requires_permission(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'cq_full_marks' => 80,
        ])->assertForbidden();
    }

    public function test_update_changes_full_marks(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'cq_full_marks' => 80,
        ])->assertOk()->assertJsonPath('data.cq_full_marks', 80);
    }

    public function test_update_rejects_a_partial_update_that_collides_with_another_row(): void
    {
        $english = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'cq_full_marks' => 60, 'mcq_full_marks' => 40]);

        $user = $this->actorWithPermissions(['academic_subject_mark_configures.update']);

        // Submits only subject_id — class_id is absent, so the request's
        // effective class_id falls back to the row's own stored value
        // (the class above). The resulting (class, subject) pair collides
        // with the first row, even though class_id itself was never sent.
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'subject_id' => $this->subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');
    }

    public function test_update_rejects_all_three_components_null(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'cq_full_marks' => null,
            'mcq_full_marks' => null,
            'practical_full_marks' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('cq_full_marks');
    }

    public function test_destroy_requires_permission(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-subject-mark-configures/{$configure->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_row(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-subject-mark-configures/{$configure->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_subject_mark_configures', ['id' => $configure->id]);
    }

    public function test_total_full_marks_sums_only_configured_components(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $this->assertSame(100, $configure->totalFullMarks());
    }
}
