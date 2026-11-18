<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapters_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapters.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
