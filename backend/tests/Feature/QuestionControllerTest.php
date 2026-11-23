<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
    }

    private function mcqPayload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapterA->id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
            'options' => [
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => true],
            ],
        ], $overrides);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())->assertForbidden();

        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_text', 'What is 2+2?')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/questions/{$id}", ['question_text' => 'What is 2+2, really?'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'What is 2+2, really?')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_store_rejects_true_false_without_exactly_two_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'true_false',
            'options' => [
                ['option_text' => 'True', 'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
                ['option_text' => 'Maybe', 'is_correct' => false],
            ],
        ]))->assertUnprocessable();
    }

    public function test_store_rejects_mcq_single_with_two_correct_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'options' => [
                ['option_text' => '3', 'is_correct' => true],
                ['option_text' => '4', 'is_correct' => true],
            ],
        ]))->assertUnprocessable();
    }

    public function test_store_rejects_mcq_multiple_with_no_correct_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'mcq_multiple',
            'options' => [
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => false],
            ],
        ]))->assertUnprocessable();
    }

    public function test_store_rejects_more_than_six_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'mcq_multiple',
            'options' => [
                ['option_text' => '1', 'is_correct' => true],
                ['option_text' => '2', 'is_correct' => false],
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => false],
                ['option_text' => '5', 'is_correct' => false],
                ['option_text' => '6', 'is_correct' => false],
                ['option_text' => '7', 'is_correct' => false],
            ],
        ]))->assertUnprocessable();
    }

    public function test_update_reapplies_option_invariants_against_stored_options_when_only_question_type_changes(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        // 3 options, 2 correct — valid for mcq_multiple.
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'mcq_multiple',
            'options' => [
                ['option_text' => '1', 'is_correct' => true],
                ['option_text' => '2', 'is_correct' => true],
                ['option_text' => '3', 'is_correct' => false],
            ],
        ]))->assertCreated()->json('data.id');

        // Switching to mcq_single without resending options must still be
        // checked against the stored options: 2 correct options is
        // invalid for mcq_single (requires exactly 1).
        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/questions/{$id}", ['question_type' => 'mcq_single'])
            ->assertUnprocessable();
    }

    public function test_actor_with_permission_can_update_a_question_belonging_to_a_different_schools_chapter(): void
    {
        $question = Question::create([
            'chapter_id' => $this->chapterB->id,
            'question_type' => 'mcq_single',
            'question_text' => 'School B Question',
        ]);

        $actor = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/questions/{$question->id}", ['question_text' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'Renamed');
    }

    public function test_index_excludes_inactive_questions_unless_the_caller_can_manage_them(): void
    {
        $enabled = Question::create(['chapter_id' => $this->chapterA->id, 'question_type' => 'mcq_single', 'question_text' => 'Enabled']);
        $disabled = Question::create(['chapter_id' => $this->chapterA->id, 'question_type' => 'mcq_single', 'question_text' => 'Disabled', 'is_active' => false]);

        $ids = $this->getJson('/api/v1/questions')->assertOk()->json('data.*.id');
        $this->assertContains($enabled->id, $ids);
        $this->assertNotContains($disabled->id, $ids);

        $manager = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $ids = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/questions')->assertOk()->json('data.*.id');
        $this->assertContains($disabled->id, $ids);
    }

    public function test_is_correct_is_hidden_from_a_viewer_without_update_permission(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())->json('data.id');

        $viewer = $this->actorWithPermissions(['questions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/questions/{$id}")->assertOk();
        $this->assertArrayNotHasKey('is_correct', $response->json('data.options.0'));

        $manager = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/questions/{$id}")->assertOk();
        $this->assertArrayHasKey('is_correct', $response->json('data.options.0'));
    }

    public function test_store_with_image_uploads_and_destroy_removes_the_file(): void
    {
        Storage::fake('public');

        $allowed = $this->actorWithPermissions(['questions.create', 'questions.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $image = UploadedFile::fake()->image('diagram.png');

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/questions', array_merge(
            $this->mcqPayload(),
            ['image' => $image]
        ))->assertCreated();

        $path = $response->json('data.image_url');
        $this->assertNotNull($path);

        $question = Question::findOrFail($response->json('data.id'));
        Storage::disk('public')->assertExists($question->image_path);

        $this->actingAs($allowed, 'sanctum')->deleteJson("/api/v1/questions/{$question->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($question->image_path);
    }

    public function test_stored_image_extension_is_derived_from_file_content_not_the_client_supplied_filename(): void
    {
        Storage::fake('public');

        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        // ".php" itself can't reach the controller at all here: Laravel's
        // own `mimes` rule has a hardcoded guard (shouldBlockPhpUpload)
        // that rejects a client filename ending in .php/.php3-8/.phtml/
        // .phar outright, independent of content. ".pht" is a real,
        // historically-used alternate PHP-execution extension on
        // misconfigured Apache setups that ISN'T in that hardcoded list,
        // so it sails through validation once the (genuine, validated)
        // MIME type is an allowed image type — exactly the attacker
        // scenario: real image bytes, a dangerous original filename.
        // getClientOriginalExtension() would trust that filename; the
        // fix must instead derive the stored extension from the real,
        // validated MIME type via ->mimeType().
        $maliciousImage = UploadedFile::fake()->image('shell.pht')->mimeType('image/jpeg');

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/questions', array_merge(
            $this->mcqPayload(),
            ['image' => $maliciousImage]
        ))->assertCreated();

        $question = Question::findOrFail($response->json('data.id'));
        $this->assertStringEndsWith('.jpg', $question->image_path);
        Storage::disk('public')->assertExists($question->image_path);

        $secondMaliciousImage = UploadedFile::fake()->image('backdoor.pht')->mimeType('image/png');

        $this->actingAs($allowed, 'sanctum')
            ->put("/api/v1/questions/{$question->id}", ['image' => $secondMaliciousImage])
            ->assertOk();

        $question->refresh();
        $this->assertStringEndsWith('.png', $question->image_path);
        Storage::disk('public')->assertExists($question->image_path);
    }

    public function test_reorder_sets_order_index_from_the_given_sequence(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $first = Question::create(['chapter_id' => $this->chapterA->id, 'question_type' => 'mcq_single', 'question_text' => 'First', 'order_index' => 0]);
        $second = Question::create(['chapter_id' => $this->chapterA->id, 'question_type' => 'mcq_single', 'question_text' => 'Second', 'order_index' => 1]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$second->id, $first->id]])
            ->assertOk();

        $this->assertSame(0, $second->refresh()->order_index);
        $this->assertSame(1, $first->refresh()->order_index);
    }

    public function test_reorder_rejects_an_id_from_a_different_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $foreign = Question::create(['chapter_id' => $this->chapterB->id, 'question_type' => 'mcq_single', 'question_text' => 'Foreign']);
        $own = Question::create(['chapter_id' => $this->chapterA->id, 'question_type' => 'mcq_single', 'question_text' => 'Own']);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$own->id, $foreign->id]])
            ->assertUnprocessable();
    }

    public function test_reorder_is_forbidden_without_update_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => []])
            ->assertForbidden();
    }
}
