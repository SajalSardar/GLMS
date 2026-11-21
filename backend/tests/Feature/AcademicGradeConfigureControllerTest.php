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

        // Fix F (gap coverage) note: spans the full 0-100 range (rather than
        // the previous 80-100) since this is now the ONLY active row and a
        // partial range would trip the new no-gap-coverage check — not the
        // behavior under test here.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 0, 'max_percent' => 100, 'grade_name' => 'A+', 'bn_grade_name' => 'এ প্লাস', 'grade_point' => 5.00, 'sort_order' => 1,
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
        // Fix F (gap coverage): a filler row covering the rest of 0-100 so
        // the full active set has no gap — this test's point is re-saving
        // the SAME range, not the new coverage check.
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 79.99, 'grade_name' => 'F', 'grade_point' => 0.00]);
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

    public function test_update_with_only_max_percent_valid_against_stored_min_succeeds(): void
    {
        // Fix F (gap coverage) note: the fixture deliberately leaves a
        // 95.01-100 gap below the full range BEFORE the PATCH (seeded
        // directly via the model, bypassing request validation) so that
        // closing it by raising max_percent to 100 is itself proof the
        // effective min (90, not submitted) was used correctly — the
        // original regression this test guards against. The previous
        // version of this test shrank max_percent instead, which the new
        // no-gap-coverage check correctly rejects (see
        // test_update_rejects_shrinking_max_percent_into_a_gap below).
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 89.99, 'grade_name' => 'F', 'grade_point' => 0.00]);
        $grade = AcademicGradeConfigure::create(['min_percent' => 90, 'max_percent' => 95, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'max_percent' => 100,
        ])->assertOk()->assertJsonPath('data.max_percent', '100.00');
    }

    public function test_update_with_only_min_percent_inverted_against_stored_max_rejected(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 60, 'max_percent' => 70, 'grade_name' => 'B', 'grade_point' => 3.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        // Regression test: PATCH with only min_percent set higher than stored max_percent
        // should be rejected with validation error on max_percent (data-integrity protection)
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'min_percent' => 75,
        ])->assertUnprocessable()->assertJsonValidationErrors('max_percent');
    }

    public function test_store_coerces_a_null_sort_order_to_zero(): void
    {
        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        // Fix F (gap coverage): spans the full 0-100 range — see the note
        // on test_store_creates_a_grade_row above.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 0, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00, 'sort_order' => null,
        ])->assertCreated()->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('academic_grade_configures', ['grade_name' => 'A+', 'sort_order' => 0]);
    }

    public function test_update_coerces_a_null_sort_order_to_zero(): void
    {
        // Fix F (gap coverage): filler row, same reasoning as
        // test_update_allows_re_saving_its_own_unchanged_range above.
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 79.99, 'grade_name' => 'F', 'grade_point' => 0.00]);
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00, 'sort_order' => 1]);
        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'sort_order' => null,
        ])->assertOk()->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('academic_grade_configures', ['id' => $grade->id, 'sort_order' => 0]);
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

    public function test_store_rejects_a_range_that_leaves_a_gap_in_coverage(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 50, 'grade_name' => 'Low', 'grade_point' => 2.00]);
        AcademicGradeConfigure::create(['min_percent' => 50.01, 'max_percent' => 90, 'grade_name' => 'Mid', 'grade_point' => 4.00]);

        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        // Combined with the existing 0-50 and 50.01-90 active rows,
        // 90.01-99 leaves 99.01-100 uncovered.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 90.01, 'max_percent' => 99, 'grade_name' => 'High', 'grade_point' => 5.00,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');

        $this->assertStringContainsString('gap', $response->json('errors.min_percent.0'));
    }

    public function test_store_accepts_a_range_that_closes_an_existing_gap(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 50, 'grade_name' => 'Low', 'grade_point' => 2.00]);
        AcademicGradeConfigure::create(['min_percent' => 50.01, 'max_percent' => 90, 'grade_name' => 'Mid', 'grade_point' => 4.00]);

        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 90.01, 'max_percent' => 100, 'grade_name' => 'High', 'grade_point' => 5.00,
        ])->assertCreated();
    }

    public function test_store_ignores_inactive_rows_for_both_overlap_and_gap_checks(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 100, 'grade_name' => 'Inactive Full', 'grade_point' => 3.00, 'is_active' => false]);

        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        // No active rows exist at all, so this new inactive row must not be
        // required to close any gap, nor be checked for overlap against the
        // other (also inactive) row above.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 40, 'max_percent' => 60, 'grade_name' => 'Also Inactive', 'grade_point' => 2.50, 'is_active' => false,
        ])->assertCreated();
    }

    public function test_update_rejects_shrinking_max_percent_into_a_gap(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 79.99, 'grade_name' => 'F', 'grade_point' => 0.00]);
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);

        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        // Shrinking the top row's max_percent down to 90 reopens a
        // 90.01-100 gap in the full active set.
        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'max_percent' => 90,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');

        $this->assertStringContainsString('gap', $response->json('errors.min_percent.0'));
        $this->assertSame('100.00', $grade->refresh()->max_percent);
    }

    public function test_update_allows_deactivating_a_row_even_if_its_new_range_would_overlap_or_gap(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $spare = AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 100, 'grade_name' => 'Spare', 'grade_point' => 5.00, 'is_active' => false]);

        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        // $spare's new range (50-75) would both overlap the first row and
        // leave a gap if it were active — but it's being set to
        // is_active: false, so neither check should run.
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$spare->id}", [
            'min_percent' => 50, 'max_percent' => 75, 'is_active' => false,
        ])->assertOk();
    }
}
