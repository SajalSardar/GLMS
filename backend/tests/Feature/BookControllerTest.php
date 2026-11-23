<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
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

    private AcademicYear $academicYear;

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

        $this->academicYear = AcademicYear::create([
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_current' => true,
        ]);

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Existing Book',
        ]);
        $payload = ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'name' => 'New Book'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/books', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_multiple_books_allowed_per_class_version_and_subject(): void
    {
        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'name' => 'Mathematics'])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'name' => 'Mathematics Practice Book'])
            ->assertCreated();

        $this->assertSame(2, Book::where('class_version_id', $this->versionA->id)->where('subject_id', $this->subject->id)->count());
    }

    public function test_actor_with_permission_can_update_a_book_belonging_to_a_different_schools_version(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionB->id,
            'subject_id' => $this->subject->id,
            'name' => 'School B Book',
        ]);

        $actor = $this->actorWithPermissions(['books.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_store_restores_a_soft_deleted_book_with_the_same_identity(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Mathematics',
        ]);
        $book->delete();

        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'name' => 'Mathematics'])
            ->assertCreated()
            ->assertJsonPath('data.id', $book->id);
    }

    /**
     * The management Books page can't show which class a book belongs to
     * (or filter by it) unless the response actually carries the class —
     * index()/show() must eager-load classVersion.schoolClass, not just
     * classVersion.
     */
    public function test_index_includes_the_books_class_name(): void
    {
        Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics']);

        $allowed = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($allowed, 'sanctum')->getJson('/api/v1/books')->assertOk();

        $response->assertJsonPath('data.0.class_version.class.name', 'Class 6');
    }

    /**
     * class_id filters by the class the book's class_version belongs to
     * (via that relation), not a column Book has directly.
     */
    public function test_index_filters_by_class_id(): void
    {
        Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Class A Book']);
        Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Class B Book']);

        $allowed = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->getJson("/api/v1/books?class_id={$this->versionA->class_id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Class A Book');
    }

    /**
     * version_id filters by the national versions catalog (Bangla/
     * English), not by a specific class_versions row — a book whose
     * class_version links to the same global version as another book
     * (even across different classes/schools) matches too.
     */
    public function test_index_filters_by_the_national_version_id(): void
    {
        $englishVersion = ClassVersion::create(['class_id' => $this->versionB->class_id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => Version::where('name', 'English')->value('id')]);

        Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Bangla Book (School A)']);
        Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Bangla Book (School B)']);
        Book::create(['class_version_id' => $englishVersion->id, 'subject_id' => $this->subject->id, 'name' => 'English Book']);

        // Unscoped (national_admin-shaped: no school_id/district_id) —
        // ClassVersion is TenantScoped, so whereHas('classVersion', ...)
        // is itself narrowed by the acting user's own school/district,
        // the same as any other query against that model. A School-A-
        // scoped actor would correctly see only School A's Bangla book
        // here; this test is specifically about version_id crossing
        // school/class boundaries, so it needs an actor who can see both.
        $allowed = $this->actorWithPermissions(['books.view']);
        $banglaId = Version::where('name', 'Bangla')->value('id');

        $response = $this->actingAs($allowed, 'sanctum')
            ->getJson("/api/v1/books?version_id={$banglaId}")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(
            ['Bangla Book (School A)', 'Bangla Book (School B)'],
            collect($response->json('data'))->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $bookA = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Book A']);
        Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/books')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $bookA->id);
    }

    public function test_show_404s_a_school_scoped_actor_for_another_schools_book(): void
    {
        $bookB = Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/books/{$bookB->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_a_school_scoped_actor_targeting_another_schools_class_version(): void
    {
        $actor = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/books', [
                'class_version_id' => $this->versionB->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->academicYear->id,
                'name' => 'Cross School Book',
            ])
            ->assertNotFound();
    }

    public function test_national_admin_still_sees_every_schools_books(): void
    {
        $bookA = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Book A']);
        $bookB = Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view']);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/books')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
    }

    public function test_destroy_is_blocked_while_the_book_still_has_chapters(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Mathematics',
        ]);
        $book->chapters()->create(['name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['books.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}")
            ->assertUnprocessable();
    }
}
