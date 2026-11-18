# Public/Guest Exam-Taking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let anyone, authenticated or not, browse published exams and attempt one with zero database writes, while leaving the existing authenticated, tracked attempt flow (`ExamAttemptController`) completely unchanged.

**Architecture:** Open `GET /exams`/`GET /exams/{exam}` to the public, degrading to a published+currently-available filter instead of a hard permission gate. Extract the one correctness rule (`ExamAttempt::answerIsCorrect()`) into a shared `ExamGrading` helper. Add a new, separate `GuestExamAttemptController` with two stateless endpoints — one to draw a fresh random question set for `per_attempt_random` exams, one to grade a submitted answer set — neither of which ever creates a row in any table.

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit, MySQL (production parity — see sub-projects 1-2's final verification tasks).

**Spec:** `docs/superpowers/specs/2026-11-18-public-guest-exam-attempts-design.md`

## Global Constraints

- No schema changes, no migrations, in this entire plan. If you find yourself wanting to add a column or table, stop — that's a sign of a design departure that hasn't been discussed.
- The existing `ExamAttemptController`'s `start`/`saveAnswer`/`submit` behavior must not change in any observable way. Every refactor in this plan (`isPubliclyTakeable()`, `ExamGrading::isCorrect()`) is a pure extraction — same inputs, same outputs — verified by running that controller's existing test files unmodified and confirming they still pass.
- The two new guest endpoints must create zero rows in `exam_attempts`, `exam_attempt_questions`, or `exam_attempt_answers` under any circumstance, including error paths. Every test that exercises them should assert this explicitly, not just assume it.
- `QuestionResource`/`QuestionOptionResource` need no changes — `is_correct` is already hidden from any viewer without `questions.update`, which a null (guest) user automatically fails. Do not add new logic to hide it a second way.
- Run `vendor/bin/pint --dirty --format agent` after modifying any PHP file in each task, before committing.
- No frontend work in this plan (deferred to sub-project 5).

---

## Task 1: Make exam browsing public

**Files:**
- Modify: `backend/app/Models/Exam.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/ExamControllerTest.php`

**Interfaces:**
- Produces: `Exam::isPubliclyTakeable(): bool` — the single place "is this exam visible/attemptable by the general public right now" lives. Task 2's `GuestExamAttemptController` consumes this.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/ExamControllerTest.php`:

Replace `test_index_and_show_require_authentication`:

```php
    public function test_index_and_show_degrade_to_published_and_available_for_an_unauthenticated_viewer(): void
    {
        $draft = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $published = Exam::create($this->examPayload(['name' => 'Final']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
        ]);

        $notYetAvailable = Exam::create($this->examPayload(['name' => 'Future Exam']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'available_from' => now()->addDay(),
        ]);

        $expired = Exam::create($this->examPayload(['name' => 'Past Exam']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'available_until' => now()->subDay(),
        ]);

        $index = $this->getJson('/api/v1/exams')->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $published->id);

        $this->getJson("/api/v1/exams/{$published->id}")->assertOk();
        $this->getJson("/api/v1/exams/{$draft->id}")->assertNotFound();
        $this->getJson("/api/v1/exams/{$notYetAvailable->id}")->assertNotFound();
        $this->getJson("/api/v1/exams/{$expired->id}")->assertNotFound();
    }
```

In `test_every_role_without_exams_permissions_is_forbidden_even_with_full_questions_access`, remove the now-invalid line `$this->actingAs($actor, 'sanctum')->getJson('/api/v1/exams')->assertForbidden();` (index is never forbidden anymore — it degrades, it doesn't gate), keeping only the `postJson(...)->assertForbidden()` line. Rename the test to `test_every_role_without_exams_permissions_cannot_create_even_with_full_questions_access`.

Replace `test_show_is_forbidden_for_an_actor_with_no_exams_permission`:

```php
    public function test_show_404s_a_draft_exam_for_an_actor_with_no_exams_permission(): void
    {
        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->getJson("/api/v1/exams/{$exam->id}")
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ExamControllerTest --compact`
Expected: FAIL — `index`/`show` still 401/403 for an unauthenticated/unprivileged request instead of degrading.

- [ ] **Step 3: Add `Exam::isPubliclyTakeable()`**

In `backend/app/Models/Exam.php`, add (near `eligibleRandomQuestionIds()`):

```php
    /**
     * Whether this exam is currently visible/attemptable to a viewer with
     * no exams.view permission (which includes every unauthenticated
     * guest) — published, and within its availability window if one is
     * set. The single place this rule lives: ExamController::show(),
     * ExamAttemptController::start(), and GuestExamAttemptController's
     * two endpoints all call this instead of repeating the three checks
     * inline.
     */
    public function isPubliclyTakeable(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        $now = now();

        if ($this->available_from && $now->lessThan($this->available_from)) {
            return false;
        }

        if ($this->available_until && $now->greaterThan($this->available_until)) {
            return false;
        }

        return true;
    }
```

- [ ] **Step 4: Update `ExamController::index()`/`show()`**

In `backend/app/Http/Controllers/Api/V1/ExamController.php`, replace `index()`:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        $exams = Exam::query()
            ->with(self::WITH)
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->when(! $request->user()?->hasPermission('exams.view'), fn ($query) => $query
                ->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
                ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', now())))
            ->orderBy('name')
            ->get();

        return ExamResource::collection($exams);
    }
```

(Removed the `$this->authorize('viewAny', Exam::class);` line — a `null` actor can never pass an `authorize()` call, so it must go, not be conditioned.)

Replace `show()`:

```php
    public function show(Request $request, Exam $exam): ExamResource
    {
        abort_unless($request->user()?->hasPermission('exams.view') || $exam->isPubliclyTakeable(), 404);

        return new ExamResource($exam->load(self::WITH));
    }
```

(Removed `$this->authorize('view', $exam);`; added the `Request $request` parameter.)

- [ ] **Step 5: Split the `exams` route registration**

In `backend/routes/api.php`, find `Route::apiResource('exams', ExamController::class);` inside the `auth:sanctum` group (currently the full CRUD resource) and change it to:

```php
        Route::apiResource('exams', ExamController::class)->only(['store', 'update', 'destroy']);
```

Add, in the public route group (alongside the other public `only(['index', 'show'])` registrations, following this file's existing ordering convention):

```php
    Route::apiResource('exams', ExamController::class)->only(['index', 'show']);
```

- [ ] **Step 6: Refactor `ExamAttemptController::start()` to use the new method**

In `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`, replace:

```php
        $now = now();

        if ($exam->available_from && $now->lessThan($exam->available_from)) {
            throw ValidationException::withMessages(['exam' => ['This exam is not yet available.']]);
        }

        if ($exam->available_until && $now->greaterThan($exam->available_until)) {
            throw ValidationException::withMessages(['exam' => ['This exam is no longer available.']]);
        }
```

(and the `if ($exam->status !== 'published') { ... }` check immediately above it) with:

```php
        if (! $exam->isPubliclyTakeable()) {
            throw ValidationException::withMessages(['exam' => ['This exam is not available to attempt right now.']]);
        }
```

This collapses what were three separate `if` blocks (status, `available_from`, `available_until`) into one call. **This changes the validation error message** from three distinct messages ("This exam is not published." / "This exam is not yet available." / "This exam is no longer available.") to one generic message. Check whether any existing test in `ExamAttemptControllerTest.php` or elsewhere asserts the exact text of any of those three old messages — if so, update that assertion to the new message. If no test asserts the exact text (only the status code), no further change is needed.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=ExamControllerTest --compact`
Expected: PASS

Run: `php artisan test --filter=ExamAttemptControllerTest --compact` (and any other `ExamAttempt*Test` file that exercises `start()`'s unavailability checks)
Expected: PASS — confirms Step 6's refactor didn't change behavior for the authenticated flow, only consolidated the code.

- [ ] **Step 8: Run the full suite, format, and commit**

Run: `php artisan test --compact` — expect everything green.

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Exam.php app/Http/Controllers/Api/V1/ExamController.php app/Http/Controllers/Api/V1/ExamAttemptController.php routes/api.php tests/Feature/ExamControllerTest.php
git commit -m "Make exam browsing public, degrading to published+available instead of gating on exams.view"
```

---

## Task 2: Build the stateless guest-attempt flow

**Files:**
- Create: `backend/app/Support/ExamGrading.php`
- Modify: `backend/app/Models/ExamAttempt.php`
- Create: `backend/app/Http/Requests/Api/V1/SubmitGuestExamAttemptRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/GuestExamAttemptController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/GuestExamAttemptControllerTest.php`
- Create: `backend/tests/Unit/ExamGradingTest.php`

**Interfaces:**
- Consumes: `Exam::isPubliclyTakeable()` (Task 1).
- Produces: `ExamGrading::isCorrect(Question $question, array $selectedOptionIds): bool` — the one correctness rule, shared by `ExamAttempt::finalize()` (unchanged behavior) and this task's new `GuestExamAttemptController::submit()`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Unit/ExamGradingTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Chapter;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Support\ExamGrading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamGradingTest extends TestCase
{
    use RefreshDatabase;

    private function questionWithOptions(string $type, array $correctFlags): Question
    {
        $question = Question::create(['chapter_id' => $this->chapter()->id, 'question_type' => $type, 'question_text' => 'Q']);

        foreach ($correctFlags as $index => $isCorrect) {
            QuestionOption::create(['question_id' => $question->id, 'option_text' => "Option {$index}", 'is_correct' => $isCorrect, 'order_index' => $index]);
        }

        return $question->fresh('options');
    }

    private function chapter(): Chapter
    {
        $subject = \App\Models\Subject::create(['name' => 'Math']);
        $classVersion = \App\Models\ClassVersion::create(['class_id' => \App\Models\SchoolClass::create(['name' => 'Class One'])->id, 'school_id' => \App\Models\School::factory()->create()->id, 'district_id' => 1, 'name' => 'V']);
        $book = \App\Models\Book::create(['class_version_id' => $classVersion->id, 'subject_id' => $subject->id, 'name' => 'Book']);

        return Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);
    }

    public function test_single_correct_option_exact_match(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false, false]);
        $correctId = $question->options->where('is_correct', true)->first()->id;

        $this->assertTrue(ExamGrading::isCorrect($question, [$correctId]));
    }

    public function test_single_correct_option_wrong_selection(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false, false]);
        $wrongId = $question->options->where('is_correct', false)->first()->id;

        $this->assertFalse(ExamGrading::isCorrect($question, [$wrongId]));
    }

    public function test_multi_correct_requires_exact_set(): void
    {
        $question = $this->questionWithOptions('mcq_multiple', [true, true, false]);
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->all();

        $this->assertTrue(ExamGrading::isCorrect($question, $correctIds));
        $this->assertFalse(ExamGrading::isCorrect($question, [$correctIds[0]])); // partial selection is wrong
    }

    public function test_extra_selection_beyond_correct_set_is_wrong(): void
    {
        $question = $this->questionWithOptions('mcq_multiple', [true, false]);
        $allIds = $question->options->pluck('id')->all();

        $this->assertFalse(ExamGrading::isCorrect($question, $allIds));
    }

    public function test_no_selection_is_wrong(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false]);

        $this->assertFalse(ExamGrading::isCorrect($question, []));
    }

    public function test_true_false(): void
    {
        $question = $this->questionWithOptions('true_false', [true, false]);
        $correctId = $question->options->where('is_correct', true)->first()->id;

        $this->assertTrue(ExamGrading::isCorrect($question, [$correctId]));
    }
}
```

(If the `chapter()`/`questionWithOptions()` helper's model-creation calls don't match this repo's actual current factory/model conventions exactly — e.g. `School::factory()` may not exist, or `district_id` on `ClassVersion` may need a real `District` row — read `ExamAttemptGradingTest.php` or `QuestionControllerTest.php`'s existing `setUp()` first and copy their established fixture-building pattern instead of this sketch. The point of this test file is the five correctness-rule assertions, not the exact fixture mechanics.)

Create `backend/tests/Feature/GuestExamAttemptControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\School;
use App\Models\SchoolClass;
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
    }

    public function test_random_draw_is_rejected_for_a_non_random_exam(): void
    {
        $exam = $this->publishedManualExam([$this->makeQuestion()]);

        $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertUnprocessable();
    }

    public function test_random_draw_404s_for_a_draft_exam(): void
    {
        $exam = $this->publishedRandomExam(1);
        $exam->update(['status' => 'draft']);

        $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertNotFound();
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
    }

    public function test_guest_submission_rejects_a_question_id_that_does_not_belong_to_the_exam(): void
    {
        $inExam = $this->makeQuestion();
        $exam = $this->publishedManualExam([$inExam]);
        $outsideExam = $this->makeQuestion(); // never attached to this exam

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $outsideExam->id, 'selected_option_ids' => []]],
        ])->assertUnprocessable();
    }

    public function test_guest_submission_404s_for_a_draft_exam(): void
    {
        $question = $this->makeQuestion();
        $exam = $this->publishedManualExam([$question]);
        $exam->update(['status' => 'draft']);

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $question->id, 'selected_option_ids' => []]],
        ])->assertNotFound();
    }

    public function test_authenticated_caller_can_also_use_the_guest_endpoints_without_anything_persisting(): void
    {
        $question = $this->makeQuestion(correctIsOptionA: true);
        $exam = $this->publishedManualExam([$question]);
        $correctOptionId = $question->options->where('is_correct', true)->first()->id;

        $actor = \App\Models\StudentUser::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
                'answers' => [['question_id' => $question->id, 'selected_option_ids' => [$correctOptionId]]],
            ])->assertOk();

        $this->assertDatabaseCount('exam_attempts', 0);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=GuestExamAttemptControllerTest --compact` and `php artisan test --filter=ExamGradingTest --compact`
Expected: FAIL — `GuestExamAttemptController`/`ExamGrading` don't exist yet, routes 404 unconditionally.

- [ ] **Step 3: Create `ExamGrading` and refactor `ExamAttempt`**

Create `backend/app/Support/ExamGrading.php`:

```php
<?php

namespace App\Support;

use App\Models\Question;

class ExamGrading
{
    /**
     * The one correctness rule for every question type in this app: the
     * selected-option-id set must exactly equal the correct-option-id
     * set. Already right for mcq_single and true_false, since their
     * correct set always has exactly one member. Shared by the tracked
     * (ExamAttempt::finalize()) and untracked (GuestExamAttemptController)
     * grading paths — this must not be duplicated a second time.
     *
     * @param  array<int, int>  $selectedOptionIds
     */
    public static function isCorrect(Question $question, array $selectedOptionIds): bool
    {
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selectedIds = collect($selectedOptionIds)->sort()->values()->all();

        return $correctIds === $selectedIds;
    }
}
```

In `backend/app/Models/ExamAttempt.php`, replace the private `answerIsCorrect()` method:

```php
    private function answerIsCorrect(ExamAttemptQuestion $attemptQuestion, ExamAttemptAnswer $answer): bool
    {
        return \App\Support\ExamGrading::isCorrect($attemptQuestion->question, $answer->selected_option_ids ?? []);
    }
```

(Use a fully-qualified reference or add `use App\Support\ExamGrading;` to this file's imports — follow whichever import style the rest of this file already uses.)

- [ ] **Step 4: Create `SubmitGuestExamAttemptRequest`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitGuestExamAttemptRequest extends FormRequest
{
    /**
     * Public endpoint, no identity to check against — anyone may submit.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.selected_option_ids' => ['present', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
        ];
    }
}
```

- [ ] **Step 5: Create `GuestExamAttemptController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitGuestExamAttemptRequest;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\Exam;
use App\Models\Question;
use App\Support\ExamGrading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class GuestExamAttemptController extends Controller
{
    /**
     * Draw a fresh random question set for a per_attempt_random exam.
     * Not persisted anywhere — a second call can (and usually will)
     * return a different set.
     */
    public function randomDraw(Exam $exam): AnonymousResourceCollection
    {
        abort_unless($exam->isPubliclyTakeable(), 404);

        if ($exam->selection_mode !== 'per_attempt_random') {
            throw ValidationException::withMessages(['exam' => ['This exam does not use random question selection.']]);
        }

        $eligible = $exam->eligibleRandomQuestionIds();

        if ($eligible->count() < $exam->random_question_count) {
            throw ValidationException::withMessages(['exam' => ["Only {$eligible->count()} eligible question(s) are available, but this exam requires {$exam->random_question_count}."]]);
        }

        $questionIds = $eligible->shuffle()->take($exam->random_question_count)->values();
        $questions = Question::with('options')->whereIn('id', $questionIds)->get();

        return QuestionResource::collection($questions);
    }

    /**
     * Grade a submitted answer set against real correctness data and
     * return the result. Never creates an ExamAttempt/
     * ExamAttemptQuestion/ExamAttemptAnswer row — nothing here is
     * persisted under any circumstance.
     */
    public function submit(SubmitGuestExamAttemptRequest $request, Exam $exam): JsonResponse
    {
        abort_unless($exam->isPubliclyTakeable(), 404);

        $answers = collect($request->validated('answers'))->keyBy('question_id');
        $submittedIds = $answers->keys()->unique()->values();

        if ($exam->selection_mode === 'per_attempt_random') {
            $eligible = $exam->eligibleRandomQuestionIds();

            if ($submittedIds->diff($eligible)->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => ['One or more submitted questions do not belong to this exam.']]);
            }

            if ($submittedIds->count() > $exam->random_question_count) {
                throw ValidationException::withMessages(['answers' => ["This exam only allows {$exam->random_question_count} question(s) per attempt."]]);
            }

            $gradeIds = $submittedIds;
        } else {
            $allowed = $exam->questions()->pluck('questions.id');

            if ($submittedIds->diff($allowed)->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => ['One or more submitted questions do not belong to this exam.']]);
            }

            $gradeIds = $allowed;
        }

        $questions = Question::with('options')->whereIn('id', $gradeIds)->get()->keyBy('id');

        $score = 0;
        $total = 0;
        $graded = [];

        foreach ($gradeIds as $questionId) {
            $question = $questions[$questionId];
            $marks = $exam->selection_mode === 'per_attempt_random' ? $exam->marks_per_question : $question->marks;
            $selectedOptionIds = $answers[$questionId]['selected_option_ids'] ?? [];
            $isCorrect = ExamGrading::isCorrect($question, $selectedOptionIds);
            $marksAwarded = $isCorrect ? $marks : 0;

            $total += $marks;
            $score += $marksAwarded;

            $graded[] = [
                'question_id' => $question->id,
                'selected_option_ids' => $selectedOptionIds,
                'is_correct' => $isCorrect,
                'marks_awarded' => $marksAwarded,
                'marks' => $marks,
                'correct_option_ids' => $question->options->where('is_correct', true)->pluck('id')->values()->all(),
            ];
        }

        $passed = ($exam->passing_score_percent !== null && $total > 0)
            ? (($score / $total) * 100) >= $exam->passing_score_percent
            : null;

        return response()->json([
            'exam_id' => $exam->id,
            'score' => $score,
            'total_possible_marks' => $total,
            'passed' => $passed,
            'answers' => $graded,
        ]);
    }
}
```

- [ ] **Step 6: Register the routes**

In `backend/routes/api.php`, add to the public route group, alongside the public `exams` registration from Task 1:

```php
    Route::post('/exams/{exam}/random-draw', [GuestExamAttemptController::class, 'randomDraw']);
    Route::post('/exams/{exam}/guest-submissions', [GuestExamAttemptController::class, 'submit']);
```

Add `use App\Http\Controllers\Api\V1\GuestExamAttemptController;` to this file's imports, following its existing alphabetical-by-controller ordering convention if one exists.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=GuestExamAttemptControllerTest --compact`, `php artisan test --filter=ExamGradingTest --compact`
Expected: PASS

- [ ] **Step 8: Run the full suite, format, and commit**

Run: `php artisan test --compact` — expect green, including every existing `ExamAttempt*Test` file unmodified (confirms the `ExamGrading` extraction is a pure refactor).

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ExamGrading.php app/Models/ExamAttempt.php app/Http/Requests/Api/V1/SubmitGuestExamAttemptRequest.php app/Http/Controllers/Api/V1/GuestExamAttemptController.php routes/api.php tests/Feature/GuestExamAttemptControllerTest.php tests/Unit/ExamGradingTest.php
git commit -m "Add stateless guest exam-taking: random-draw and guest-submissions endpoints"
```

---

## Task 3: Full backend test suite against MySQL

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1-2.

This plan adds no migrations, but this repo's documented SQLite/MySQL behavior gaps (see sub-projects 1-2's own final verification tasks, which each found real MySQL-only bugs invisible under SQLite) mean this is still the right final gate — a query-builder difference, a JSON-column comparison, or a `whereNull`/`orWhere` grouping issue in the new `index()` filter could behave differently under MySQL than SQLite even with no schema change involved.

- [ ] **Step 1: Confirm the test environment's DB connection targets MySQL**

Override via shell-exported `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` when invoking the test command (this repo's `phpunit.xml` defaults to SQLite and its `<env>` tags don't carry `force="true"`, so real shell-exported values take precedence). **Create a throwaway MySQL database for this run — never point it at a database that might hold real development data.**

- [ ] **Step 2: Run the full suite**

Run (with the MySQL env vars from Step 1 exported): `php artisan test --compact`
Expected: PASS, zero failures beyond any already-known, already-ruled-out-of-scope pre-existing failures (see sub-projects 1-2's own Task 10/Task 4 findings — a `UserSeeder`/`DatabaseSeeder` FK-ordering bug and a couple of ID-assertion/row-lock test fragilities, none of which this plan's two tasks touch).

- [ ] **Step 3: If anything fails, attribute it before fixing anything**

Before changing any code, determine whether a failure is caused by THIS plan's changes (Tasks 1-2) or is one of the already-known pre-existing failures from sub-projects 1-2's own final MySQL verification. If a failure is new and traces to a file this plan's Tasks 1-2 touched, fix it there, following the same minimal-fix discipline those two sub-projects' own Task 10/Task 4 used. If it's one of the already-known pre-existing failures, do not fix it — confirm by name/message that it matches, and move on.

- [ ] **Step 4: Commit if any fix-forward changes were made**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Fix stragglers found by the full MySQL test run"
```

(Skip this step entirely if Step 2 passed clean with no new failures.)
