<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * academic_years is national calendar data now — only national_admin
     * may author it. school_admin previously held create/update/delete;
     * granting it to any other role here would be a real authorization
     * regression, not just redundancy.
     */
    public function test_academic_years_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'academic_years.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
