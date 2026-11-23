<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionSeedMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_two_versions_are_seeded_by_migration(): void
    {
        $this->assertSame(2, Version::count());

        $bangla = Version::where('name', 'Bangla')->first();
        $english = Version::where('name', 'English')->first();

        $this->assertNotNull($bangla);
        $this->assertNotNull($english);
        $this->assertTrue($bangla->is_active);
        $this->assertTrue($english->is_active);
    }
}
