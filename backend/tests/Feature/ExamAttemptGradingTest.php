<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ExamAttemptController;
use App\Http\Requests\Api\V1\SaveExamAttemptAnswerRequest;
use App\Http\Requests\Api\V1\SubmitExamAttemptRequest;
use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

class ExamAttemptGradingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $exam;

    private StudentUser $student;

    private Question $singleQuestion;

    private Question $multiQuestion;

    private int $singleCorrectOptionId;

    private array $multiCorrectOptionIds;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $subject = Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $shift = Shift::create(['class_version_id' => $version->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Chapter 1']);

        $this->exam = Exam::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual', 'status' => 'published', 'passing_score_percent' => 50]);

        $this->singleQuestion = Question::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'What is 2+2?', 'marks' => 2]);
        $wrong = QuestionOption::create(['question_id' => $this->singleQuestion->id, 'option_text' => '3', 'is_correct' => false, 'order_index' => 0]);
        $right = QuestionOption::create(['question_id' => $this->singleQuestion->id, 'option_text' => '4', 'is_correct' => true, 'order_index' => 1]);
        $this->singleCorrectOptionId = $right->id;

        $this->multiQuestion = Question::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_multiple', 'question_text' => 'Which are even?', 'marks' => 3]);
        $this->multiCorrectOptionIds = [];
        foreach ([['2', true], ['3', false], ['4', true]] as [$text, $correct]) {
            $option = QuestionOption::create(['question_id' => $this->multiQuestion->id, 'option_text' => $text, 'is_correct' => $correct]);
            if ($correct) {
                $this->multiCorrectOptionIds[] = $option->id;
            }
        }

        $this->exam->questions()->attach([$this->singleQuestion->id => ['order_index' => 0], $this->multiQuestion->id => ['order_index' => 1]]);

        $role = Role::create(['name' => 'student_grading', 'label' => 'Student']);
        $permission = Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);
        $this->student = StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $this->student->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $section->id]);
    }

    public function test_full_marks_scenario_scores_correctly_and_marks_passed(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->multiQuestion->id}", ['selected_option_ids' => $this->multiCorrectOptionIds])
            ->assertOk();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/exam-attempts/{$attemptId}/submit")
            ->assertOk();

        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.score', 5);
        $response->assertJsonPath('data.total_possible_marks', 5);
        $response->assertJsonPath('data.passed', true);
    }

    public function test_mcq_multiple_is_all_or_nothing_a_partial_selection_scores_zero(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->multiQuestion->id}", ['selected_option_ids' => [$this->multiCorrectOptionIds[0]]])
            ->assertOk();

        $response = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exam-attempts/{$attemptId}/submit")->assertOk();

        $this->assertSame(0, collect($response->json('data.questions'))->firstWhere('question_id', $this->multiQuestion->id)['marks_awarded']);
    }

    public function test_an_unanswered_question_grades_as_zero(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $response = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exam-attempts/{$attemptId}/submit")->assertOk();

        $response->assertJsonPath('data.score', 0);
        $response->assertJsonPath('data.total_possible_marks', 5);
        $response->assertJsonPath('data.passed', false);
    }

    public function test_feedback_is_hidden_while_in_progress_and_visible_once_submitted(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $inProgress = $this->actingAs($this->student, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();
        $this->assertNull($inProgress->json('data.questions.0.is_correct'));
        $this->assertNull($inProgress->json('data.questions.0.options.0.is_correct'));

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exam-attempts/{$attemptId}/submit")->assertOk();

        $submitted = $this->actingAs($this->student, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();
        $this->assertNotNull($submitted->json('data.questions.0.is_correct'));
        $this->assertNotNull($submitted->json('data.questions.0.options.0.is_correct'));
    }

    public function test_save_answer_is_forbidden_for_a_different_user(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $other = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertForbidden();
    }

    public function test_time_limit_auto_finalizes_on_next_touch_and_rejects_a_late_save(): void
    {
        $this->exam->update(['time_limit_minutes' => 10]);
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        $attempt = ExamAttempt::find($attemptId);
        $attempt->started_at = now()->subMinutes(20);
        $attempt->save();

        // A late save is rejected — the attempt auto-finalizes first.
        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->multiQuestion->id}", ['selected_option_ids' => $this->multiCorrectOptionIds])
            ->assertUnprocessable();

        $attempt->refresh();
        $this->assertSame('submitted', $attempt->status);
        // Only the first (correct, pre-expiry) answer counts.
        $this->assertSame(2, $attempt->score);
    }

    public function test_submit_handles_a_concurrent_double_finalize_without_crashing(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        // Two independent in-memory copies of the same attempt — standing
        // in for two near-simultaneous submit requests that both loaded the
        // row (and its attemptQuestions/answers) while it was still
        // in_progress, before either had committed. Eager-loading here
        // matters: finalize()'s loadMissing() is a no-op once a relation
        // is already loaded, so $attemptB's "answers" collection stays
        // stale (pre-collision) through its own finalize() call below —
        // exactly like two real requests that both read the row before
        // either write landed.
        $attemptA = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);
        $attemptB = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);

        $controller = app(ExamAttemptController::class);
        $request = new SubmitExamAttemptRequest;

        // The first "request" wins the race and finalizes normally.
        $controller->submit($request, $attemptA);

        // The second "request" still sees the stale in-memory in_progress
        // status it loaded before $attemptA committed, so it also tries to
        // finalize. Its ExamAttemptAnswer::create() for the unanswered
        // multiQuestion collides with the unique constraint on
        // (exam_attempt_id, question_id) that $attemptA's finalize()
        // already satisfied. This must not throw — the controller swallows
        // the QueryException and treats it as "already finalized."
        $controller->submit($request, $attemptB);

        $attempt = ExamAttempt::find($attemptId);
        $this->assertSame('submitted', $attempt->status);
        // Same score a single clean finalize() call would have produced:
        // 2 marks for the correctly-answered singleQuestion, 0 for the
        // unanswered multiQuestion.
        $this->assertSame(2, $attempt->score);
        $this->assertSame(5, $attempt->total_possible_marks);
        // Exactly one answer row for the unanswered question — no
        // duplicate was left behind by the losing "request".
        $this->assertSame(
            1,
            ExamAttemptAnswer::where('exam_attempt_id', $attemptId)->where('question_id', $this->multiQuestion->id)->count()
        );
    }

    public function test_show_handles_a_concurrent_double_finalize_without_crashing(): void
    {
        $this->exam->update(['time_limit_minutes' => 10]);
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        ExamAttempt::where('id', $attemptId)->update(['started_at' => now()->subMinutes(20)]);

        // Two independent in-memory copies of the same (now expired)
        // attempt — standing in for two near-simultaneous GET requests
        // past the deadline, both of which try to auto-finalize via
        // finalizeIfExpired(). Eager-loaded for the same reason as the
        // submit() race test above: finalize()'s loadMissing() must not
        // silently refresh $attemptB's stale "answers" collection.
        $attemptA = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);
        $attemptB = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);

        $controller = app(ExamAttemptController::class);

        $controller->show($attemptA);

        // The second "request" still sees the stale in-memory
        // in_progress/started_at it loaded before $attemptA committed, so
        // it also tries to auto-finalize — same unique-constraint
        // collision as submit(), which show() must swallow too.
        $controller->show($attemptB);

        $attempt = ExamAttempt::find($attemptId);
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame(2, $attempt->score);
        $this->assertSame(
            1,
            ExamAttemptAnswer::where('exam_attempt_id', $attemptId)->where('question_id', $this->multiQuestion->id)->count()
        );
    }

    public function test_save_answer_handles_a_concurrent_double_finalize_without_crashing(): void
    {
        $this->exam->update(['time_limit_minutes' => 10]);
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        ExamAttempt::where('id', $attemptId)->update(['started_at' => now()->subMinutes(20)]);

        // Two independent in-memory copies of the same (now expired)
        // attempt — standing in for two near-simultaneous requests past
        // the deadline. Eager-loaded for the same reason as the
        // submit()/show() race tests above: finalize()'s loadMissing()
        // must not silently refresh $attemptB's stale "answers"
        // collection.
        $attemptA = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);
        $attemptB = ExamAttempt::with(['attemptQuestions.question.options', 'answers'])->find($attemptId);

        $controller = app(ExamAttemptController::class);

        // The first "request" wins the race and auto-finalizes normally.
        $controller->show($attemptA);

        // The second "request" still sees the stale in-memory
        // in_progress/started_at it loaded before $attemptA committed, so
        // saveAnswer()'s finalizeIfExpired() call also tries to finalize —
        // the same unique-constraint collision as submit()/show(), which
        // saveAnswer() must swallow too (via finalizeSafely()) instead of
        // crashing with a 500.
        $request = new SaveExamAttemptAnswerRequest;
        $validator = Validator::make(['selected_option_ids' => [$this->singleCorrectOptionId]], $request->rules());
        (new ReflectionProperty($request, 'validator'))->setValue($request, $validator);

        $this->expectException(ValidationException::class);

        try {
            $controller->saveAnswer($request, $attemptB, $this->singleQuestion);
        } finally {
            $attempt = ExamAttempt::find($attemptId);
            $this->assertSame('submitted', $attempt->status);
            $this->assertSame(
                1,
                ExamAttemptAnswer::where('exam_attempt_id', $attemptId)->where('question_id', $this->multiQuestion->id)->count()
            );
        }
    }

    public function test_an_already_graded_attempt_survives_its_exam_and_question_being_soft_deleted(): void
    {
        $attemptId = $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exams/{$this->exam->id}/attempts")->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/exam-attempts/{$attemptId}/answers/{$this->singleQuestion->id}", ['selected_option_ids' => [$this->singleCorrectOptionId]])
            ->assertOk();

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/exam-attempts/{$attemptId}/submit")->assertOk();

        $this->singleQuestion->delete();
        $this->exam->delete();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/exam-attempts/{$attemptId}")
            ->assertOk();

        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.score', 2);
        $response->assertJsonPath('data.total_possible_marks', 5);
        $response->assertJsonPath('data.passed', false);
        $response->assertJsonPath('data.questions.0.is_correct', true);
    }
}
