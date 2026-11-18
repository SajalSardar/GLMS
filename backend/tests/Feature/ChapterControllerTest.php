<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $bookA;

    private Book $bookB;

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

        $this->bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $this->bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->classVersion->school_id, 'district_id' => $this->bookA->classVersion->district_id, 'name' => 'Existing Chapter']);
        $payload = ['book_id' => $this->bookA->id, 'name' => 'New Chapter'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id)
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_index_filters_by_book_id(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->classVersion->school_id, 'district_id' => $this->bookA->classVersion->district_id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $this->bookB->id, 'school_id' => $this->bookB->classVersion->school_id, 'district_id' => $this->bookB->classVersion->district_id, 'name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['chapters.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/chapters?book_id={$this->bookA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_chapter(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookB->id, 'school_id' => $this->bookB->classVersion->school_id, 'district_id' => $this->bookB->classVersion->district_id, 'name' => 'School B Chapter']);

        $actor = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_update_silently_ignores_an_attempt_to_change_book_id(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->classVersion->school_id, 'district_id' => $this->bookA->classVersion->district_id, 'name' => 'Chapter 1']);

        $allowed = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['book_id' => $this->bookB->id, 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.book_id', $this->bookA->id);

        $this->assertSame($this->bookA->id, $chapter->refresh()->book_id);
    }

    public function test_store_restores_a_soft_deleted_chapter_with_the_same_identity(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->classVersion->school_id, 'district_id' => $this->bookA->classVersion->district_id, 'name' => 'Chapter 1']);
        $chapter->delete();

        $allowed = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookA->id, 'name' => 'Chapter 1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $chapter->id);
    }
}
