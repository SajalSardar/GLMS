<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards against a repeat of the ChapterSeeder regression, where a
     * seeder referencing a dropped column (subject_id on chapters) aborted
     * the entire seed chain before permissions were ever seeded.
     */
    public function test_full_seed_chain_completes_without_error(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Permission::where('name', 'books.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_materials.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_videos.view')->exists());
    }
}
