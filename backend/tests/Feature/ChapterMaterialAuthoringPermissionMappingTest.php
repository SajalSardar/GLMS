<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterMaterialAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * chapter_materials.create is now also granted to `teacher`, since
     * teachers upload their own class's materials — scoped at the
     * Policy layer (SubjectTeacherAssignment match), not by withholding
     * the permission. national_admin keeps it via $allPermissions.
     * Granting it to any OTHER role here would be a real authorization
     * regression, not just redundancy.
     */
    public function test_chapter_materials_create_is_mapped_to_national_admin_and_teacher(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapter_materials.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin', 'teacher'], $roles);
    }
}
