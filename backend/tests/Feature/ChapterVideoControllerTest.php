<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
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

class ChapterVideoControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

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

        $this->chapterA = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter 1']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $payload = ['chapter_id' => $this->chapterA->id, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Intro'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapter-videos', $payload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapter_videos.create', 'chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapter-videos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.youtube_video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$id}", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_store_without_order_index_defaults_to_zero(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapter-videos', ['chapter_id' => $this->chapterA->id, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Intro'])
            ->assertCreated()
            ->assertJsonPath('data.order_index', 0);
    }

    public function test_store_rejects_an_unrecognized_youtube_url(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapter-videos', ['chapter_id' => $this->chapterA->id, 'youtube_url' => 'https://vimeo.com/123', 'title' => 'Intro'])
            ->assertUnprocessable();
    }

    public function test_actor_with_permission_can_update_a_video_belonging_to_a_different_schools_chapter(): void
    {
        $video = ChapterVideo::create([
            'chapter_id' => $this->chapterB->id,
            'youtube_video_id' => 'hhhhhhhhhhh',
            'title' => 'School B Video',
        ]);

        $actor = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$video->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_reorder_sets_order_index_from_the_given_sequence(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.create', 'chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $first = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'First', 'order_index' => 0]);
        $second = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Second', 'order_index' => 1]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/chapter-videos/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$second->id, $first->id]])
            ->assertOk();

        $this->assertSame(0, $second->refresh()->order_index);
        $this->assertSame(1, $first->refresh()->order_index);
    }

    public function test_reorder_rejects_an_id_from_a_different_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $foreign = ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'ccccccccccc', 'title' => 'Foreign']);
        $own = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'ddddddddddd', 'title' => 'Own']);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/chapter-videos/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$own->id, $foreign->id]])
            ->assertUnprocessable();
    }

    public function test_index_excludes_disabled_videos_unless_the_caller_can_manage_them(): void
    {
        $enabled = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'eeeeeeeeeee', 'title' => 'Enabled']);
        $disabled = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'fffffffffff', 'title' => 'Disabled', 'is_enabled' => false]);

        $ids = $this->getJson('/api/v1/chapter-videos')->assertOk()->json('data.*.id');
        $this->assertContains($enabled->id, $ids);
        $this->assertNotContains($disabled->id, $ids);

        $manager = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);
        $ids = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/chapter-videos')->assertOk()->json('data.*.id');
        $this->assertContains($disabled->id, $ids);
    }

    public function test_index_filters_by_book_id(): void
    {
        $videoA = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Video A']);
        ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Video B']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/chapter-videos?book_id={$this->bookA->id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $videoA->id);
    }

    /**
     * class_id filters by the class the video's chapter's book's
     * class_version belongs to (via that relation chain), not a column
     * ChapterVideo has.
     */
    public function test_index_filters_by_class_id(): void
    {
        $videoA = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Video A']);
        ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Video B']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapter-videos?class_id={$this->versionA->class_id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $videoA->id);
    }

    /**
     * version_id filters by the national versions catalog (Bangla/
     * English), crossing school/class boundaries the same way the
     * equivalent Book/Chapter filter does.
     */
    public function test_index_filters_by_the_national_version_id(): void
    {
        $englishVersion = ClassVersion::create(['class_id' => $this->versionB->class_id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => Version::where('name', 'English')->value('id')]);
        $englishBook = Book::create(['class_version_id' => $englishVersion->id, 'subject_id' => $this->subject->id, 'name' => 'English Book']);
        $englishChapter = Chapter::create(['book_id' => $englishBook->id, 'name' => 'Chapter 1']);

        $videoA = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Bangla Video (School A)']);
        $videoB = ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Bangla Video (School B)']);
        ChapterVideo::create(['chapter_id' => $englishChapter->id, 'youtube_video_id' => 'ccccccccccc', 'title' => 'English Video']);

        // Unscoped (national_admin-shaped: no school_id/district_id) —
        // ClassVersion is TenantScoped, so whereHas(..., 'classVersion', ...)
        // is itself narrowed by the acting user's own school/district.
        $actor = $this->actorWithPermissions(['chapter_videos.view'], null, null);
        $banglaId = Version::where('name', 'Bangla')->value('id');

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapter-videos?version_id={$banglaId}")
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(
            [$videoA->id, $videoB->id],
            collect($response->json('data'))->pluck('id')->sort()->values()->all(),
        );
    }

    /**
     * subject_id filters by the video's chapter's book's subject.
     */
    public function test_index_filters_by_subject_id(): void
    {
        $scienceSubject = Subject::create(['name' => 'Science']);
        $scienceBook = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $scienceSubject->id, 'name' => 'Science Book']);
        $scienceChapter = Chapter::create(['book_id' => $scienceBook->id, 'name' => 'Chapter 1']);

        $mathVideo = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Math Video']);
        ChapterVideo::create(['chapter_id' => $scienceChapter->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Science Video']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapter-videos?subject_id={$this->subject->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mathVideo->id);
    }

    /**
     * academic_year_id filters by the video's chapter's book's academic
     * year.
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
        $chapterWithYear = Chapter::create(['book_id' => $bookWithYear->id, 'name' => 'Chapter 1']);

        ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'No Year Video']);
        $yearVideo = ChapterVideo::create(['chapter_id' => $chapterWithYear->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Year Video']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapter-videos?academic_year_id={$academicYear->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $yearVideo->id);
    }

    public function test_show_404s_a_disabled_video_unless_the_caller_can_manage_it(): void
    {
        $disabled = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'ggggggggggg', 'title' => 'Disabled', 'is_enabled' => false]);

        $this->getJson("/api/v1/chapter-videos/{$disabled->id}")->assertNotFound();

        $manager = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/chapter-videos/{$disabled->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $disabled->id);
    }
}
