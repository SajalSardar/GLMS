<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * questions is national content now — only national_admin may author
     * it. teacher previously held questions.create/update; granting it
     * to any other role here would be a real authorization regression.
     */
    public function test_questions_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'questions.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
