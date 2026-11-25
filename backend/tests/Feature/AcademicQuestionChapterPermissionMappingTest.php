<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicQuestionChapterPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * academic_question_chapters.create/update/delete must map to exactly
     * national_admin — same tier as the question bank itself. .view
     * additionally maps to teacher (to populate the chapter picker when
     * filtering/building a paper), but never school_admin.
     */
    public function test_academic_question_chapter_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'academic_question_chapters.view' => ['national_admin', 'teacher'],
            'academic_question_chapters.create' => ['national_admin'],
            'academic_question_chapters.update' => ['national_admin'],
            'academic_question_chapters.delete' => ['national_admin'],
        ];

        foreach ($expected as $permissionName => $expectedRoles) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roles, "Expected {$permissionName} to be mapped to exactly: ".implode(', ', $expectedRoles));
        }
    }
}
