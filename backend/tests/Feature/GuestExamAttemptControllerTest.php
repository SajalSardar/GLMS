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
use App\Models\QuestionOption;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestExamAttemptControllerTest extends TestCase
{
    use RefreshDatabase;

    private ClassVersion $version;

    private Subject $subject;

    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);

        $book = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Book']);
        $this->chapter = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);
    }

    private function makeQuestion(bool $correctIsOptionA = true): Question
    {
        $question = Question::create(['chapter_id' => $this->chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'Q', 'marks' => 2]);
        QuestionOption::create(['question_id' => $question->id, 'option_text' => 'A', 'is_correct' => $correctIsOptionA, 'order_index' => 0]);
        QuestionOption::create(['question_id' => $question->id, 'option_text' => 'B', 'is_correct' => ! $correctIsOptionA, 'order_index' => 1]);

        return $question->fresh('options');
    }

    private function publishedManualExam(array $questions): Exam
    {
        $academicYear = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $exam = Exam::create([
            'class_version_id' => $this->version->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $academicYear->id,
            'school_id' => $this->version->school_id,
            'district_id' => $this->version->district_id,
            'name' => 'Guest Exam',
            'selection_mode' => 'manual',
            'status' => 'published',
        ]);

        foreach ($questions as $index => $question) {
            $exam->questions()->attach($question->id, ['order_index' => $index]);
        }

        return $exam;
    }

    private function publishedRandomExam(int $randomCount, int $marksPerQuestion = 2): Exam
    {
        $academicYear = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        return Exam::create([
            'class_version_id' => $this->version->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $academicYear->id,
            'school_id' => $this->version->school_id,
            'district_id' => $this->version->district_id,
            'name' => 'Guest Random Exam',
            'selection_mode' => 'per_attempt_random',
            'status' => 'published',
            'random_question_count' => $randomCount,
            'marks_per_question' => $marksPerQuestion,
        ]);
    }

    public function test_random_draw_returns_the_configured_count_with_correctness_hidden(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeQuestion();
        }

        $exam = $this->publishedRandomExam(3);

        $response = $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertOk();

        $response->assertJsonCount(3, 'data');
        $this->assertArrayNotHasKey('is_correct', $response->json('data.0.options.0'));

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_random_draw_is_rejected_for_a_non_random_exam(): void
    {
        $exam = $this->publishedManualExam([$this->makeQuestion()]);

        $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertUnprocessable();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_random_draw_404s_for_a_draft_exam(): void
    {
        $exam = $this->publishedRandomExam(1);
        $exam->update(['status' => 'draft']);

        $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertNotFound();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_grades_a_manual_exam_and_persists_nothing(): void
    {
        $correct = $this->makeQuestion(correctIsOptionA: true);
        $wrong = $this->makeQuestion(correctIsOptionA: true);
        $exam = $this->publishedManualExam([$correct, $wrong]);

        $correctOptionId = $correct->options->where('is_correct', true)->first()->id;
        $wrongOptionId = $wrong->options->where('is_correct', false)->first()->id;

        $response = $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [
                ['question_id' => $correct->id, 'selected_option_ids' => [$correctOptionId]],
                ['question_id' => $wrong->id, 'selected_option_ids' => [$wrongOptionId]],
            ],
        ])->assertOk();

        $response->assertJsonPath('score', 2);
        $response->assertJsonPath('total_possible_marks', 4);

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_grades_an_omitted_question_as_wrong_but_still_counts_its_marks(): void
    {
        $answered = $this->makeQuestion();
        $omitted = $this->makeQuestion();
        $exam = $this->publishedManualExam([$answered, $omitted]);

        $correctOptionId = $answered->options->where('is_correct', true)->first()->id;

        $response = $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [
                ['question_id' => $answered->id, 'selected_option_ids' => [$correctOptionId]],
            ],
        ])->assertOk();

        $response->assertJsonPath('score', 2);
        $response->assertJsonPath('total_possible_marks', 4);

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_reveals_correct_option_ids_per_question(): void
    {
        $question = $this->makeQuestion(correctIsOptionA: true);
        $exam = $this->publishedManualExam([$question]);
        $correctOptionId = $question->options->where('is_correct', true)->first()->id;

        $response = $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $question->id, 'selected_option_ids' => []]],
        ])->assertOk();

        $response->assertJsonPath('answers.0.correct_option_ids.0', $correctOptionId);

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_on_a_random_exam_grades_only_the_echoed_back_questions(): void
    {
        $shown = $this->makeQuestion(correctIsOptionA: true);
        $notShown = $this->makeQuestion(correctIsOptionA: true); // exists in the eligible pool but never submitted
        $exam = $this->publishedRandomExam(1, marksPerQuestion: 3);

        $correctOptionId = $shown->options->where('is_correct', true)->first()->id;

        $response = $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $shown->id, 'selected_option_ids' => [$correctOptionId]]],
        ])->assertOk();

        // total_possible_marks is 3 (one question's worth), not 6 (both
        // eligible questions) — $notShown must never be graded.
        $response->assertJsonPath('total_possible_marks', 3);
        $response->assertJsonPath('score', 3);

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_on_a_random_exam_rejects_more_distinct_questions_than_random_question_count(): void
    {
        $first = $this->makeQuestion();
        $second = $this->makeQuestion();
        $exam = $this->publishedRandomExam(1);

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [
                ['question_id' => $first->id, 'selected_option_ids' => []],
                ['question_id' => $second->id, 'selected_option_ids' => []],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_rejects_a_question_id_that_does_not_belong_to_the_exam(): void
    {
        $inExam = $this->makeQuestion();
        $exam = $this->publishedManualExam([$inExam]);
        $outsideExam = $this->makeQuestion(); // never attached to this exam

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $outsideExam->id, 'selected_option_ids' => []]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_guest_submission_404s_for_a_draft_exam(): void
    {
        $question = $this->makeQuestion();
        $exam = $this->publishedManualExam([$question]);
        $exam->update(['status' => 'draft']);

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $question->id, 'selected_option_ids' => []]],
        ])->assertNotFound();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }

    public function test_authenticated_caller_can_also_use_the_guest_endpoints_without_anything_persisting(): void
    {
        $question = $this->makeQuestion(correctIsOptionA: true);
        $exam = $this->publishedManualExam([$question]);
        $correctOptionId = $question->options->where('is_correct', true)->first()->id;

        $actor = StudentUser::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
                'answers' => [['question_id' => $question->id, 'selected_option_ids' => [$correctOptionId]]],
            ])->assertOk();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }
}
