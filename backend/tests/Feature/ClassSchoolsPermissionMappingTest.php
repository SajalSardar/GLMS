<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSchoolsPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * class_schools.create is mapped to exactly the roles that may manage
     * a school's own class offering — school_admin (their own school) and
     * national_admin via its all-permissions wildcard.
     */
    public function test_class_schools_create_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'class_schools.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin', 'school_admin'], $roles);
    }
}
