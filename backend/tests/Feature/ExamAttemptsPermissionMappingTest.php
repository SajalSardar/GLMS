<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamAttemptsPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list from Task 2's own brief: granting
     * exam_attempts.view to a role that shouldn't have it (student's
     * own attempt access is ownership-based, not a flat permission; a
     * parent_guardian's access to a linked child is a Policy
     * relationship check, not this permission) would be a real
     * authorization regression, not just redundancy.
     */
    public function test_exam_attempts_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rolesWithView = Role::whereHas('permissions', fn ($q) => $q->where('name', 'exam_attempts.view'))->pluck('name')->sort()->values()->all();
        $rolesWithCreate = Role::whereHas('permissions', fn ($q) => $q->where('name', 'exam_attempts.create'))->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['district_officer', 'national_admin', 'school_admin', 'teacher', 'trainer'],
            $rolesWithView
        );

        $this->assertSame(['national_admin', 'student'], $rolesWithCreate);
    }
}
