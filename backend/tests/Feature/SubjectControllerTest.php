<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SubjectControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_store_does_not_restore_a_different_trashed_subject_via_an_ungrouped_code_match(): void
    {
        $subjectA = Subject::create(['name' => 'ZZ A', 'code' => 'CA']);
        $subjectA->delete();

        $subjectB = Subject::create(['name' => 'ZZ B', 'code' => 'CB']);
        $subjectB->delete();

        $actor = $this->actorWithPermissions(['subjects.create'], null, null);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/subjects', ['name' => 'ZZ A', 'code' => 'CB']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->assertDatabaseHas('subjects', [
            'id' => $subjectA->id,
            'name' => 'ZZ A',
            'code' => 'CA',
        ]);
        $subjectA->refresh();
        $this->assertTrue($subjectA->trashed());

        $this->assertDatabaseHas('subjects', [
            'id' => $subjectB->id,
            'name' => 'ZZ B',
            'code' => 'CB',
        ]);
        $subjectB->refresh();
        $this->assertTrue($subjectB->trashed());
    }

    public function test_store_creates_normally_when_no_code_is_given_and_name_has_no_trashed_match(): void
    {
        Subject::create(['name' => 'Some Other Subject']);
        Subject::create(['name' => 'Live Subject', 'code' => 'LIVE1']);

        $actor = $this->actorWithPermissions(['subjects.create'], null, null);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/subjects', ['name' => 'Brand New Subject']);

        $response->assertCreated();

        $this->assertDatabaseHas('subjects', [
            'name' => 'Brand New Subject',
            'code' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_store_restores_a_trashed_subject_matched_only_on_name(): void
    {
        $subject = Subject::create(['name' => 'Chemistry']);
        $subject->delete();

        $actor = $this->actorWithPermissions(['subjects.create'], null, null);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/subjects', ['name' => 'Chemistry', 'bn_name' => 'রসায়ন']);

        $response->assertCreated();

        $subject->refresh();
        $this->assertFalse($subject->trashed());
        $this->assertSame('রসায়ন', $subject->bn_name);

        $this->assertDatabaseCount('subjects', 1);
    }
}
