<?php

namespace Tests\Feature;

use App\Models\AcademicGradeConfigure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicGradeConfigureControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_is_public(): void
    {
        AcademicGradeConfigure::create([
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00, 'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/academic-grade-configures')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grade_name', 'A+');
    }

    public function test_show_is_public(): void
    {
        $grade = AcademicGradeConfigure::create([
            'min_percent' => 33, 'max_percent' => 39.99, 'grade_name' => 'D', 'grade_point' => 1.00, 'sort_order' => 6,
        ]);

        $this->getJson("/api/v1/academic-grade-configures/{$grade->id}")
            ->assertOk()
            ->assertJsonPath('data.grade_point', '1.00');
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00,
        ])->assertForbidden();
    }

    public function test_store_creates_a_grade_row(): void
    {
        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'bn_grade_name' => 'এ প্লাস', 'grade_point' => 5.00, 'sort_order' => 1,
        ])->assertCreated()->assertJsonPath('data.grade_name', 'A+');

        $this->assertDatabaseHas('academic_grade_configures', ['grade_name' => 'A+']);
    }

    public function test_store_rejects_an_overlapping_range(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00, 'sort_order' => 2]);

        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 75, 'max_percent' => 85, 'grade_name' => 'Overlap', 'grade_point' => 4.50,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');
    }

    public function test_store_rejects_max_percent_below_min_percent(): void
    {
        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 70, 'grade_name' => 'Bad', 'grade_point' => 4.00,
        ])->assertUnprocessable()->assertJsonValidationErrors('max_percent');
    }

    public function test_update_requires_permission(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'grade_name' => 'Updated',
        ])->assertForbidden();
    }

    public function test_update_allows_re_saving_its_own_unchanged_range(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'min_percent' => 80, 'max_percent' => 100, 'bn_grade_name' => 'এ প্লাস',
        ])->assertOk()->assertJsonPath('data.bn_grade_name', 'এ প্লাস');
    }

    public function test_update_rejects_moving_into_another_rows_range(): void
    {
        $first = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00]);

        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$first->id}", [
            'min_percent' => 75,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');
    }

    public function test_destroy_requires_permission(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-grade-configures/{$grade->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_row(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-grade-configures/{$grade->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_grade_configures', ['id' => $grade->id]);
    }

    public function test_for_percent_finds_the_matching_active_row(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00]);
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 32.99, 'grade_name' => 'F', 'grade_point' => 0.00, 'is_active' => false]);

        $this->assertSame('A', AcademicGradeConfigure::forPercent(75.5)->grade_name);
        $this->assertNull(AcademicGradeConfigure::forPercent(20.0));
    }
}
