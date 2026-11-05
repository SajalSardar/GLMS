<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProgressPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list this task's brief specifies — granting
     * student_progress.view to a role that shouldn't have it (a
     * student's own dashboard access is ownership-based, a
     * parent_guardian's access to a linked child is a Policy
     * relationship check, neither is this flat permission) would be a
     * real authorization regression, not just redundancy. Same pattern
     * already established for exam_attempts.* in the prior sub-project.
     */
    public function test_student_progress_view_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'student_progress.view'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['district_officer', 'national_admin', 'school_admin', 'teacher', 'trainer'],
            $roles
        );
    }
}
