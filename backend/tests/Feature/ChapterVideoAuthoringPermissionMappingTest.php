<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterVideoAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_videos_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapter_videos.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
