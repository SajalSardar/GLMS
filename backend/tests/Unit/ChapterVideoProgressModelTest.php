<?php

namespace Tests\Unit;

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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterVideoProgressModelTest extends TestCase
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
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->classVersion->school_id, 'district_id' => $book->classVersion->district_id, 'name' => 'Chapter 1']);

        $this->video = ChapterVideo::create([
            'chapter_id' => $chapter->id,
            'school_id' => $chapter->book->classVersion->school_id,
            'district_id' => $chapter->book->classVersion->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
        ]);

        $role = Role::create(['name' => 'student_role', 'label' => 'Student']);
        $this->student = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_progress_row_defaults_to_not_started_and_zero_position(): void
    {
        $progress = ChapterVideoProgress::create([
            'chapter_video_id' => $this->video->id,
            'user_type' => User::class,
            'user_id' => $this->student->id,
        ]);

        $this->assertSame('not_started', $progress->status);
        $this->assertSame(0, $progress->max_position_seconds);
    }

    public function test_only_one_progress_row_per_video_and_user(): void
    {
        ChapterVideoProgress::create(['chapter_video_id' => $this->video->id, 'user_type' => User::class, 'user_id' => $this->student->id]);

        $this->expectException(QueryException::class);

        ChapterVideoProgress::create(['chapter_video_id' => $this->video->id, 'user_type' => User::class, 'user_id' => $this->student->id]);
    }
}
