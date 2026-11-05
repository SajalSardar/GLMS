<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableEntriesGeneratePermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list timetable_entries.generate is mapped to
     * (school_admin explicitly in RolePermissionSeeder; national_admin via
     * its all-permissions wildcard) — granting this to any other role
     * would let it bulk-replace every section's draft entries under a
     * shift, a real authorization regression, not just redundancy. Same
     * pattern already established for student_progress.view and
     * exam_attempts.* in prior sub-projects.
     */
    public function test_timetable_entries_generate_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'timetable_entries.generate'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['national_admin', 'school_admin'],
            $roles
        );
    }
}
