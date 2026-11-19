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

    private Book $book;

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

        $this->book = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Book']);
        $this->chapterOne = Chapter::create(['book_id' => $this->book->id, 'name' => 'Chapter 1']);
        $this->chapterTwo = Chapter::create(['book_id' => $this->book->id, 'name' => 'Chapter 2']);

        // store() now caps random_question_count at the number of
        // questions that actually exist, so the configured-count
        // assertions below need a pool at least that large: 20 per
        // chapter covers both the single (20) and multi (40) cases.
        $this->seedQuestions($this->chapterOne, config('practice_exams.single_chapter_question_count'));
        $this->seedQuestions($this->chapterTwo, config('practice_exams.single_chapter_question_count'));
    }

    private function seedQuestions(Chapter $chapter, int $count): void
    {
        foreach (range(1, $count) as $i) {
            Question::create([
                'chapter_id' => $chapter->id,
                'school_id' => $this->school->id,
                'district_id' => $this->school->district_id,
                'question_type' => 'mcq_single',
                'question_text' => "Q{$i} for chapter {$chapter->id}",
                'marks' => 1,
            ]);
        }
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
        // least random_question_count eligible questions under
        // chapterOne, or ExamAttemptController::start() rejects the
        // attempt with "Only N eligible question(s) are available" —
        // setUp() seeds exactly that pool.
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

    public function test_more_than_fifty_chapter_ids_are_rejected(): void
    {
        // Real, valid chapters with a question pool: otherwise the
        // per-id `exists` rule or store()'s zero-questions guard would
        // produce the same 422 regardless of whether max:50 is present.
        $ids = [];
        foreach (range(1, 51) as $i) {
            $chapter = Chapter::create(['book_id' => $this->book->id, 'name' => "Bulk Chapter {$i}"]);
            $this->seedQuestions($chapter, 1);
            $ids[] = $chapter->id;
        }

        $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => $ids,
        ])->assertUnprocessable()->assertJsonValidationErrors(['chapter_ids']);

        $this->assertSame(0, Exam::where('is_practice', true)->count());
    }

    public function test_creation_fails_with_422_when_no_academic_year_is_current(): void
    {
        $this->academicYear->forceFill(['is_current' => false])->save();

        $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);

        $this->assertSame(0, Exam::where('is_practice', true)->count());
    }

    public function test_creation_is_rejected_when_the_selected_chapters_have_no_questions(): void
    {
        $emptyChapter = Chapter::create(['book_id' => $this->book->id, 'name' => 'Empty Chapter']);

        $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$emptyChapter->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['chapter_ids']);

        $this->assertSame(0, Exam::where('is_practice', true)->count());
    }

    public function test_a_chapter_with_fewer_questions_than_configured_uses_the_actual_count(): void
    {
        $sparseChapter = Chapter::create(['book_id' => $this->book->id, 'name' => 'Sparse Chapter']);
        $this->seedQuestions($sparseChapter, 3);

        $response = $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$sparseChapter->id],
        ])->assertCreated();

        $exam = Exam::findOrFail($response->json('data.id'));

        $this->assertLessThan(config('practice_exams.single_chapter_question_count'), $exam->random_question_count);
        $this->assertSame(3, $exam->random_question_count);
    }

    public function test_the_provisioned_exam_carries_a_bangla_display_name(): void
    {
        $response = $this->postJson('/api/v1/practice-exams', [
            'subject_id' => $this->subject->id,
            'chapter_ids' => [$this->chapterOne->id],
        ])->assertCreated();

        $this->assertSame('অনুশীলন পরীক্ষা', Exam::findOrFail($response->json('data.id'))->bn_name);
    }
}
