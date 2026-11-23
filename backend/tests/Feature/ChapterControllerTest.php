<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
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

class ChapterControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $bookA;

    private Book $bookB;

    private School $schoolA;

    private School $schoolB;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

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

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);

        $this->bookA = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Book']);
        $this->bookB = Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Book']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Existing Chapter']);
        $payload = ['book_id' => $this->bookA->id, 'name' => 'New Chapter'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_index_filters_by_book_id(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['chapters.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/chapters?book_id={$this->bookA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * class_id filters by the class the chapter's book's class_version
     * belongs to (via that relation chain), not a column Chapter has.
     */
    public function test_index_filters_by_class_id(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter A']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters?class_id={$this->versionA->class_id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Chapter A');
    }

    /**
     * version_id filters by the national versions catalog (Bangla/
     * English), crossing school/class boundaries the same way the
     * equivalent Book filter does.
     */
    public function test_index_filters_by_the_national_version_id(): void
    {
        $englishVersion = ClassVersion::create(['class_id' => $this->versionB->class_id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => Version::where('name', 'English')->value('id')]);
        $englishBook = Book::create(['class_version_id' => $englishVersion->id, 'subject_id' => $this->subject->id, 'name' => 'English Book']);

        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Bangla Chapter (School A)']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Bangla Chapter (School B)']);
        Chapter::create(['book_id' => $englishBook->id, 'name' => 'English Chapter']);

        // Unscoped (national_admin-shaped: no school_id/district_id) —
        // ClassVersion is TenantScoped, so whereHas('book.classVersion', ...)
        // is itself narrowed by the acting user's own school/district.
        $actor = $this->actorWithPermissions(['chapters.view'], null, null);
        $banglaId = Version::where('name', 'Bangla')->value('id');

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters?version_id={$banglaId}")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(
            ['Bangla Chapter (School A)', 'Bangla Chapter (School B)'],
            collect($response->json('data'))->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $chapterA = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter A']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapters')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $chapterA->id);
    }

    public function test_show_404s_a_school_scoped_actor_for_another_schools_chapter(): void
    {
        $chapterB = Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters/{$chapterB->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_a_school_scoped_actor_targeting_another_schools_book(): void
    {
        $actor = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookB->id, 'name' => 'Cross School Chapter'])
            ->assertNotFound();
    }

    public function test_national_admin_still_sees_every_schools_chapters(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter A']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view']);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapters')->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * subject_id filters by the chapter's book's subject.
     */
    public function test_index_filters_by_subject_id(): void
    {
        $scienceSubject = Subject::create(['name' => 'Science']);
        $scienceBook = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $scienceSubject->id, 'name' => 'Science Book']);

        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Math Chapter']);
        Chapter::create(['book_id' => $scienceBook->id, 'name' => 'Science Chapter']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters?subject_id={$this->subject->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Math Chapter');
    }

    /**
     * academic_year_id filters by the chapter's book's academic year.
     */
    public function test_index_filters_by_academic_year_id(): void
    {
        $academicYear = AcademicYear::create([
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_current' => true,
        ]);
        $bookWithYear = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $academicYear->id, 'name' => 'Book With Year']);

        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'No Year Chapter']);
        Chapter::create(['book_id' => $bookWithYear->id, 'name' => 'Year Chapter']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters?academic_year_id={$academicYear->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Year Chapter');
    }

    public function test_actor_with_permission_can_update_a_chapter_belonging_to_a_different_schools_book(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookB->id, 'name' => 'School B Chapter']);

        $actor = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_update_silently_ignores_an_attempt_to_change_book_id(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);

        $allowed = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['book_id' => $this->bookB->id, 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.book_id', $this->bookA->id);

        $this->assertSame($this->bookA->id, $chapter->refresh()->book_id);
    }

    public function test_store_restores_a_soft_deleted_chapter_with_the_same_identity(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);
        $chapter->delete();

        $allowed = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookA->id, 'name' => 'Chapter 1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $chapter->id);
    }
}
