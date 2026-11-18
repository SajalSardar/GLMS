<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * books is national content now — only national_admin may author it.
     * trainer previously held books.create/update; granting it to any
     * other role here would be a real authorization regression.
     */
    public function test_books_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'books.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
