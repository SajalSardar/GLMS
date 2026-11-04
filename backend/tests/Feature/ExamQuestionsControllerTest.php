<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ExamQuestionsControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private Chapter $chapterA;

    private ClassVersion $versionA;

    private Subject $subject;

    private Exam $manualExam;

    private Exam $fixedRandomExam;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);

        $book = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Mathematics Book']);
        $this->chapterA = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $academicYear = AcademicYear::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->manualExam = Exam::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual']);
        $this->fixedRandomExam = Exam::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Final', 'selection_mode' => 'fixed_random', 'random_question_count' => 2]);
    }

    private function createQuestions(int $count, string $difficulty = 'medium'): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = Question::create([
                'chapter_id' => $this->chapterA->id,
                'school_id' => $this->chapterA->school_id,
                'district_id' => $this->chapterA->district_id,
                'question_type' => 'mcq_single',
                'question_text' => "Question {$i}",
                'difficulty' => $difficulty,
            ])->id;
        }

        return $ids;
    }

    private function createQuestionsInChapter(Chapter $chapter, int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = Question::create([
                'chapter_id' => $chapter->id,
                'school_id' => $chapter->school_id,
                'district_id' => $chapter->district_id,
                'question_type' => 'mcq_single',
                'question_text' => "Chapter {$chapter->id} Question {$i}",
            ])->id;
        }

        return $ids;
    }

    public function test_set_questions_stores_the_ordered_list(): void
    {
        [$q1, $q2] = $this->createQuestions(2);
        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/exams/{$this->manualExam->id}/questions", ['question_ids' => [$q2, $q1]])
            ->assertOk()
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.questions.0.question.id', $q2)
            ->assertJsonPath('data.questions.1.question.id', $q1);
    }

    public function test_set_questions_rejects_a_question_from_an_unrelated_subject(): void
    {
        $otherSubject = Subject::create(['name' => 'Science']);
        $otherBook = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $otherSubject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Science Book']);
        $otherChapter = Chapter::create(['book_id' => $otherBook->id, 'school_id' => $otherBook->school_id, 'district_id' => $otherBook->district_id, 'name' => 'Chapter 1']);
        $foreignQuestion = Question::create(['chapter_id' => $otherChapter->id, 'school_id' => $otherChapter->school_id, 'district_id' => $otherChapter->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Foreign']);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/exams/{$this->manualExam->id}/questions", ['question_ids' => [$foreignQuestion->id]])
            ->assertUnprocessable();
    }

    public function test_set_questions_is_rejected_for_a_per_attempt_random_exam(): void
    {
        [$q1] = $this->createQuestions(1);
        $exam = Exam::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->manualExam->academic_year_id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Random Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 1, 'marks_per_question' => 1]);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/exams/{$exam->id}/questions", ['question_ids' => [$q1]])
            ->assertUnprocessable();
    }

    public function test_randomize_draws_exactly_the_configured_count(): void
    {
        $this->createQuestions(5);
        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
            ->assertOk()
            ->assertJsonCount(2, 'data.questions');
    }

    public function test_randomize_respects_a_difficulty_filter(): void
    {
        $this->createQuestions(3, 'easy');
        $this->createQuestions(2, 'hard');
        $this->fixedRandomExam->update(['random_difficulty' => 'hard']);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
            ->assertOk();

        foreach ($response->json('data.questions') as $entry) {
            $this->assertSame('hard', $entry['question']['difficulty']);
        }
    }

    public function test_randomize_rejects_a_pool_smaller_than_the_configured_count(): void
    {
        $this->createQuestions(1);
        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
            ->assertUnprocessable();
    }

    public function test_randomize_is_rejected_for_a_manual_exam(): void
    {
        $this->createQuestions(5);
        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/randomize")
            ->assertUnprocessable();
    }

    public function test_randomize_narrows_the_pool_to_allowed_chapters_only(): void
    {
        $chapterB = Chapter::create(['book_id' => $this->chapterA->book_id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'name' => 'Chapter 2']);

        $excludedIds = $this->createQuestions(5); // chapterA — excluded by the allow-list below.
        $allowedIds = $this->createQuestionsInChapter($chapterB, 5);

        $this->fixedRandomExam->randomChapters()->attach($chapterB->id);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
            ->assertOk();

        $drawnIds = collect($response->json('data.questions'))->pluck('question.id')->all();

        $this->assertCount(2, $drawnIds);
        $this->assertEmpty(array_intersect($drawnIds, $excludedIds));
        $this->assertEmpty(array_diff($drawnIds, $allowedIds));
    }

    public function test_randomize_draws_from_any_chapter_when_no_random_chapters_are_configured(): void
    {
        $chapterB = Chapter::create(['book_id' => $this->chapterA->book_id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'name' => 'Chapter 2']);

        $chapterAIds = $this->createQuestions(10);
        $chapterBIds = $this->createQuestionsInChapter($chapterB, 10);

        $this->fixedRandomExam->update(['random_question_count' => 10]);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
            ->assertOk();

        $drawnIds = collect($response->json('data.questions'))->pluck('question.id')->all();

        // No exam_random_chapters row is configured, so the pool spans
        // both chapters under the subject. With 10 drawn from a 20-item
        // pool split evenly across two chapters, the odds of a correct
        // implementation drawing exclusively from one chapter are
        // astronomically small (~1/184,756) — this would reliably catch
        // a reversed/absent chapter allow-list check.
        $this->assertNotEmpty(array_intersect($drawnIds, $chapterAIds));
        $this->assertNotEmpty(array_intersect($drawnIds, $chapterBIds));
    }

    public function test_randomize_produces_varying_draws_across_repeated_calls(): void
    {
        $this->createQuestions(20);
        $this->fixedRandomExam->update(['random_question_count' => 5]);

        $allowed = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $draws = [];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($allowed, 'sanctum')
                ->postJson("/api/v1/exams/{$this->fixedRandomExam->id}/randomize")
                ->assertOk();

            $ids = collect($response->json('data.questions'))->pluck('question.id')->sort()->values()->all();
            $draws[] = implode(',', $ids);
        }

        // Drawing 5 of 20 questions 10 times gives C(20,5) = 15,504
        // possible sets — a deterministic "always the first N by id" bug
        // would produce the exact same set every time, which this would
        // catch with overwhelming probability.
        $this->assertGreaterThan(1, count(array_unique($draws)), 'Expected randomize() to produce varying draws across repeated calls.');
    }
}
