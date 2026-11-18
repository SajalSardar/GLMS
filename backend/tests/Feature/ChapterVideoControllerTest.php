<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterVideoControllerTest extends TestCase
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

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
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
