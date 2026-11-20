<?php

namespace Tests\Feature;

use App\Models\AcademicExamTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamTermControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_is_public(): void
    {
        AcademicExamTerm::create(['name' => 'First Terminal Examination', 'bn_name' => 'প্রথম সাময়িক পরীক্ষা', 'sort_order' => 1]);

        $this->getJson('/api/v1/academic-exam-terms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'First Terminal Examination');
    }

    public function test_show_is_public(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);

        $this->getJson("/api/v1/academic-exam-terms/{$term->id}")
            ->assertOk()
            ->assertJsonPath('data.is_annual', true);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'First Terminal Examination',
        ])->assertForbidden();
    }

    public function test_store_creates_a_term(): void
    {
        $user = $this->actorWithPermissions(['academic_exam_terms.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'First Terminal Examination',
            'bn_name' => 'প্রথম সাময়িক পরীক্ষা',
            'sort_order' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'First Terminal Examination')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('academic_exam_terms', ['name' => 'First Terminal Examination', 'is_active' => true]);
    }

    public function test_store_rejects_a_second_annual_term(): void
    {
        AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);

        $user = $this->actorWithPermissions(['academic_exam_terms.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'Final Examination',
            'sort_order' => 4,
            'is_annual' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_annual');
    }

    public function test_update_requires_permission(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$term->id}", [
            'name' => 'Updated',
        ])->assertForbidden();
    }

    public function test_update_rejects_marking_a_second_term_annual(): void
    {
        AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $second = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);

        $user = $this->actorWithPermissions(['academic_exam_terms.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$second->id}", [
            'is_annual' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_annual');
    }

    public function test_update_allows_re_saving_the_existing_annual_term(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $user = $this->actorWithPermissions(['academic_exam_terms.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$term->id}", [
            'name' => 'Annual/Final Examination',
            'is_annual' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Annual/Final Examination');
    }

    public function test_store_coerces_a_null_sort_order_to_zero(): void
    {
        $user = $this->actorWithPermissions(['academic_exam_terms.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'First Terminal Examination',
            'sort_order' => null,
        ])->assertCreated()->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('academic_exam_terms', ['name' => 'First Terminal Examination', 'sort_order' => 0]);
    }

    public function test_update_coerces_a_null_sort_order_to_zero(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);
        $user = $this->actorWithPermissions(['academic_exam_terms.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$term->id}", [
            'sort_order' => null,
        ])->assertOk()->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('academic_exam_terms', ['id' => $term->id, 'sort_order' => 0]);
    }

    public function test_destroy_requires_permission(): void
    {
        $term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-exam-terms/{$term->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_term(): void
    {
        $term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $user = $this->actorWithPermissions(['academic_exam_terms.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-exam-terms/{$term->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_exam_terms', ['id' => $term->id]);
    }
}
