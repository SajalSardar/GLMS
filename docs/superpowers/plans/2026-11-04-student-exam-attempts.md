# Student Exam-Taking + Grading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an eligible student start an attempt at a `published` `Exam`, save answers incrementally, submit for automatic grading, and view results — scoped to the student themselves, their linked guardian, or the exam's management/oversight roles.

**Architecture:** `ExamAttempt` is `TenantScoped`, denormalizing `school_id`/`district_id` from its parent `Exam`. `exam_attempt_questions` snapshots exactly which questions (and their marks) this attempt presents, for **every** selection mode — this is what grading and review reference, never the live exam. `exam_attempt_answers` records what the student picked per question; `is_correct`/`marks_awarded` stay `null` until finalization. Grading reduces to one rule regardless of question type: an answer is correct iff its selected-option-id set exactly equals the question's correct-option-id set (this naturally enforces "exactly one, and it's right" for `mcq_single`/`true_false`, since their correct-set size is always 1 — no type branching needed). Time-limit enforcement is **lazy**: any touch to an `in_progress` attempt (save-answer, submit, or a `GET`) first checks whether the deadline has passed and auto-finalizes if so — there is no queued `Job`/Horizon infrastructure in this codebase to do it any other way.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-11-04-student-exam-attempts-design.md`

## Global Constraints

- `exam_attempts` denormalizes `school_id`/`district_id` from its parent `Exam` at creation and uses `TenantScoped` — same reasoning as every other tenant-scoped table in this codebase.
- `exam_attempt_questions`/`exam_attempt_answers` have **no** tenant columns — always reached through their parent `ExamAttempt`, never queried standalone.
- `exam_attempt_questions.marks` is a **snapshot** taken at attempt-start — grading and `total_possible_marks` always reference this snapshot, never the live `Question.marks` or live `Exam` question list, so a later edit to the exam/question bank never retroactively changes an already-started or already-graded attempt.
- Grading never branches on `question_type`. The single rule — selected-option-id set equals correct-option-id set, exactly — is correct for `mcq_single`, `mcq_multiple`, and `true_false` alike.
- **Feedback gating in `ExamAttemptResource` is based on the attempt's `status`, not the viewer's `questions.update` permission.** This is a deliberate departure from `QuestionOptionResource`'s existing `is_correct`-stripping rule (which exists for the admin-facing question-bank views) — here, the viewer legitimately seeing their own (or their managed/linked student's) *graded result* is the whole point once `status === 'submitted'`, regardless of role. Do **not** nest the existing `QuestionResource`/`QuestionOptionResource` inside `ExamAttemptResource` — build the question/option shape directly inside `ExamAttemptResource`, gated on `$this->status === 'submitted'`, per Task 3's code below.
- **Lazy expiry, not a background job.** No Horizon/queued `Job` class exists anywhere in this codebase yet. `ExamAttempt::finalizeIfExpired()` (Task 4) is called at the top of every action that touches an `in_progress` attempt (`show()`, `saveAnswer()`, `submit()`).
- **`saveAnswer()`/`submit()` use FormRequests with identity-based `authorize()`** (`$this->user()?->id === $this->route('exam_attempt')->user_id`), the same "any authenticated user acts on their own record" convention as `UpdateChapterVideoProgressRequest`/`ChapterVideoController::updateProgress()`. Per that established precedent, these two actions are correctly **excluded** from `tests/Unit/ControllerAuthorizationConventionTest.php`'s `mutatingActions()` provider — that test is specifically for Policy-based `$this->authorize()` calls in a controller's own body, not FormRequest-level ownership checks. `start()` (Policy-based, no FormRequest) **does** get a row there; `show()`/`index()` are read actions and, per the exact same precedent `ExamController::show()`/`index()` already set, are verified by ordinary feature tests instead, not this structural one.
- `Exam::eligibleRandomQuestionIds()` (Task 1) must be the single source of truth for "which questions can a `per_attempt_random` draw pick from" — both `ExamController::randomize()` (refactored) and `ExamAttemptController::start()` (Task 3) call it; the logic must not be duplicated a third time.
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `ExamAttempt` schema and models; extract `Exam::eligibleRandomQuestionIds()`

**Files:**
- Create: `backend/database/migrations/2026_11_04_090000_create_exam_attempts_table.php`
- Create: `backend/database/migrations/2026_11_04_090100_create_exam_attempt_questions_table.php`
- Create: `backend/database/migrations/2026_11_04_090200_create_exam_attempt_answers_table.php`
- Create: `backend/app/Models/ExamAttempt.php`
- Create: `backend/app/Models/ExamAttemptQuestion.php`
- Create: `backend/app/Models/ExamAttemptAnswer.php`
- Modify: `backend/app/Models/Exam.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamController.php`
- Test: `backend/tests/Unit/ExamAttemptTenantScopeTest.php`
- Test: `backend/tests/Unit/ExamEligibleRandomQuestionIdsTest.php`

**Interfaces:**
- Consumes: `App\Models\Exam`, `App\Models\Chapter`, `App\Models\Question` (existing).
- Produces: `App\Models\ExamAttempt` (`TenantScoped`; fillable `exam_id, user_id, school_id, district_id, attempt_number, status, started_at, submitted_at, score, total_possible_marks, passed`; relations `exam(): BelongsTo`, `user(): BelongsTo`, `attemptQuestions(): HasMany` ordered by `order_index`, `answers(): HasMany`). `App\Models\ExamAttemptQuestion` (fillable `exam_attempt_id, question_id, order_index, marks`; relations `examAttempt(): BelongsTo`, `question(): BelongsTo`). `App\Models\ExamAttemptAnswer` (fillable `exam_attempt_id, question_id, selected_option_ids, is_correct, marks_awarded`; `selected_option_ids` cast to `array`; relations `examAttempt(): BelongsTo`, `question(): BelongsTo`). `Exam::attempts(): HasMany`. `Exam::eligibleRandomQuestionIds(): \Illuminate\Support\Collection` — Task 3's `ExamAttemptController::start()` consumes this by this exact name.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Unit/ExamAttemptTenantScopeTest.php`:

```php
<?php

namespace Tests\Unit;

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
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ExamAttemptTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private ExamAttempt $attemptA;

    private School $schoolA;

    private Exam $examA;

    private Question $questionA;

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

        $academicYearA = AcademicYear::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $academicYearB = AcademicYear::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->examA = Exam::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYearA->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual']);
        $examB = Exam::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYearB->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual']);

        $book = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'name' => 'Chapter 1']);
        $this->questionA = Question::create(['chapter_id' => $chapter->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q1']);

        $studentA = User::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $studentB = User::factory()->create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id]);

        $this->attemptA = ExamAttempt::create([
            'exam_id' => $this->examA->id,
            'user_id' => $studentA->id,
            'school_id' => $this->examA->school_id,
            'district_id' => $this->examA->district_id,
            'attempt_number' => 1,
            'started_at' => now(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examB->id,
            'user_id' => $studentB->id,
            'school_id' => $examB->school_id,
            'district_id' => $examB->district_id,
            'attempt_number' => 1,
            'started_at' => now(),
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_attempts(): void
    {
        $role = Role::create(['name' => 'school_admin_attempts', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = ExamAttempt::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->attemptA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_attempts(): void
    {
        $role = Role::create(['name' => 'national_admin_attempts', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, ExamAttempt::all());
    }

    public function test_status_defaults_to_in_progress(): void
    {
        $this->assertSame('in_progress', $this->attemptA->status);
    }

    public function test_attempt_question_has_no_tenant_columns_and_belongs_to_attempt(): void
    {
        $attemptQuestion = ExamAttemptQuestion::create(['exam_attempt_id' => $this->attemptA->id, 'question_id' => $this->questionA->id, 'marks' => 2]);

        $this->assertFalse($this->attemptA->getConnection()->getSchemaBuilder()->hasColumn('exam_attempt_questions', 'school_id'));
        $this->assertSame($this->attemptA->id, $attemptQuestion->examAttempt->id);
        $this->assertSame(0, $attemptQuestion->order_index);
    }

    public function test_attempt_answer_casts_selected_option_ids_to_array(): void
    {
        $answer = ExamAttemptAnswer::create(['exam_attempt_id' => $this->attemptA->id, 'question_id' => $this->questionA->id, 'selected_option_ids' => [10, 20]]);

        $this->assertSame([10, 20], $answer->refresh()->selected_option_ids);
    }
}
```

(`config/database.php`'s sqlite connection defaults `foreign_key_constraints` to `true`, and `phpunit.xml` does not override `DB_FOREIGN_KEYS` — foreign keys ARE enforced in this project's tests. Both tests above therefore use `$this->questionA`, a real persisted `Question`, rather than a bare literal id.)

`backend/tests/Unit/ExamEligibleRandomQuestionIdsTest.php`:

```php
<?php

namespace Tests\Unit;

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
use Tests\TestCase;

class ExamEligibleRandomQuestionIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_pool_respects_difficulty_and_random_chapters_filters(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $academicYear = AcademicYear::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Mathematics Book']);
        $chapterA = Chapter::create(['book_id' => $book->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Chapter 1']);
        $chapterB = Chapter::create(['book_id' => $book->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Chapter 2']);

        $easyInA = Question::create(['chapter_id' => $chapterA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Easy A', 'difficulty' => 'easy']);
        $hardInA = Question::create(['chapter_id' => $chapterA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Hard A', 'difficulty' => 'hard']);
        $hardInB = Question::create(['chapter_id' => $chapterB->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Hard B', 'difficulty' => 'hard']);

        $exam = Exam::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 1]);

        $this->assertEqualsCanonicalizing([$easyInA->id, $hardInA->id, $hardInB->id], $exam->eligibleRandomQuestionIds()->all());

        $exam->update(['random_difficulty' => 'hard']);
        $this->assertEqualsCanonicalizing([$hardInA->id, $hardInB->id], $exam->eligibleRandomQuestionIds()->all());

        $exam->randomChapters()->attach($chapterA->id);
        $this->assertSame([$hardInA->id], $exam->eligibleRandomQuestionIds()->all());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/ExamAttemptTenantScopeTest.php tests/Unit/ExamEligibleRandomQuestionIdsTest.php`
Expected: FAIL — `App\Models\ExamAttempt` does not exist; `Exam::eligibleRandomQuestionIds()` does not exist.

- [ ] **Step 3: Create the migrations**

`backend/database/migrations/2026_11_04_090000_create_exam_attempts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Denormalized from the exam at creation time (never taken
            // directly from the request) — exam_attempts is resolved by
            // direct route-model-binding, so it needs its own TenantScope
            // filter, same as every other tenant-scoped table.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('status')->default('in_progress');
            $table->dateTime('started_at');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('total_possible_marks')->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['exam_id', 'user_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
```

`backend/database/migrations/2026_11_04_090100_create_exam_attempt_questions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A snapshot taken at attempt-start, for every selection mode — not
     * just the random ones — so grading/review always has one uniform,
     * stable reference regardless of later edits to the live exam or
     * question bank. No tenant columns: always reached through its
     * parent ExamAttempt, never queried standalone.
     */
    public function up(): void
    {
        Schema::create('exam_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->unsignedSmallInteger('marks');
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_questions');
    }
};
```

`backend/database/migrations/2026_11_04_090200_create_exam_attempt_answers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No row at all means "not yet answered" — saveAnswer() always
     * creates/updates a row with an actual selected_option_ids value.
     * is_correct/marks_awarded stay null until finalization. No tenant
     * columns, same reasoning as exam_attempt_questions.
     */
    public function up(): void
    {
        Schema::create('exam_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->json('selected_option_ids')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('marks_awarded')->nullable();
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_answers');
    }
};
```

- [ ] **Step 4: Create the models**

`backend/app/Models/ExamAttempt.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamAttempt extends Model
{
    use SoftDeletes, TenantScoped;

    /**
     * In-memory default mirroring the migration's column default.
     */
    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected $fillable = [
        'exam_id',
        'user_id',
        'school_id',
        'district_id',
        'attempt_number',
        'status',
        'started_at',
        'submitted_at',
        'score',
        'total_possible_marks',
        'passed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'passed' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class)->orderBy('order_index');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAttemptAnswer::class);
    }
}
```

`backend/app/Models/ExamAttemptQuestion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttemptQuestion extends Model
{
    protected $attributes = [
        'order_index' => 0,
    ];

    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'order_index',
        'marks',
    ];

    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
```

`backend/app/Models/ExamAttemptAnswer.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttemptAnswer extends Model
{
    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'selected_option_ids',
        'is_correct',
        'marks_awarded',
    ];

    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
```

- [ ] **Step 5: Modify `Exam.php`** — add the `HasMany` import, the `attempts()` relation, and the `eligibleRandomQuestionIds()` method

Change the import block:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
```

Add after `randomChapters()`:

```php
    public function randomChapters(): BelongsToMany
    {
        return $this->belongsToMany(Chapter::class, 'exam_random_chapters');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * The single source of truth for "which questions can a
     * fixed_random/per_attempt_random draw pick from": every chapter
     * reachable through a book under this exam's (class_version_id,
     * subject_id), optionally narrowed by exam_random_chapters,
     * optionally filtered by random_difficulty. Used by both
     * ExamController::randomize() and ExamAttemptController::start() —
     * must not be duplicated a third time.
     *
     * @return Collection<int, int>
     */
    public function eligibleRandomQuestionIds(): Collection
    {
        $chapterIds = Chapter::query()
            ->whereHas('book', fn ($query) => $query->where('class_version_id', $this->class_version_id)->where('subject_id', $this->subject_id))
            ->pluck('id');

        $allowedChapterIds = $this->randomChapters()->pluck('chapters.id');

        if ($allowedChapterIds->isNotEmpty()) {
            $chapterIds = $chapterIds->intersect($allowedChapterIds);
        }

        return Question::query()
            ->whereIn('chapter_id', $chapterIds)
            ->when($this->random_difficulty, fn ($query, $value) => $query->where('difficulty', $value))
            ->pluck('id');
    }
```

- [ ] **Step 6: Refactor `ExamController::randomize()` to use the extracted method** — modify `backend/app/Http/Controllers/Api/V1/ExamController.php`

Replace the pool-computation block inside `randomize()`:

```php
        $chapterIds = Chapter::query()
            ->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
            ->pluck('id');

        $allowedChapterIds = $exam->randomChapters()->pluck('chapters.id');

        if ($allowedChapterIds->isNotEmpty()) {
            $chapterIds = $chapterIds->intersect($allowedChapterIds);
        }

        $eligible = Question::query()
            ->whereIn('chapter_id', $chapterIds)
            ->when($exam->random_difficulty, fn ($query, $value) => $query->where('difficulty', $value))
            ->pluck('id');
```

with:

```php
        $eligible = $exam->eligibleRandomQuestionIds();
```

Then remove the now-unused `use App\Models\Chapter;` and `use App\Models\Question;` import lines from `ExamController.php` (neither class is referenced anywhere else in that file after this change — double check this with a search before removing, in case a later part of this same file still references them).

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Unit/ExamAttemptTenantScopeTest.php tests/Unit/ExamEligibleRandomQuestionIdsTest.php`
Expected: PASS (6 tests total).
Also run: `php artisan test tests/Feature/ExamQuestionsControllerTest.php` to confirm the `randomize()` refactor didn't break anything — expected PASS, unchanged behavior.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_04_090000_create_exam_attempts_table.php database/migrations/2026_11_04_090100_create_exam_attempt_questions_table.php database/migrations/2026_11_04_090200_create_exam_attempt_answers_table.php app/Models/ExamAttempt.php app/Models/ExamAttemptQuestion.php app/Models/ExamAttemptAnswer.php app/Models/Exam.php app/Http/Controllers/Api/V1/ExamController.php tests/Unit/ExamAttemptTenantScopeTest.php tests/Unit/ExamEligibleRandomQuestionIdsTest.php
git commit -m "Add ExamAttempt models and tables; extract Exam::eligibleRandomQuestionIds()"
```

---

### Task 2: `exam_attempts.*` permissions

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly.
- Produces: permissions `exam_attempts.view` (national_admin, district_officer, school_admin, trainer, teacher — same list as `exams.view`), `exam_attempts.create` (student only). Task 3's `ExamAttemptPolicy` consumes these exact permission-name strings.

- [ ] **Step 1: Write the failing test** — modify `backend/tests/Feature/DatabaseSeederTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards against a repeat of the ChapterSeeder regression, where a
     * seeder referencing a dropped column (subject_id on chapters) aborted
     * the entire seed chain before permissions were ever seeded.
     */
    public function test_full_seed_chain_completes_without_error(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Permission::where('name', 'books.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_materials.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_videos.view')->exists());
        $this->assertTrue(Permission::where('name', 'questions.view')->exists());
        $this->assertTrue(Permission::where('name', 'exams.view')->exists());
        $this->assertTrue(Permission::where('name', 'exam_attempts.view')->exists());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: FAIL — `exam_attempts.view` permission does not exist.

- [ ] **Step 3: Add the permissions** — modify `backend/database/seeders/PermissionSeeder.php`, inserting right after the `exams.delete` entry and before `users.view`:

```php
            ['name' => 'exams.update', 'label' => 'Update exams'],
            ['name' => 'exams.delete', 'label' => 'Delete exams'],

            ['name' => 'exam_attempts.view', 'label' => 'View any exam attempt'],
            ['name' => 'exam_attempts.create', 'label' => 'Start, answer, and submit exam attempts'],

            ['name' => 'users.view', 'label' => 'View users'],
```

- [ ] **Step 4: Map the permissions to roles** — in `backend/database/seeders/RolePermissionSeeder.php`, replace the entire `$rolePermissions = [ ... ];` array with:

```php
        $rolePermissions = [
            'national_admin' => $allPermissions,

            'district_officer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'exams.view', 'exam_attempts.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'teacher_profiles.view', 'student_profiles.view',
            ],

            'school_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view',
                'schools.view', 'schools.update',
                'classes.view', 'classes.create', 'classes.update', 'classes.delete',
                'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view',
                'academic_years.view', 'academic_years.create', 'academic_years.update', 'academic_years.delete',
                'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete',
                'class_routines.view', 'class_routines.create', 'class_routines.update', 'class_routines.delete',
                'class_versions.view', 'class_versions.create', 'class_versions.update', 'class_versions.delete',
                'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete',
                'sections.view', 'sections.create', 'sections.update', 'sections.delete', 'sections.override_capacity',
                'subject_teacher_assignments.view', 'subject_teacher_assignments.create',
                'subject_teacher_assignments.update', 'subject_teacher_assignments.delete',
                'timetable_entries.view', 'timetable_entries.create', 'timetable_entries.update',
                'timetable_entries.delete', 'timetable_entries.publish',
                'users.view', 'users.create', 'users.update',
                'teacher_profiles.view', 'teacher_profiles.create', 'teacher_profiles.update', 'teacher_profiles.delete',
                'student_profiles.view', 'student_profiles.create', 'student_profiles.update', 'student_profiles.delete',
                'guardians.view', 'guardians.create', 'guardians.update', 'guardians.delete',
            ],

            'teacher' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'questions.view', 'questions.create', 'questions.update',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view',
                'teacher_profiles.view', 'student_profiles.view',
            ],

            'student' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'exam_attempts.create',
            ],

            'trainer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'academic_years.view', 'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'subjects.view', 'subjects.create', 'subjects.update',
                'chapter_videos.view', 'chapter_videos.create', 'chapter_videos.update',
                'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update',
                'books.view', 'books.create', 'books.update',
                'chapters.view', 'chapters.create', 'chapters.update',
                'questions.view', 'questions.create', 'questions.update',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view',
                'teacher_profiles.view',
            ],

            'office_staff' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'teacher_profiles.view', 'student_profiles.view',
                'guardians.view', 'guardians.create', 'guardians.update',
            ],

            'parent_guardian' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'guardians.view',
            ],

            'it_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
            ],

            'auditor' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'users.view', 'roles.view', 'permissions.view',
                'teacher_profiles.view', 'student_profiles.view', 'guardians.view',
            ],
        ];
```

This is a full-array replacement — verify role-by-role that only `district_officer`/`school_admin`/`teacher`/`trainer` gained `exam_attempts.view`, only `student` gained `exam_attempts.create`, and every other permission on every role is byte-for-byte identical to before. `parent_guardian`/`office_staff`/`it_admin`/`auditor` must end up with **no** `exam_attempts.*` entry at all — their access to a linked/managed attempt is a relationship check in the Policy (Task 3), not a flat permission grant.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "Seed exam_attempts.* permissions and map to roles"
```

---

### Task 3: Start, show, and list attempts

**Files:**
- Create: `backend/app/Policies/ExamAttemptPolicy.php`
- Create: `backend/app/Http/Resources/Api/V1/ExamAttemptResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Unit/ControllerAuthorizationConventionTest.php`
- Test: `backend/tests/Feature/ExamAttemptControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\ExamAttempt`/`ExamAttemptQuestion` (Task 1), `exam_attempts.*` permissions (Task 2), `Exam::eligibleRandomQuestionIds()` (Task 1), `App\Models\StudentProfile` (existing, via `->section->shift` chain), `App\Models\Guardian` (existing, via `User::guardian` → `Guardian::students()`).
- Produces: routes `POST /api/v1/exams/{exam}/attempts`, `GET /api/v1/exams/{exam}/attempts`, `GET /api/v1/exam-attempts/{exam_attempt}`. `ExamAttemptController` with a `private const WITH` eager-load list Task 4's `saveAnswer()`/`submit()` reuse by the same name. `ExamAttemptPolicy` with `view`/`create`/`viewAny` abilities — Task 4 does not add any new ability (it uses identity-checking FormRequests instead, per the Global Constraints).

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ExamAttemptControllerTest.php`

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
use App\Models\Guardian;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ExamAttemptControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private ClassVersion $version;

    private Subject $subject;

    private AcademicYear $academicYear;

    private Chapter $chapter;

    private Section $section;

    private Exam $manualExam;

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
        $this->academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $shift = Shift::create(['class_version_id' => $this->version->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $book = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Mathematics Book']);
        $this->chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Chapter 1']);

        $this->manualExam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual', 'status' => 'published']);

        $question = Question::create(['chapter_id' => $this->chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q1', 'marks' => 5]);
        $this->manualExam->questions()->attach($question->id, ['order_index' => 0]);
    }

    private function eligibleStudent(): User
    {
        $role = Role::create(['name' => 'student_'.uniqid(), 'label' => 'Student']);
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $this->section->id]);

        return $user;
    }

    public function test_an_eligible_student_can_start_an_attempt(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonCount(1, 'data.questions');
    }

    public function test_start_is_rejected_for_an_unpublished_exam(): void
    {
        $this->manualExam->update(['status' => 'draft']);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_start_is_rejected_for_a_student_in_a_different_class_version(): void
    {
        $otherClass = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 7']);
        $otherVersion = ClassVersion::create(['class_id' => $otherClass->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Other Version']);
        $otherShift = Shift::create(['class_version_id' => $otherVersion->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $otherSection = Section::create(['shift_id' => $otherShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section B']);

        $role = Role::create(['name' => 'student_ineligible', 'label' => 'Student']);
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $otherSection->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_start_is_rejected_once_max_attempts_is_exceeded(): void
    {
        $this->manualExam->update(['max_attempts' => 1]);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertCreated();
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertUnprocessable();
    }

    public function test_per_attempt_random_start_performs_the_draw_and_rejects_a_too_small_pool(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 5, 'marks_per_question' => 2, 'status' => 'published']);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$exam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_show_is_visible_to_the_owner_a_linked_guardian_and_management_but_not_an_unrelated_student(): void
    {
        $student = $this->eligibleStudent();
        $attemptId = $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->json('data.id');

        $this->actingAs($student, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $guardian = Guardian::create(['name' => 'Guardian', 'phone' => '01700000001', 'user_id' => User::factory()->create()->id]);
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $guardian->students()->attach($studentProfile->id, ['relation' => 'father', 'is_primary' => true]);
        $this->actingAs($guardian->user, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $manager = $this->actorWithPermissions(['exam_attempts.view'], $this->school->id, $this->school->district_id);
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $unrelatedStudent = $this->eligibleStudent();
        $this->actingAs($unrelatedStudent, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertForbidden();
    }

    public function test_index_lists_attempts_for_management_but_not_for_a_student(): void
    {
        $student = $this->eligibleStudent();
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertCreated();

        $manager = $this->actorWithPermissions(['exam_attempts.view'], $this->school->id, $this->school->district_id);
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($student, 'sanctum')->getJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ExamAttemptControllerTest.php`
Expected: FAIL — route `/api/v1/exams/{exam}/attempts` does not exist.

- [ ] **Step 3: Create the policy**

`backend/app/Policies/ExamAttemptPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    /**
     * Viewing a single attempt: the attempt's own student, their linked
     * guardian, or a management/oversight role holding exam_attempts.view.
     * No flat permission is required for the first two — same "own
     * record" precedent as ChapterVideoController::updateProgress().
     */
    public function view(User $user, ExamAttempt $examAttempt): bool
    {
        if ($user->id === $examAttempt->user_id) {
            return true;
        }

        if ($user->hasPermission('exam_attempts.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('user_id', $examAttempt->user_id)->exists();
    }

    /**
     * Listing every attempt for an exam — management/oversight only,
     * never a student's own-attempts view (which goes through view()
     * on a single already-known attempt instead).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_attempts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exam_attempts.create');
    }
}
```

- [ ] **Step 4: Create the resource**

`backend/app/Http/Resources/Api/V1/ExamAttemptResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'user_id' => $this->user_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
            'score' => $this->score,
            'total_possible_marks' => $this->total_possible_marks,
            'passed' => $this->passed,
            // Built directly here rather than nesting QuestionResource/
            // QuestionOptionResource — their is_correct gate is based on
            // the VIEWER's questions.update permission, which is the
            // wrong rule for a graded result: here, is_correct must show
            // once status is 'submitted', regardless of the viewer's
            // role, and must NEVER show while still in_progress.
            'questions' => $this->when(
                $this->relationLoaded('attemptQuestions'),
                function () {
                    $showFeedback = $this->status === 'submitted';

                    return $this->attemptQuestions->map(function ($attemptQuestion) use ($showFeedback) {
                        $question = $attemptQuestion->question;
                        $answer = $this->answers->firstWhere('question_id', $attemptQuestion->question_id);

                        return [
                            'question_id' => $question->id,
                            'question_type' => $question->question_type,
                            'question_text' => $question->question_text,
                            'marks' => $attemptQuestion->marks,
                            'order_index' => $attemptQuestion->order_index,
                            'options' => $question->options->map(fn ($option) => [
                                'id' => $option->id,
                                'option_text' => $option->option_text,
                                'is_correct' => $showFeedback ? $option->is_correct : null,
                            ]),
                            'selected_option_ids' => $answer?->selected_option_ids,
                            'is_correct' => $showFeedback ? $answer?->is_correct : null,
                            'marks_awarded' => $showFeedback ? $answer?->marks_awarded : null,
                        ];
                    });
                }
            ),
        ];
    }
}
```

- [ ] **Step 5: Create the controller**

`backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptController extends Controller
{
    /**
     * Shared eager-load list — Task 4's saveAnswer()/submit() reuse this
     * by the same name so the response shape stays consistent.
     */
    private const WITH = ['attemptQuestions.question.options', 'answers'];

    public function start(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('create', ExamAttempt::class);

        if ($exam->status !== 'published') {
            throw ValidationException::withMessages(['exam' => ['This exam is not published.']]);
        }

        $now = now();

        if ($exam->available_from && $now->lessThan($exam->available_from)) {
            throw ValidationException::withMessages(['exam' => ['This exam is not yet available.']]);
        }

        if ($exam->available_until && $now->greaterThan($exam->available_until)) {
            throw ValidationException::withMessages(['exam' => ['This exam is no longer available.']]);
        }

        $user = $request->user();
        $studentProfile = $user->studentProfile;
        $classVersionId = $studentProfile?->section?->shift?->class_version_id;
        $academicYearId = $studentProfile?->section?->shift?->academic_year_id;

        if ($classVersionId !== $exam->class_version_id || $academicYearId !== $exam->academic_year_id) {
            throw ValidationException::withMessages(['exam' => ['You are not enrolled in the class version/academic year this exam belongs to.']]);
        }

        $attemptNumber = (int) ExamAttempt::where('exam_id', $exam->id)->where('user_id', $user->id)->max('attempt_number') + 1;

        if ($exam->max_attempts && $attemptNumber > $exam->max_attempts) {
            throw ValidationException::withMessages(['exam' => ['You have used all allowed attempts for this exam.']]);
        }

        if ($exam->selection_mode === 'per_attempt_random') {
            $eligible = $exam->eligibleRandomQuestionIds();

            if ($eligible->count() < $exam->random_question_count) {
                throw ValidationException::withMessages(['exam' => ["Only {$eligible->count()} eligible question(s) are available, but this exam requires {$exam->random_question_count}."]]);
            }

            $questionIds = $eligible->shuffle()->take($exam->random_question_count)->values();
        } else {
            $questionIds = $exam->questions()->pluck('questions.id');

            if ($questionIds->isEmpty()) {
                throw ValidationException::withMessages(['exam' => ['This exam has no questions configured.']]);
            }
        }

        $questions = Question::whereIn('id', $questionIds)->get()->keyBy('id');

        $attempt = DB::transaction(function () use ($exam, $user, $attemptNumber, $questionIds, $questions) {
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'school_id' => $exam->school_id,
                'district_id' => $exam->district_id,
                'attempt_number' => $attemptNumber,
                'started_at' => now(),
            ]);

            foreach ($questionIds->values() as $index => $questionId) {
                ExamAttemptQuestion::create([
                    'exam_attempt_id' => $attempt->id,
                    'question_id' => $questionId,
                    'order_index' => $index,
                    'marks' => $questions[$questionId]->marks,
                ]);
            }

            return $attempt;
        });

        return (new ExamAttemptResource($attempt->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExamAttempt $exam_attempt): ExamAttemptResource
    {
        $this->authorize('view', $exam_attempt);

        return new ExamAttemptResource($exam_attempt->load(self::WITH));
    }

    public function index(Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExamAttempt::class);

        $attempts = $exam->attempts()->orderBy('user_id')->orderBy('attempt_number')->get();

        return ExamAttemptResource::collection($attempts);
    }
}
```

- [ ] **Step 6: Register routes** — modify `backend/routes/api.php`

Add the import alongside the other controller imports (alphabetical, after `ExamController`):

```php
use App\Http\Controllers\Api\V1\ExamAttemptController;
use App\Http\Controllers\Api\V1\ExamController;
```

Add the routes inside the `auth:sanctum` group, right after the existing `exams` block:

```php
        Route::apiResource('exams', ExamController::class);
        Route::patch('/exams/{exam}/questions', [ExamController::class, 'setQuestions']);
        Route::patch('/exams/{exam}/random-chapters', [ExamController::class, 'setRandomChapters']);
        Route::post('/exams/{exam}/randomize', [ExamController::class, 'randomize']);

        Route::post('/exams/{exam}/attempts', [ExamAttemptController::class, 'start']);
        Route::get('/exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
        Route::get('/exam-attempts/{exam_attempt}', [ExamAttemptController::class, 'show']);
```

- [ ] **Step 7: Add `ExamAttemptController::start` to the structural authorization convention test** — modify `backend/tests/Unit/ControllerAuthorizationConventionTest.php`

Add the import alongside the other controller imports:

```php
use App\Http\Controllers\Api\V1\ExamAttemptController;
```

Add one row to the `mutatingActions()` provider (place it near the other `Exam*` entries if any exist there, or alphabetically):

```php
            [ExamAttemptController::class, 'start'],
```

(`show`/`index` are read actions — per this file's own established convention, they are verified by feature tests only, same as `ExamController::show`/`index`, not by this structural test.)

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/ExamAttemptControllerTest.php tests/Unit/ControllerAuthorizationConventionTest.php`
Expected: PASS (7 feature tests + the existing convention-test suite, now one row larger).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/ExamAttemptPolicy.php app/Http/Resources/Api/V1/ExamAttemptResource.php app/Http/Controllers/Api/V1/ExamAttemptController.php routes/api.php tests/Unit/ControllerAuthorizationConventionTest.php tests/Feature/ExamAttemptControllerTest.php
git commit -m "Add exam attempt start/show/list with ownership+guardian+management visibility"
```

---

### Task 4: Save answer, submit, grading, lazy time-limit expiry

**Files:**
- Create: `backend/app/Http/Requests/Api/V1/SaveExamAttemptAnswerRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/SubmitExamAttemptRequest.php`
- Modify: `backend/app/Models/ExamAttempt.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ExamAttemptGradingTest.php`

**Interfaces:**
- Consumes: `App\Models\ExamAttempt`/`ExamAttemptAnswer` (Task 1), `ExamAttemptController::WITH` (Task 3, same file).
- Produces: routes `PUT /api/v1/exam-attempts/{exam_attempt}/answers/{question}`, `POST /api/v1/exam-attempts/{exam_attempt}/submit`; `ExamAttempt::finalize()`/`finalizeIfExpired()`; `ExamAttemptController::saveAnswer()`/`submit()`. This is the LAST task in the plan — run the full suite before committing.

- [ ] **Step 1: Write the failing tests** — create `backend/tests/Feature/ExamAttemptGradingTest.php`

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
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamAttemptGradingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $exam;

    private User $student;

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
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
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
        $this->student = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
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
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ExamAttemptGradingTest.php`
Expected: FAIL — route `/api/v1/exam-attempts/{exam_attempt}/answers/{question}` does not exist.

- [ ] **Step 3: Create the requests**

`backend/app/Http/Requests/Api/V1/SaveExamAttemptAnswerRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveExamAttemptAnswerRequest extends FormRequest
{
    /**
     * Any authenticated user may save an answer on their own attempt —
     * no flat permission needed, same "own record" convention as
     * UpdateChapterVideoProgressRequest.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt && $this->user()?->id === $attempt->user_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'selected_option_ids' => ['required', 'array'],
            'selected_option_ids.*' => ['integer'],
        ];
    }
}
```

`backend/app/Http/Requests/Api/V1/SubmitExamAttemptRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;

class SubmitExamAttemptRequest extends FormRequest
{
    /**
     * Any authenticated user may submit their own attempt — same
     * convention as SaveExamAttemptAnswerRequest.
     */
    public function authorize(): bool
    {
        $attempt = $this->route('exam_attempt');

        return $attempt instanceof ExamAttempt && $this->user()?->id === $attempt->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Add `finalize()`/`finalizeIfExpired()` to `ExamAttempt.php`** — modify `backend/app/Models/ExamAttempt.php`

Add the import:

```php
use Illuminate\Support\Carbon;
```

Add after `answers()`:

```php
    /**
     * If this attempt is in_progress and its exam's time limit has
     * elapsed, finalizes it NOW (grading whatever answers already
     * exist) and returns true. A no-op — returns false — if the attempt
     * isn't in_progress, the exam is untimed, or the deadline hasn't
     * passed yet. Called at the top of every action that touches an
     * in_progress attempt (show, saveAnswer, submit) since this
     * codebase has no queued Job/Horizon infrastructure to do it any
     * other way.
     */
    public function finalizeIfExpired(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $exam = $this->exam;

        if (! $exam->time_limit_minutes) {
            return false;
        }

        $deadline = $this->started_at->copy()->addMinutes($exam->time_limit_minutes);

        if (now()->lessThan($deadline)) {
            return false;
        }

        $this->finalize($deadline);

        return true;
    }

    /**
     * Grades every attempt_question against its recorded answer (an
     * unanswered question grades as 0) and marks this attempt
     * submitted. The correctness rule is the same regardless of
     * question_type: the selected-option-id set must exactly equal the
     * correct-option-id set — this is already right for mcq_single and
     * true_false, since their correct set always has exactly one
     * member.
     */
    public function finalize(?Carbon $at = null): void
    {
        $this->loadMissing(['attemptQuestions.question.options', 'answers']);

        $score = 0;
        $total = 0;

        foreach ($this->attemptQuestions as $attemptQuestion) {
            $total += $attemptQuestion->marks;

            $answer = $this->answers->firstWhere('question_id', $attemptQuestion->question_id);
            $isCorrect = $answer !== null && $this->answerIsCorrect($attemptQuestion, $answer);
            $marksAwarded = $isCorrect ? $attemptQuestion->marks : 0;

            if ($answer !== null) {
                $answer->update(['is_correct' => $isCorrect, 'marks_awarded' => $marksAwarded]);
            }

            $score += $marksAwarded;
        }

        $passed = ($this->exam->passing_score_percent !== null && $total > 0)
            ? (($score / $total) * 100) >= $this->exam->passing_score_percent
            : null;

        $this->update([
            'status' => 'submitted',
            'submitted_at' => $at ?? now(),
            'score' => $score,
            'total_possible_marks' => $total,
            'passed' => $passed,
        ]);
    }

    private function answerIsCorrect(ExamAttemptQuestion $attemptQuestion, ExamAttemptAnswer $answer): bool
    {
        $correctIds = $attemptQuestion->question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selectedIds = collect($answer->selected_option_ids ?? [])->sort()->values()->all();

        return $correctIds === $selectedIds;
    }
```

- [ ] **Step 5: Add the controller actions** — modify `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`

Add imports:

```php
use App\Http\Requests\Api\V1\SaveExamAttemptAnswerRequest;
use App\Http\Requests\Api\V1\SubmitExamAttemptRequest;
use App\Models\ExamAttemptAnswer;
```

(`Question` is already imported from Task 3 for `start()` — do not duplicate that `use` line.)

Modify `show()` to trigger lazy expiry first:

```php
    public function show(ExamAttempt $exam_attempt): ExamAttemptResource
    {
        $this->authorize('view', $exam_attempt);

        $exam_attempt->finalizeIfExpired();

        return new ExamAttemptResource($exam_attempt->load(self::WITH));
    }
```

Add after `show()`:

```php
    public function saveAnswer(SaveExamAttemptAnswerRequest $request, ExamAttempt $exam_attempt, Question $question): JsonResponse
    {
        if ($exam_attempt->finalizeIfExpired()) {
            throw ValidationException::withMessages(['exam_attempt' => ['This attempt has already been finalized due to time expiry.']]);
        }

        if ($exam_attempt->status !== 'in_progress') {
            throw ValidationException::withMessages(['exam_attempt' => ['This attempt is no longer in progress.']]);
        }

        if (! $exam_attempt->attemptQuestions()->where('question_id', $question->id)->exists()) {
            abort(404);
        }

        $answer = ExamAttemptAnswer::updateOrCreate(
            ['exam_attempt_id' => $exam_attempt->id, 'question_id' => $question->id],
            ['selected_option_ids' => $request->validated()['selected_option_ids']]
        );

        return response()->json([
            'question_id' => $question->id,
            'selected_option_ids' => $answer->selected_option_ids,
            'saved_at' => $answer->updated_at,
        ]);
    }

    public function submit(SubmitExamAttemptRequest $request, ExamAttempt $exam_attempt): ExamAttemptResource
    {
        if ($exam_attempt->status === 'in_progress') {
            $exam_attempt->finalizeIfExpired() || $exam_attempt->finalize();
        }

        return new ExamAttemptResource($exam_attempt->load(self::WITH));
    }
```

- [ ] **Step 6: Register routes** — modify `backend/routes/api.php`. Task 3 already added these three lines:

```php
        Route::post('/exams/{exam}/attempts', [ExamAttemptController::class, 'start']);
        Route::get('/exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
        Route::get('/exam-attempts/{exam_attempt}', [ExamAttemptController::class, 'show']);
```

**Add two new lines right after them** (do not re-add or duplicate the three lines above — they already exist):

```php
        Route::put('/exam-attempts/{exam_attempt}/answers/{question}', [ExamAttemptController::class, 'saveAnswer']);
        Route::post('/exam-attempts/{exam_attempt}/submit', [ExamAttemptController::class, 'submit']);
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/ExamAttemptGradingTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — no regressions in any existing test.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/SaveExamAttemptAnswerRequest.php app/Http/Requests/Api/V1/SubmitExamAttemptRequest.php app/Models/ExamAttempt.php app/Http/Controllers/Api/V1/ExamAttemptController.php routes/api.php tests/Feature/ExamAttemptGradingTest.php
git commit -m "Add answer-saving, submission, grading, and lazy time-limit expiry"
```
