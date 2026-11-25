<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicQuestionPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * academic_questions.create/update/delete must map to exactly
     * national_admin — no other role may write to the national question
     * bank. .view additionally maps to teacher (to browse the bank when
     * building a paper), but never school_admin (it only prints
     * already-saved papers — see question_papers.* instead).
     */
    public function test_academic_question_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'academic_questions.view' => ['national_admin', 'teacher'],
            'academic_questions.create' => ['national_admin'],
            'academic_questions.update' => ['national_admin'],
            'academic_questions.delete' => ['national_admin'],
        ];

        foreach ($expected as $permissionName => $expectedRoles) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roles, "Expected {$permissionName} to be mapped to exactly: ".implode(', ', $expectedRoles));
        }
    }
}
