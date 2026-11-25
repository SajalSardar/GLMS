<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionPaperPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * question_papers.view maps to national_admin, school_admin, and
     * teacher — school_admin print-only (gated to view by the product
     * decision, never update/delete), teacher additionally holds create/
     * update/delete (update/delete further narrowed to the paper's own
     * creator by QuestionPaperPolicy, not expressible at the role level).
     */
    public function test_question_paper_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'question_papers.view' => ['national_admin', 'school_admin', 'teacher'],
            'question_papers.create' => ['national_admin', 'teacher'],
            'question_papers.update' => ['national_admin', 'teacher'],
            'question_papers.delete' => ['national_admin', 'teacher'],
        ];

        foreach ($expected as $permissionName => $expectedRoles) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roles, "Expected {$permissionName} to be mapped to exactly: ".implode(', ', $expectedRoles));
        }
    }
}
