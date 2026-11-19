<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeExamControllerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private ClassVersion $version;

    private Subject $subject;

    private AcademicYear $academicYear;

    private Chapter $chapterOne;

    private Chapter $chapterTwo;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $this->academicYear = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $shift = Shift::create(['class_version_id' => $this->version->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $book = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Book']);
        $this->chapterOne = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);
        $this->chapterTwo = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 2']);
    }

    private function eligibleStudent(): StudentUser
    {
        $role = Role::create(['name' => 'student_'.uniqid(), 'label' => 'Student']);
        $permission = Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);

        $user = StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $this->section->id]);

        return $user;
    }

    public function test_config_returns_the_configured_values(): void
    {
        $response = $this->getJson('/api/v1/practice-exams/config')->assertOk();

        $response->assertJson([
            'single_chapter_question_count' => config('practice_exams.single_chapter_question_count'),
            'multi_chapter_question_count' => config('practice_exams.multi_chapter_question_count'),
            'tier_pass_percent' => config('practice_exams.tier_pass_percent'),
            'tier_good_percent' => config('practice_exams.tier_good_percent'),
            'tier_best_percent' => config('practice_exams.tier_best_percent'),
        ]);
    }

    public function test_guest_creation_with_one_chapter_uses_the_single_chapter_question_count(): void
    {
        $response = $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id],
        ])->assertCreated();

        $examId = $response->json('data.id');
        $exam = Exam::findOrFail($examId);

        $this->assertTrue($exam->is_practice);
        $this->assertSame('published', $exam->status);
        $this->assertSame('per_attempt_random', $exam->selection_mode);
        $this->assertSame(config('practice_exams.single_chapter_question_count'), $exam->random_question_count);
        $this->assertSame([$this->chapterOne->id], $exam->randomChapters()->pluck('chapters.id')->all());
    }

    public function test_duplicate_chapter_ids_are_rejected_as_a_validation_error(): void
    {
        $response = $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id, $this->chapterOne->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['chapter_ids.0']);
        $this->assertSame(0, Exam::where('is_practice', true)->count());
    }

    public function test_guest_creation_with_multiple_chapters_uses_the_multi_chapter_question_count(): void
    {
        $response = $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id, $this->chapterTwo->id],
        ])->assertCreated();

        $exam = Exam::findOrFail($response->json('data.id'));

        $this->assertSame(config('practice_exams.multi_chapter_question_count'), $exam->random_question_count);
    }

    public function test_logged_in_creation_uses_the_students_own_enrollment_and_can_then_start_an_attempt(): void
    {
        // The provisioned exam's per_attempt_random draw requires at
        // least single_chapter_question_count eligible questions under
        // chapterOne, or ExamAttemptController::start() rejects the
        // attempt with "Only N eligible question(s) are available" —
        // the brief's version of this test omitted these fixtures.
        $requiredQuestionCount = config('practice_exams.single_chapter_question_count');
        foreach (range(1, $requiredQuestionCount) as $i) {
            Question::create(['chapter_id' => $this->chapterOne->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => "Q{$i}", 'marks' => 1]);
        }

        $student = $this->eligibleStudent();

        $response = $this->actingAs($student, 'sanctum')->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id],
        ])->assertCreated();

        $examId = $response->json('data.id');
        $exam = Exam::findOrFail($examId);

        $this->assertSame($this->version->id, $exam->class_version_id);
        $this->assertSame($this->academicYear->id, $exam->academic_year_id);

        // The real proof this is correctly wired: starting a tracked
        // attempt against this exam must succeed, not throw the
        // enrollment-mismatch error.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$examId}/attempts")
            ->assertCreated();
    }

    public function test_chapters_spanning_different_subjects_are_rejected(): void
    {
        $otherSubject = Subject::create(['name' => 'Science']);
        $otherBook = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $otherSubject->id, 'name' => 'Science Book']);
        $otherChapter = Chapter::create(['book_id' => $otherBook->id, 'name' => 'Science Chapter']);

        $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id, $otherChapter->id],
        ])->assertUnprocessable();
    }

    public function test_chapter_ids_must_be_a_non_empty_array(): void
    {
        $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [],
        ])->assertUnprocessable();
    }
}
