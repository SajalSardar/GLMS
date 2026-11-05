<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use App\Support\StudentDashboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDashboardBuilderTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private ClassVersion $version;

    private AcademicYear $academicYear;

    private Subject $subject;

    private User $studentUser;

    private StudentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $this->version->subjects()->attach($this->subject->id);

        $this->academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $shift = Shift::create(['class_version_id' => $this->version->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $this->studentUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $this->profile = StudentProfile::create(['user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $section->id]);
    }

    public function test_build_returns_empty_subjects_when_student_has_no_section(): void
    {
        $unassigned = StudentProfile::create(['user_id' => User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id])->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $result = (new StudentDashboardBuilder)->build($unassigned);

        $this->assertNull($result['class_version_id']);
        $this->assertNull($result['academic_year_id']);
        $this->assertSame([], $result['subjects']);
    }

    public function test_build_includes_a_subject_with_zero_chapters_and_zero_exams(): void
    {
        $result = (new StudentDashboardBuilder)->build($this->profile);

        $this->assertSame($this->version->id, $result['class_version_id']);
        $this->assertSame($this->academicYear->id, $result['academic_year_id']);
        $this->assertCount(1, $result['subjects']);
        $this->assertSame($this->subject->id, $result['subjects'][0]['subject_id']);
        $this->assertSame([], $result['subjects'][0]['chapters']);
        $this->assertSame([], $result['subjects'][0]['exams']);
    }

    public function test_video_and_material_counts_exclude_inactive_book_disabled_video_and_archived_material(): void
    {
        $activeBook = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Active Book']);
        $inactiveBook = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Inactive Book', 'is_active' => false]);

        $chapter = Chapter::create(['book_id' => $activeBook->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $inactiveBook->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Hidden Chapter']);

        $enabledVideo = ChapterVideo::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Enabled']);
        ChapterVideo::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Disabled', 'is_enabled' => false]);

        ChapterVideoProgress::create(['chapter_video_id' => $enabledVideo->id, 'user_id' => $this->studentUser->id, 'status' => 'completed']);

        $activeMaterial = ChapterMaterial::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'type' => 'supplementary', 'title' => 'Active', 'original_filename' => 'a.pdf', 'stored_path' => 'a.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size_bytes' => 10]);
        ChapterMaterial::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'type' => 'supplementary', 'title' => 'Archived', 'original_filename' => 'b.pdf', 'stored_path' => 'b.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'status' => 'archived']);

        ContentAccessLog::create(['chapter_id' => $chapter->id, 'chapter_material_id' => $activeMaterial->id, 'user_id' => $this->studentUser->id, 'action' => 'downloaded']);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $chapters = $result['subjects'][0]['chapters'];

        $this->assertCount(1, $chapters, 'the inactive book\'s chapter must not appear');
        $this->assertSame(1, $chapters[0]['video_total'], 'disabled video excluded from total');
        $this->assertSame(1, $chapters[0]['video_completed']);
        $this->assertSame(1, $chapters[0]['materials_total'], 'archived material excluded from total');
        $this->assertSame(1, $chapters[0]['materials_accessed']);
    }

    public function test_exam_rollup_excludes_unpublished_exams_and_pairs_best_score_with_its_own_total(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual', 'status' => 'published', 'max_attempts' => 3]);
        Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Draft Exam', 'selection_mode' => 'manual', 'status' => 'draft']);

        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 1, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now(), 'score' => 4, 'total_possible_marks' => 10, 'passed' => false]);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 2, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now(), 'score' => 8, 'total_possible_marks' => 10, 'passed' => true]);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 3, 'status' => 'in_progress', 'started_at' => now()]);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $exams = $result['subjects'][0]['exams'];

        $this->assertCount(1, $exams, 'the draft exam must not appear');
        $this->assertSame(3, $exams[0]['attempts_used'], 'in_progress counts toward attempts_used');
        $this->assertSame(3, $exams[0]['max_attempts']);
        $this->assertSame(8, $exams[0]['best_score']);
        $this->assertSame(10, $exams[0]['total_possible_marks']);
        $this->assertTrue($exams[0]['passed'], 'passed is true because SOME submitted attempt passed, independent of which one scored highest');
    }

    public function test_exam_passed_is_null_when_no_attempt_has_been_submitted_yet(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Final', 'selection_mode' => 'manual', 'status' => 'published']);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now()]);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $exams = $result['subjects'][0]['exams'];

        $this->assertSame(1, $exams[0]['attempts_used']);
        $this->assertNull($exams[0]['best_score']);
        $this->assertNull($exams[0]['total_possible_marks']);
        $this->assertNull($exams[0]['passed']);
    }
}
