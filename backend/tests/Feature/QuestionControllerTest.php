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

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);
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
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_text', 'What is 2+2?')
            ->assertJsonCount(2, 'data.options')
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/questions/{$id}", ['question_text' => 'What is 2+2, really?'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'What is 2+2, really?')
            ->assertJsonCount(2, 'data.options');
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

    public function test_actor_with_permission_gets_404_updating_another_schools_question(): void
    {
        $question = Question::create([
            'chapter_id' => $this->chapterB->id,
            'school_id' => $this->chapterB->school_id,
            'district_id' => $this->chapterB->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'School B question',
        ]);

        $actor = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/questions/{$question->id}", ['question_text' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_index_excludes_inactive_questions_unless_the_caller_can_manage_them(): void
    {
        $enabled = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Enabled']);
        $disabled = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Disabled', 'is_active' => false]);

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
}
