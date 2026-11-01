<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterVideoProgressControllerTest extends TestCase
{
    use RefreshDatabase;

    private ChapterVideo $video;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $this->video = ChapterVideo::create([
            'chapter_id' => $chapter->id,
            'school_id' => $chapter->school_id,
            'district_id' => $chapter->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
            'duration_seconds' => 100,
        ]);

        $role = Role::create(['name' => 'student_role', 'label' => 'Student']);
        $this->student = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_first_report_creates_an_in_progress_record(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.max_position_seconds', 10);

        $progress = ChapterVideoProgress::where('chapter_video_id', $this->video->id)->where('user_id', $this->student->id)->first();
        $this->assertNotNull($progress->started_at);
    }

    public function test_crossing_the_default_threshold_marks_completed(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 95])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $progress = ChapterVideoProgress::where('chapter_video_id', $this->video->id)->where('user_id', $this->student->id)->first();
        $this->assertNotNull($progress->completed_at);
    }

    public function test_a_lower_position_never_decreases_the_high_water_mark(): void
    {
        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 80]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 20])
            ->assertOk()
            ->assertJsonPath('data.max_position_seconds', 80);
    }

    public function test_status_never_reverts_from_completed(): void
    {
        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 95]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 5])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_custom_per_video_threshold_overrides_the_default(): void
    {
        $this->video->update(['completion_threshold_percent' => 50]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 55])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_reported_duration_backfills_a_missing_duration(): void
    {
        $this->video->update(['duration_seconds' => null]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10, 'duration_seconds' => 200])
            ->assertOk();

        $this->assertSame(200, $this->video->refresh()->duration_seconds);
    }

    public function test_progress_is_scoped_to_the_authenticated_user_only(): void
    {
        $otherRole = Role::create(['name' => 'other_student', 'label' => 'Student']);
        $other = User::factory()->create(['role_id' => $otherRole->id]);

        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 50]);
        $this->actingAs($other, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10]);

        $this->assertSame(2, ChapterVideoProgress::where('chapter_video_id', $this->video->id)->count());
        $this->assertSame(50, ChapterVideoProgress::where('user_id', $this->student->id)->first()->max_position_seconds);
        $this->assertSame(10, ChapterVideoProgress::where('user_id', $other->id)->first()->max_position_seconds);
    }
}
