<?php

namespace Tests\Feature;

use App\Models\Book;
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

class BookControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

    private School $schoolA;

    private School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Bangla Version']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Existing Book',
        ]);
        $payload = ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'New Book'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/books', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id)
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }

    public function test_multiple_books_allowed_per_class_version_and_subject(): void
    {
        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Practice Book'])
            ->assertCreated();

        $this->assertSame(2, Book::where('class_version_id', $this->versionA->id)->where('subject_id', $this->subject->id)->count());
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_book(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionB->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionB->school_id,
            'district_id' => $this->versionB->district_id,
            'name' => 'School B Book',
        ]);

        $actor = $this->actorWithPermissions(['books.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_store_restores_a_soft_deleted_book_with_the_same_identity(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Mathematics',
        ]);
        $book->delete();

        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated()
            ->assertJsonPath('data.id', $book->id);
    }

    public function test_destroy_is_blocked_while_the_book_still_has_chapters(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Mathematics',
        ]);
        $book->chapters()->create([
            'name' => 'Chapter 1',
            'school_id' => $book->school_id,
            'district_id' => $book->district_id,
        ]);

        $actor = $this->actorWithPermissions(['books.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}")
            ->assertUnprocessable();
    }
}
