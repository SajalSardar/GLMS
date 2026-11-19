# Student Practice Exam Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the student frontend's pre-built-exam list with a self-serve class→subject→chapter-checkbox practice exam builder, tiered pass/fail feedback, and a certificate page — while restricting guest (anonymous) exam access to practice exams only.

**Architecture:** Almost everything reuses existing backend exam-attempt/grading infrastructure unchanged. The only new backend surface is one endpoint that provisions a hidden, student-created `Exam` row (`is_practice = true`) from a chapter selection; the existing `ExamRunnerPage.tsx`/`ExamAttemptController`/`GuestExamAttemptController` then run it exactly like any other exam. A small existing method (`Exam::eligibleRandomQuestionIds()`) gets a backward-compatible fix to support chapters spanning multiple schools, and the guest endpoints gain an `is_practice` gate so teacher-created exams can no longer be taken without logging in.

**Tech Stack:** Laravel 13 / PHP 8.5 backend (PHPUnit feature tests), React 19 + TypeScript + React Router 7 + Tailwind v4 student frontend (vitest for `api-client` only; page-level correctness verified live via Playwright).

**Spec:** `docs/superpowers/specs/2026-11-19-practice-exam-redesign-design.md`

## Global Constraints

- Question counts: 1 chapter selected → 20 questions; 2+ chapters → 40 questions. Both configurable via env (`PRACTICE_EXAM_SINGLE_CHAPTER_QUESTIONS`, `PRACTICE_EXAM_MULTI_CHAPTER_QUESTIONS`).
- Score tiers (percent of total marks): `< 40` → failed, `< 60` → pass, `< 80` → good, `>= 80` → best. Cutoffs configurable via env (`PRACTICE_EXAM_TIER_PASS_PERCENT` = 40, `PRACTICE_EXAM_TIER_GOOD_PERCENT` = 60, `PRACTICE_EXAM_TIER_BEST_PERCENT` = 80).
- Only pass/good/best tiers get a "View certificate" button; failed does not.
- Guest (anonymous) exam access is exclusive to practice exams (`is_practice = true`). Teacher-created exams require login and the existing same-school/class enrollment check.
- No persistence for the certificate itself; it reads only from React Router navigation `state` set at the moment the student finishes.
- `php artisan test --filter=X` for backend tests; `vendor/bin/pint --dirty --format agent` after every backend task with PHP changes; `npx tsc --noEmit -p apps/student/tsconfig.json` after every frontend task; `npx vitest run packages/api-client` after `api-client` changes.

---

## Task 1: Fix `Exam::eligibleRandomQuestionIds()` for cross-school chapter pooling

**Files:**
- Modify: `backend/app/Models/Exam.php` (the `eligibleRandomQuestionIds()` method, and its `$fillable` array)
- Create: `backend/database/migrations/2026_11_19_120000_add_is_practice_to_exams_table.php`
- Test: `backend/tests/Feature/ExamAttemptControllerTest.php` (new test method)

**Interfaces:**
- Produces: `exams.is_practice` column (boolean, default `false`), added to `Exam::$fillable`. `Exam::eligibleRandomQuestionIds(): Collection<int,int>` — same signature, fixed behavior (see below).

- [ ] **Step 1: Create the migration**

```bash
cd backend
php artisan make:migration add_is_practice_to_exams_table --no-interaction
```

Edit the generated file to:

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
     * A practice exam is created by a student (or on a guest's behalf)
     * from an ad-hoc chapter selection, not by a teacher/admin through
     * the normal exam-management flow — it must never appear in any
     * exam listing. See docs/superpowers/specs/2026-11-19-practice-exam-redesign-design.md.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('is_practice')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('is_practice');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_11_19_120000_add_is_practice_to_exams_table ... DONE`

- [ ] **Step 3: Add `is_practice` to `Exam::$fillable`**

In `backend/app/Models/Exam.php`, find the `$fillable` array (starts `protected $fillable = [`) and add `'is_practice',` right after `'status',`.

- [ ] **Step 4: Write the failing test proving the current cross-school bug**

Add to `backend/tests/Feature/ExamAttemptControllerTest.php`, inside the `ExamAttemptControllerTest` class (anywhere among the other test methods):

```php
    public function test_eligible_random_question_ids_pools_chapters_across_class_versions_when_narrowed(): void
    {
        $otherSchool = School::create(['division_id' => $this->school->division_id, 'district_id' => $this->school->district_id, 'upazila_id' => $this->school->upazila_id, 'name' => 'Other School']);
        $otherClass = SchoolClass::create(['name' => 'Class 6 Other']);
        $otherVersion = ClassVersion::create(['class_id' => $otherClass->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Version']);
        $otherBook = Book::create(['class_version_id' => $otherVersion->id, 'subject_id' => $this->subject->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Book']);
        $otherChapter = Chapter::create(['book_id' => $otherBook->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Chapter']);
        $otherQuestion = Question::create(['chapter_id' => $otherChapter->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Other Q', 'marks' => 1]);

        // $this->chapter (from setUp) belongs to $this->version's school;
        // $otherChapter belongs to a completely different school/class_version.
        // Narrowing the exam's own class_version_id/subject_id to $this->version
        // but pointing exam_random_chapters at BOTH chapters must still surface
        // both chapters' questions — not just $this->chapter's.
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Cross-School Practice', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 2, 'marks_per_question' => 1, 'status' => 'published']);

        $ownQuestion = Question::create(['chapter_id' => $this->chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Own Q', 'marks' => 1]);

        $exam->randomChapters()->attach([$this->chapter->id, $otherChapter->id]);

        $eligible = $exam->eligibleRandomQuestionIds();

        $this->assertTrue($eligible->contains($ownQuestion->id));
        $this->assertTrue($eligible->contains($otherQuestion->id));
    }

    public function test_eligible_random_question_ids_still_filters_to_the_exams_own_class_version_when_not_narrowed(): void
    {
        $otherSchool = School::create(['division_id' => $this->school->division_id, 'district_id' => $this->school->district_id, 'upazila_id' => $this->school->upazila_id, 'name' => 'Other School']);
        $otherClass = SchoolClass::create(['name' => 'Class 6 Other 2']);
        $otherVersion = ClassVersion::create(['class_id' => $otherClass->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Version 2']);
        $otherBook = Book::create(['class_version_id' => $otherVersion->id, 'subject_id' => $this->subject->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Book 2']);
        $otherChapter = Chapter::create(['book_id' => $otherBook->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Other Chapter 2']);
        Question::create(['chapter_id' => $otherChapter->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Other Q 2', 'marks' => 1]);

        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Unnarrowed', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 1, 'marks_per_question' => 1, 'status' => 'published']);

        $ownQuestion = Question::create(['chapter_id' => $this->chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Own Q 2', 'marks' => 1]);

        // No exam_random_chapters attached at all — must fall back to
        // "every chapter under this exam's own class_version_id/subject_id",
        // exactly as before this task's change.
        $eligible = $exam->eligibleRandomQuestionIds();

        $this->assertTrue($eligible->contains($ownQuestion->id));
        $this->assertCount(1, $eligible);
    }
```

- [ ] **Step 5: Run the new tests to verify the first one fails**

Run: `php artisan test --filter=test_eligible_random_question_ids_pools_chapters_across_class_versions_when_narrowed`
Expected: FAIL — `$eligible` does not contain `$otherQuestion->id` (it was filtered out by the pre-existing class_version_id pre-filter before the intersection even runs).

- [ ] **Step 6: Fix `eligibleRandomQuestionIds()`**

In `backend/app/Models/Exam.php`, replace the existing method body:

```php
    public function eligibleRandomQuestionIds(): Collection
    {
        $allowedChapterIds = $this->randomChapters()->pluck('chapters.id');

        // When exam_random_chapters is explicitly set, trust it directly —
        // it may span multiple schools' class_versions (a practice exam's
        // chapters are pooled cross-school by the student's own picks).
        // Only fall back to "every chapter under this exam's own
        // (class_version_id, subject_id)" when nothing has been narrowed.
        // Behavior-preserving for every pre-existing exam:
        // SetExamRandomChaptersRequest already requires any chapter it
        // accepts to belong to the exam's own class_version_id/subject_id,
        // so for those exams this is the exact same set as before.
        $chapterIds = $allowedChapterIds->isNotEmpty()
            ? $allowedChapterIds
            : Chapter::query()
                ->whereHas('book', fn ($query) => $query->where('class_version_id', $this->class_version_id)->where('subject_id', $this->subject_id))
                ->pluck('id');

        return Question::query()
            ->whereIn('chapter_id', $chapterIds)
            ->when($this->random_difficulty, fn ($query, $value) => $query->where('difficulty', $value))
            ->pluck('id');
    }
```

Update the method's docblock (directly above it) to drop the now-inaccurate "every chapter reachable through a book under this exam's (class_version_id, subject_id), optionally narrowed by exam_random_chapters" phrasing — replace with:

```php
    /**
     * The single source of truth for "which questions can a
     * fixed_random/per_attempt_random draw pick from": exam_random_chapters
     * directly when set (chapters may span multiple schools), otherwise
     * every chapter reachable through a book under this exam's
     * (class_version_id, subject_id). Optionally filtered by
     * random_difficulty. Used by both ExamController::randomize() and
     * ExamAttemptController::start() — must not be duplicated a third time.
     *
     * @return Collection<int, int>
     */
```

- [ ] **Step 7: Run both new tests and the full ExamAttemptControllerTest file**

Run: `php artisan test --filter=ExamAttemptControllerTest`
Expected: PASS (all tests in the file, including the two new ones)

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: `{"result":"passed"}` or auto-fixed cleanly

- [ ] **Step 9: Run the full backend suite**

Run: `php artisan test --compact`
Expected: all tests pass (confirms the migration/fillable change and the method rewrite don't break anything else that calls `eligibleRandomQuestionIds()`, e.g. `ExamController::randomize()`)

- [ ] **Step 10: Commit**

```bash
git add backend/database/migrations/2026_11_19_120000_add_is_practice_to_exams_table.php backend/app/Models/Exam.php backend/tests/Feature/ExamAttemptControllerTest.php
git commit -m "Add exams.is_practice column; fix eligibleRandomQuestionIds() for cross-school chapter pooling"
```

---

## Task 2: Expose and hide `is_practice`

**Files:**
- Modify: `backend/app/Http/Resources/Api/V1/ExamResource.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamController.php` (`index()` method)
- Modify: `frontend/packages/api-client/src/index.ts` (`Exam` interface)
- Test: `backend/tests/Feature/ExamControllerTest.php` (new test method)

**Interfaces:**
- Consumes: `exams.is_practice` column from Task 1.
- Produces: `ExamResource` output includes `is_practice: boolean`. `GET /api/v1/exams` never returns a row with `is_practice = true`, for any actor. Frontend `Exam` type gains `is_practice: boolean`.

- [ ] **Step 1: Add `is_practice` to `ExamResource`**

In `backend/app/Http/Resources/Api/V1/ExamResource.php`, inside the returned array, add a line right after `'status' => $this->status,`:

```php
            'is_practice' => $this->is_practice,
```

- [ ] **Step 2: Write the failing test**

Add to `backend/tests/Feature/ExamControllerTest.php`, inside the `ExamControllerTest` class:

```php
    public function test_index_never_returns_a_practice_exam_for_any_actor(): void
    {
        $practiceExam = Exam::create($this->examPayload(['name' => 'Practice Session']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
            'is_practice' => true,
        ]);

        $published = Exam::create($this->examPayload(['name' => 'Real Final']) + [
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'status' => 'published',
        ]);

        $index = $this->getJson('/api/v1/exams')->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $published->id);

        $manager = $this->actorWithPermissions(['exams.view'], $this->versionA->school_id, $this->versionA->district_id);
        $managerIndex = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/exams')->assertOk();
        $managerIndex->assertJsonMissing(['id' => $practiceExam->id]);
    }
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=test_index_never_returns_a_practice_exam_for_any_actor`
Expected: FAIL — `assertJsonCount(1, 'data')` sees 2, and/or `assertJsonMissing` finds the practice exam's id.

- [ ] **Step 4: Add the unconditional filter to `ExamController::index()`**

In `backend/app/Http/Controllers/Api/V1/ExamController.php`, in `index()`, find the `Exam::query()->with(self::INDEX_WITH)->when(...)` chain and add, right after `->with(self::INDEX_WITH)`:

```php
            ->where('is_practice', false)
```

(This line must come before the existing `->when(...)` calls and apply unconditionally — both the privileged and non-privileged branches share this one query, so placing it before the `->when(! $actor?->hasPermission(...))` branch already covers both.)

- [ ] **Step 5: Run the test again**

Run: `php artisan test --filter=test_index_never_returns_a_practice_exam_for_any_actor`
Expected: PASS

- [ ] **Step 6: Add `is_practice` to the frontend `Exam` type**

In `frontend/packages/api-client/src/index.ts`, find `export interface Exam {` and add a line right after `status: string`:

```ts
  is_practice: boolean
```

- [ ] **Step 7: Typecheck the student app**

Run: `cd frontend && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean)

- [ ] **Step 8: Run Pint and the full backend suite**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: Pint passes; all tests pass

- [ ] **Step 9: Commit**

```bash
git add backend/app/Http/Resources/Api/V1/ExamResource.php backend/app/Http/Controllers/Api/V1/ExamController.php backend/tests/Feature/ExamControllerTest.php frontend/packages/api-client/src/index.ts
git commit -m "Expose is_practice on ExamResource; exclude practice exams from the exam listing"
```

---

## Task 3: Restrict guest exam access to practice exams

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/GuestExamAttemptController.php` (`randomDraw()`, `submit()`)
- Modify: `backend/tests/Feature/GuestExamAttemptControllerTest.php` (fixture helpers + new test)

**Interfaces:**
- Consumes: `exams.is_practice` from Task 1.
- Produces: `POST /api/v1/exams/{exam}/random-draw` and `POST /api/v1/exams/{exam}/guest-submissions` both return 403 for any exam where `is_practice` is `false`, regardless of its `status`/availability.

- [ ] **Step 1: Update the existing test fixtures to produce practice exams**

In `backend/tests/Feature/GuestExamAttemptControllerTest.php`, every existing test in this file exercises the guest draw/submission endpoints — after this task's change, that only works for practice exams. Update both helper methods to mark their exams as practice exams, so none of the 11 existing tests in this file change behavior:

In `publishedManualExam()`, add `'is_practice' => true,` to the `Exam::create([...])` array (anywhere inside it, e.g. right after `'status' => 'published',`).

In `publishedRandomExam()`, add `'is_practice' => true,` to its `Exam::create([...])` array the same way.

- [ ] **Step 2: Write the new failing test proving a non-practice exam is blocked**

Add to the same file, inside the class:

```php
    public function test_random_draw_and_guest_submission_are_blocked_for_a_non_practice_exam(): void
    {
        $question = $this->makeQuestion(correctIsOptionA: true);
        $exam = $this->publishedRandomExam(1);
        $exam->update(['is_practice' => false]);

        $this->postJson("/api/v1/exams/{$exam->id}/random-draw")->assertForbidden();

        $this->postJson("/api/v1/exams/{$exam->id}/guest-submissions", [
            'answers' => [['question_id' => $question->id, 'selected_option_ids' => []]],
        ])->assertForbidden();

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('exam_attempt_questions', 0);
        $this->assertDatabaseCount('exam_attempt_answers', 0);
    }
```

- [ ] **Step 3: Run the full file to verify the new test fails and nothing else broke yet**

Run: `php artisan test --filter=GuestExamAttemptControllerTest`
Expected: the 11 pre-existing tests still PASS (fixtures now produce practice exams, behavior unchanged); the new test FAILS (both calls currently succeed instead of returning 403).

- [ ] **Step 4: Add the `is_practice` gate**

In `backend/app/Http/Controllers/Api/V1/GuestExamAttemptController.php`, in `randomDraw()`, change:

```php
        abort_unless($exam->isPubliclyTakeable(), 404);
```

to:

```php
        abort_unless($exam->isPubliclyTakeable(), 404);
        abort_unless($exam->is_practice, 403);
```

Do the identical change in `submit()` (same two lines, same order).

- [ ] **Step 5: Run the full file again**

Run: `php artisan test --filter=GuestExamAttemptControllerTest`
Expected: PASS — all 12 tests (11 pre-existing + the new one).

- [ ] **Step 6: Run Pint and the full backend suite**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: Pint passes; all tests pass.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/GuestExamAttemptController.php backend/tests/Feature/GuestExamAttemptControllerTest.php
git commit -m "Restrict guest exam draw/submission to practice exams only"
```

---

## Task 4: `PracticeExamController` — provision a practice exam from a chapter selection

**Files:**
- Create: `backend/config/practice_exams.php`
- Create: `backend/app/Http/Requests/Api/V1/StorePracticeExamRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/PracticeExamController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/PracticeExamControllerTest.php` (new file)

**Interfaces:**
- Consumes: `Exam`, `Chapter`, `AcademicYear` models; `StudentProfile` relation chain `$user->profile?->section?->shift` (giving `class_version_id`/`academic_year_id`; `Section`/`Shift` also carry `school_id`/`district_id` directly, confirmed in `ExamAttemptControllerTest`'s fixtures).
- Produces:
  - `GET /api/v1/practice-exams/config` → `{ "single_chapter_question_count": int, "multi_chapter_question_count": int, "tier_pass_percent": int, "tier_good_percent": int, "tier_best_percent": int }`
  - `POST /api/v1/practice-exams` with body `{ "subject_id": int, "chapter_ids": int[] }` → `201 { "data": { "id": int } }` where `id` is a newly created, hidden `Exam`'s id, immediately usable at the existing `GET/POST /api/v1/exams/{id}/...` endpoints.

- [ ] **Step 1: Create `config/practice_exams.php`**

```php
<?php

return [
    /*
     * How many random questions a practice exam draws when exactly one
     * chapter was selected.
     */
    'single_chapter_question_count' => (int) env('PRACTICE_EXAM_SINGLE_CHAPTER_QUESTIONS', 20),

    /*
     * How many random questions a practice exam draws when two or more
     * chapters were selected.
     */
    'multi_chapter_question_count' => (int) env('PRACTICE_EXAM_MULTI_CHAPTER_QUESTIONS', 40),

    /*
     * Score-percentage cutoffs for the four result tiers: below
     * tier_pass_percent is "failed"; below tier_good_percent is "pass";
     * below tier_best_percent is "good"; at or above tier_best_percent
     * is "best". Computed client-side from score/total, but the cutoffs
     * themselves live here so they're configured in one place.
     */
    'tier_pass_percent' => (int) env('PRACTICE_EXAM_TIER_PASS_PERCENT', 40),
    'tier_good_percent' => (int) env('PRACTICE_EXAM_TIER_GOOD_PERCENT', 60),
    'tier_best_percent' => (int) env('PRACTICE_EXAM_TIER_BEST_PERCENT', 80),
];
```

- [ ] **Step 2: Create `StorePracticeExamRequest`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePracticeExamRequest extends FormRequest
{
    /**
     * Public — a guest must be able to provision a practice exam, same
     * as the public /exams/{exam}/random-draw and /guest-submissions
     * routes.
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
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'chapter_ids' => ['required', 'array', 'min:1'],
            'chapter_ids.*' => ['integer', Rule::exists('chapters', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Every chapter must belong to a book with the submitted subject_id
     * — unlike SetExamRandomChaptersRequest's equivalent check, there is
     * deliberately no class_version_id constraint here: a practice
     * exam's chapters are pooled across every school's version of the
     * student's chosen class.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ids = $this->input('chapter_ids', []);

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Chapter::query()
                ->whereIn('id', $ids)
                ->whereHas('book', fn ($query) => $query->where('subject_id', $this->integer('subject_id')))
                ->count();

            if ($matching !== count(array_unique($ids))) {
                $validator->errors()->add('chapter_ids', 'One or more chapters do not belong to a book under the submitted subject.');
            }
        });
    }
}
```

- [ ] **Step 3: Create `PracticeExamController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePracticeExamRequest;
use App\Models\AcademicYear;
use App\Models\Chapter;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PracticeExamController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'single_chapter_question_count' => config('practice_exams.single_chapter_question_count'),
            'multi_chapter_question_count' => config('practice_exams.multi_chapter_question_count'),
            'tier_pass_percent' => config('practice_exams.tier_pass_percent'),
            'tier_good_percent' => config('practice_exams.tier_good_percent'),
            'tier_best_percent' => config('practice_exams.tier_best_percent'),
        ]);
    }

    /**
     * Creates a hidden, student-driven Exam (is_practice = true) scoped
     * via exam_random_chapters to exactly the submitted chapters. No
     * find-or-create/dedup — every call creates a fresh row (accepted
     * tradeoff, see the design spec).
     *
     * This route sits outside the auth:sanctum group (it must also serve
     * guests), so the acting user is resolved explicitly from the
     * sanctum guard rather than via $request->user().
     */
    public function store(StorePracticeExamRequest $request): JsonResponse
    {
        $chapterIds = $request->validated('chapter_ids');
        $subjectId = $request->validated('subject_id');

        $questionCount = count($chapterIds) > 1
            ? config('practice_exams.multi_chapter_question_count')
            : config('practice_exams.single_chapter_question_count');

        [$classVersionId, $academicYearId, $schoolId, $districtId] = $this->resolveOwnerContext($request, $chapterIds);

        $exam = Exam::create([
            'class_version_id' => $classVersionId,
            'subject_id' => $subjectId,
            'academic_year_id' => $academicYearId,
            'school_id' => $schoolId,
            'district_id' => $districtId,
            'name' => 'Practice #'.Str::random(8),
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => $questionCount,
            'marks_per_question' => 1,
            'passing_score_percent' => config('practice_exams.tier_pass_percent'),
            'status' => 'published',
            'is_practice' => true,
        ]);

        $exam->randomChapters()->attach($chapterIds);

        return response()->json(['data' => ['id' => $exam->id]], 201);
    }

    /**
     * [class_version_id, academic_year_id, school_id, district_id].
     *
     * A logged-in student's own enrollment is used when available — this
     * is what lets ExamAttemptController::start()'s enrollment check
     * (comparing the attempting student's own class_version_id/
     * academic_year_id against the exam's) succeed for the exact
     * student who requested this practice exam. A guest — or an
     * authenticated actor with no usable student profile/section/shift,
     * mirroring the frontend's existing "403 falls through to guest"
     * pattern in ExamRunnerPage.tsx — falls back to the first selected
     * chapter's own book's class_version/school/district and the
     * current academic year; this is cosmetic/reporting-only for a
     * guest, since guests never reach that enrollment check at all.
     *
     * @param  list<int>  $chapterIds
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function resolveOwnerContext(Request $request, array $chapterIds): array
    {
        $actor = $request->user('sanctum');
        $section = $actor?->profile?->section;
        $shift = $section?->shift;

        if ($shift !== null) {
            return [$shift->class_version_id, $shift->academic_year_id, $section->school_id, $section->district_id];
        }

        // Book carries no school_id/district_id of its own (dropped in
        // migration 2026_11_18_100100_drop_tenant_columns_from_books_table —
        // only class_version_id/subject_id remain), so those two come
        // from the book's own ClassVersion instead.
        $firstChapter = Chapter::with('book.classVersion')->find($chapterIds[0]);
        $classVersion = $firstChapter->book->classVersion;
        $academicYearId = AcademicYear::where('is_current', true)->first()?->id;

        return [$classVersion->id, $academicYearId, $classVersion->school_id, $classVersion->district_id];
    }
}
```

- [ ] **Step 4: Add the routes**

In `backend/routes/api.php`, add the `PracticeExamController` import alongside the other `use App\Http\Controllers\Api\V1\...` lines (alphabetical position, after `PermissionController`):

```php
use App\Http\Controllers\Api\V1\PracticeExamController;
```

Then, in the public routes block (near the other public exam routes — `Route::apiResource('exams', ...)`, `/exams/{exam}/random-draw`, `/exams/{exam}/guest-submissions`), add:

```php
    Route::get('/practice-exams/config', [PracticeExamController::class, 'config']);
    Route::post('/practice-exams', [PracticeExamController::class, 'store']);
```

- [ ] **Step 5: Write the test file**

Create `backend/tests/Feature/PracticeExamControllerTest.php`:

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
```

- [ ] **Step 6: Run the new test file**

Run: `php artisan test --filter=PracticeExamControllerTest`
Expected: PASS (all 6 tests)

- [ ] **Step 7: Run Pint and the full backend suite**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: Pint passes; all tests pass.

- [ ] **Step 8: Commit**

```bash
git add backend/config/practice_exams.php backend/app/Http/Requests/Api/V1/StorePracticeExamRequest.php backend/app/Http/Controllers/Api/V1/PracticeExamController.php backend/routes/api.php backend/tests/Feature/PracticeExamControllerTest.php
git commit -m "Add PracticeExamController: provision a hidden practice exam from a chapter selection"
```

---

## Task 5: `resolveContent.ts` — extract `chaptersForClassAndSubject()`

**Files:**
- Modify: `frontend/apps/student/src/lib/resolveContent.ts`

**Interfaces:**
- Produces: `export async function chaptersForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Array<{ chapter: Chapter; book: Book }>>`
- Consumes by: Task 8 (`ExamsPage.tsx`'s chapter picker). `chaptersWithVideosForClassAndSubject()` (existing) now calls this internally.

- [ ] **Step 1: Read the current file**

Read `frontend/apps/student/src/lib/resolveContent.ts` in full (already quoted in this plan's research — reproduced here for reference, no action needed beyond confirming it still matches):

```ts
export interface ChapterWithVideoCount {
  chapter: Chapter
  book: Book
  videoCount: number
}

export async function chaptersWithVideosForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithVideoCount[]> {
  const books = await booksForClassAndSubject(classId, subjectId)

  const chapterLists = await Promise.all(
    books.map(async (book) => {
      const chaptersResult = await listChapters({ bookId: book.id })
      return chaptersResult.data.map((chapter) => ({ chapter, book }))
    }),
  )

  const chaptersWithBooks = chapterLists.flat()

  const withCounts = await Promise.all(
    chaptersWithBooks.map(async ({ chapter, book }) => {
      const videosResult = await listChapterVideos({ chapterId: chapter.id })
      return { chapter, book, videoCount: videosResult.data.length }
    }),
  )

  return withCounts.filter((entry) => entry.videoCount > 0)
}
```

- [ ] **Step 2: Extract the merge into `chaptersForClassAndSubject()`**

Replace the block above with:

```ts
export interface ChapterWithBook {
  chapter: Chapter
  book: Book
}

// One level deeper than `booksForClassAndSubject`: every chapter under
// every cross-school book for this class/subject, paired with its own
// book. No video filtering here — `chaptersWithVideosForClassAndSubject`
// (below) adds that on top; the practice-exam chapter picker needs every
// chapter regardless of whether it has a video.
export async function chaptersForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithBook[]> {
  const books = await booksForClassAndSubject(classId, subjectId)

  const chapterLists = await Promise.all(
    books.map(async (book) => {
      const chaptersResult = await listChapters({ bookId: book.id })
      return chaptersResult.data.map((chapter) => ({ chapter, book }))
    }),
  )

  return chapterLists.flat()
}

export interface ChapterWithVideoCount {
  chapter: Chapter
  book: Book
  videoCount: number
}

// One level deeper than `chaptersForClassAndSubject`: for every chapter,
// check for at least one (enabled) video. This is an N+1 from the
// frontend's perspective (chapters -> per-chapter video lookup), on top
// of `chaptersForClassAndSubject`'s own already-accepted N+1.
export async function chaptersWithVideosForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithVideoCount[]> {
  const chaptersWithBooks = await chaptersForClassAndSubject(classId, subjectId)

  const withCounts = await Promise.all(
    chaptersWithBooks.map(async ({ chapter, book }) => {
      const videosResult = await listChapterVideos({ chapterId: chapter.id })
      return { chapter, book, videoCount: videosResult.data.length }
    }),
  )

  return withCounts.filter((entry) => entry.videoCount > 0)
}
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean)

- [ ] **Step 4: Commit**

```bash
git add frontend/apps/student/src/lib/resolveContent.ts
git commit -m "Extract chaptersForClassAndSubject() from chaptersWithVideosForClassAndSubject()"
```

---

## Task 6: `api-client` — practice exam config and creation

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`
- Test: `frontend/packages/api-client/src/index.test.ts`

**Interfaces:**
- Produces:
  - `interface PracticeExamConfig { single_chapter_question_count: number; multi_chapter_question_count: number; tier_pass_percent: number; tier_good_percent: number; tier_best_percent: number }`
  - `function getPracticeExamConfig(): Promise<PracticeExamConfig>`
  - `function createPracticeExam(payload: { subjectId: number; chapterIds: number[] }, token?: string): Promise<{ data: { id: number } }>`
- Consumed by: Task 8 (`ExamsPage.tsx`), Task 9 (`ExamRunnerPage.tsx`).

- [ ] **Step 1: Add the types and functions**

In `frontend/packages/api-client/src/index.ts`, near the existing `listExams`/`getExam` functions (after `export function getExam(id: number): Promise<{ data: Exam }> { ... }`), add:

```ts
export interface PracticeExamConfig {
  single_chapter_question_count: number
  multi_chapter_question_count: number
  tier_pass_percent: number
  tier_good_percent: number
  tier_best_percent: number
}

export function getPracticeExamConfig(): Promise<PracticeExamConfig> {
  return apiFetch<PracticeExamConfig>('/practice-exams/config')
}

export function createPracticeExam(
  payload: { subjectId: number; chapterIds: number[] },
  token?: string,
): Promise<{ data: { id: number } }> {
  return apiFetch<{ data: { id: number } }>('/practice-exams', {
    method: 'POST',
    headers: token ? authHeaders(token) : { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ subject_id: payload.subjectId, chapter_ids: payload.chapterIds }),
  })
}
```

- [ ] **Step 2: Write the test cases**

In `frontend/packages/api-client/src/index.test.ts`, add `getPracticeExamConfig` and `createPracticeExam` to the import list (alphabetical position — after `listUpazilas,`/`logout,` and before `managementLogin,`, matching the file's existing sort order), then add a new `describe` block right after the existing `logout` describe block:

```ts
describe('practice exams', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('fetches the practice exam config', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          single_chapter_question_count: 20,
          multi_chapter_question_count: 40,
          tier_pass_percent: 40,
          tier_good_percent: 60,
          tier_best_percent: 80,
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getPracticeExamConfig()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/practice-exams/config`)
    expect(result.single_chapter_question_count).toBe(20)
  })

  it('creates a practice exam without a token for a guest', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 7 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await createPracticeExam({ subjectId: 3, chapterIds: [1, 2] })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/practice-exams`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ subject_id: 3, chapter_ids: [1, 2] }),
    })
    expect((mockFetch.mock.calls[0][1] as RequestInit).headers).not.toHaveProperty('Authorization')
    expect(result.data.id).toBe(7)
  })

  it('creates a practice exam with a bearer token when logged in', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 8 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createPracticeExam({ subjectId: 3, chapterIds: [1] }, 'secret-token')

    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})
```

- [ ] **Step 3: Run the test suite**

Run: `cd frontend && npx vitest run packages/api-client`
Expected: PASS, test count increased by 3

- [ ] **Step 4: Typecheck**

Run: `npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean)

- [ ] **Step 5: Commit**

```bash
git add frontend/packages/api-client/src/index.ts frontend/packages/api-client/src/index.test.ts
git commit -m "Add getPracticeExamConfig() and createPracticeExam() to api-client"
```

---

## Task 7: i18n keys for the practice-exam picker, tiers, and certificate

**Files:**
- Modify: `frontend/packages/i18n/src/common/bn.json`
- Modify: `frontend/packages/i18n/src/common/en.json`

**Interfaces:**
- Produces: the keys listed below, in both files. Consumed by Tasks 8–10.

- [ ] **Step 1: Add new keys to `bn.json`**

In `frontend/packages/i18n/src/common/bn.json`, right after the existing `"student.exams.yourAnswer": "তোমার উত্তর",` line, add:

```json
  "student.exams.loginRequired": "এই পরীক্ষাটি শুধুমাত্র লগইন করা শিক্ষার্থীদের জন্য। অনুগ্রহ করে লগইন করো।",
  "student.exams.tierFailed": "আরও অনুশীলন দরকার",
  "student.exams.tierPass": "উত্তীর্ণ",
  "student.exams.tierGood": "ভালো করেছো",
  "student.exams.tierBest": "চমৎকার!",
  "student.exams.viewCertificate": "সনদপত্র দেখো",
  "student.practiceExam.title": "অনুশীলন পরীক্ষা",
  "student.practiceExam.pickClass": "শ্রেণি নির্বাচন করো",
  "student.practiceExam.pickSubject": "বিষয় নির্বাচন করো",
  "student.practiceExam.allClasses": "শ্রেণি নির্বাচন করো",
  "student.practiceExam.allSubjects": "বিষয় নির্বাচন করো",
  "student.practiceExam.chaptersHeading": "অধ্যায় নির্বাচন করো",
  "student.practiceExam.noChapters": "এই বিষয়ে কোনো অধ্যায় পাওয়া যায়নি।",
  "student.practiceExam.questionHintSingle": "১টি অধ্যায় নির্বাচন করলে পরীক্ষায় থাকবে {count}টি প্রশ্ন।",
  "student.practiceExam.questionHintMulti": "একাধিক অধ্যায় নির্বাচন করলে পরীক্ষায় থাকবে {count}টি প্রশ্ন।",
  "student.practiceExam.submit": "পরীক্ষা শুরু করো",
  "student.practiceExam.submitting": "তৈরি হচ্ছে...",
  "student.practiceExam.loadError": "এই মুহূর্তে লোড করা যায়নি। আবার চেষ্টা করো।",
  "student.certificate.title": "সনদপত্র",
  "student.certificate.guestName": "অতিথি শিক্ষার্থী",
  "student.certificate.achieved": "অর্জন করেছে",
  "student.certificate.scoreLine": "স্কোর: {score} / {total}",
  "student.certificate.print": "প্রিন্ট করো",
  "student.certificate.missing": "এই সনদপত্রের তথ্য পাওয়া যায়নি।",
  "student.certificate.backToExams": "পরীক্ষায় ফিরে যাও",
```

- [ ] **Step 2: Add the matching English keys to `en.json`**

In `frontend/packages/i18n/src/common/en.json`, right after the existing `"student.exams.yourAnswer": "Your answer",` line, add:

```json
  "student.exams.loginRequired": "This exam is only for logged-in students. Please log in.",
  "student.exams.tierFailed": "Needs more practice",
  "student.exams.tierPass": "Passed",
  "student.exams.tierGood": "Good job",
  "student.exams.tierBest": "Excellent!",
  "student.exams.viewCertificate": "View certificate",
  "student.practiceExam.title": "Practice Exam",
  "student.practiceExam.pickClass": "Select a class",
  "student.practiceExam.pickSubject": "Select a subject",
  "student.practiceExam.allClasses": "Select a class",
  "student.practiceExam.allSubjects": "Select a subject",
  "student.practiceExam.chaptersHeading": "Select chapters",
  "student.practiceExam.noChapters": "No chapters found for this subject.",
  "student.practiceExam.questionHintSingle": "Picking 1 chapter gives you {count} questions.",
  "student.practiceExam.questionHintMulti": "Picking multiple chapters gives you {count} questions.",
  "student.practiceExam.submit": "Start Exam",
  "student.practiceExam.submitting": "Preparing...",
  "student.practiceExam.loadError": "Could not load right now. Please try again.",
  "student.certificate.title": "Certificate",
  "student.certificate.guestName": "Guest Student",
  "student.certificate.achieved": "has achieved",
  "student.certificate.scoreLine": "Score: {score} / {total}",
  "student.certificate.print": "Print",
  "student.certificate.missing": "This certificate's details could not be found.",
  "student.certificate.backToExams": "Back to Exams",
```

- [ ] **Step 3: Verify both JSON files still parse**

Run: `cd frontend && node -e "require('./packages/i18n/src/common/bn.json'); require('./packages/i18n/src/common/en.json'); console.log('ok')"`
Expected: `ok`

- [ ] **Step 4: Run the i18n package's own test**

Run: `npx vitest run packages/i18n`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/packages/i18n/src/common/bn.json frontend/packages/i18n/src/common/en.json
git commit -m "Add i18n keys for the practice-exam picker, result tiers, and certificate"
```

---

## Task 8: `ExamsPage.tsx` — class → subject → chapter-checkbox picker

**Files:**
- Modify: `frontend/apps/student/src/pages/ExamsPage.tsx` (full rewrite)

**Interfaces:**
- Consumes: `listClasses` (existing, same as `BooksPage.tsx`), `subjectsForClass` (existing, from `resolveContent.ts`), `chaptersForClassAndSubject` (Task 5), `getPracticeExamConfig`/`createPracticeExam` (Task 6), `type ChapterWithBook` (Task 5).
- Produces: on submit, navigates to `/exams/:id` with the id returned by `createPracticeExam()`.

- [ ] **Step 1: Replace the file**

Replace the full contents of `frontend/apps/student/src/pages/ExamsPage.tsx` with:

```tsx
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ApiError,
  createPracticeExam,
  getPracticeExamConfig,
  listClasses,
  type PracticeExamConfig,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { chaptersForClassAndSubject, subjectsForClass, type ChapterWithBook } from '../lib/resolveContent'

const t = getCommonStrings('bn')

// Same pattern as BooksPage.tsx/VideoLecturePage.tsx — see the comment
// there for why a translated `fallback` is used instead of the raw
// `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

function chapterLabel(entry: ChapterWithBook): string {
  const chapterName = entry.chapter.bn_name ?? entry.chapter.name
  const bookName = entry.book.bn_name ?? entry.book.name
  return `${chapterName} — ${bookName}`
}

export default function ExamsPage() {
  const { token } = useAuth()
  const navigate = useNavigate()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classId, setClassId] = useState<number | null>(null)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(null)

  const [chapters, setChapters] = useState<ChapterWithBook[]>([])
  const [checkedChapterIds, setCheckedChapterIds] = useState<Set<number>>(new Set())
  const [chaptersLoading, setChaptersLoading] = useState(false)

  const [config, setConfig] = useState<PracticeExamConfig | null>(null)

  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    getPracticeExamConfig()
      .then(setConfig)
      .catch(() => setConfig(null))
  }, [])

  useEffect(() => {
    setSubjectId(null)

    let active = true

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    setCheckedChapterIds(new Set())

    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    setChaptersLoading(true)

    chaptersForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setChapters(result)
      })
      .catch(() => {
        if (active) setChapters([])
      })
      .finally(() => {
        if (active) setChaptersLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  function toggleChapter(chapterId: number, checked: boolean) {
    setCheckedChapterIds((previous) => {
      const next = new Set(previous)
      if (checked) {
        next.add(chapterId)
      } else {
        next.delete(chapterId)
      }
      return next
    })
  }

  function questionHint(): string | null {
    if (!config || checkedChapterIds.size === 0) return null

    const template =
      checkedChapterIds.size === 1
        ? t['student.practiceExam.questionHintSingle']
        : t['student.practiceExam.questionHintMulti']
    const count =
      checkedChapterIds.size === 1 ? config.single_chapter_question_count : config.multi_chapter_question_count

    return template.replace('{count}', String(count))
  }

  async function handleSubmit() {
    if (!subjectId || checkedChapterIds.size === 0) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await createPracticeExam(
        { subjectId, chapterIds: [...checkedChapterIds] },
        token ?? undefined,
      )
      navigate(`/exams/${result.data.id}`)
    } catch (caught) {
      setError(messageFromApiError(caught, t['student.practiceExam.loadError']))
      setSubmitting(false)
    }
  }

  const hint = questionHint()

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.practiceExam.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.practiceExam.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.bn_name ?? schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={!classId}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.practiceExam.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.bn_name ?? subject.name}
            </option>
          ))}
        </select>
      </div>

      {!classId && <p className="mt-6 text-sm text-primary-600">{t['student.practiceExam.pickClass']}</p>}

      {classId && !subjectId && <p className="mt-6 text-sm text-primary-600">{t['student.practiceExam.pickSubject']}</p>}

      {classId && subjectId && (
        <>
          <h2 className="mt-6 text-lg font-semibold text-primary-800">{t['student.practiceExam.chaptersHeading']}</h2>

          {chaptersLoading && <p className="mt-2 text-sm text-primary-600">{t['student.exams.loading']}</p>}

          {!chaptersLoading && chapters.length === 0 && (
            <p className="mt-2 text-sm text-primary-600">{t['student.practiceExam.noChapters']}</p>
          )}

          <div className="mt-3 flex flex-col gap-2">
            {chapters.map((entry) => (
              <label
                key={entry.chapter.id}
                className="flex items-center gap-3 rounded-md border border-primary-100 px-4 py-3 text-sm text-primary-800"
              >
                <input
                  type="checkbox"
                  className="h-4 w-4"
                  checked={checkedChapterIds.has(entry.chapter.id)}
                  onChange={(event) => toggleChapter(entry.chapter.id, event.target.checked)}
                />
                {chapterLabel(entry)}
              </label>
            ))}
          </div>

          {hint && <p className="mt-4 text-sm font-medium text-primary-700">{hint}</p>}

          {error && <p className="mt-4 text-sm text-danger-600">{error}</p>}

          <button
            type="button"
            onClick={handleSubmit}
            disabled={checkedChapterIds.size === 0 || submitting}
            className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
          >
            {submitting ? t['student.practiceExam.submitting'] : t['student.practiceExam.submit']}
          </button>
        </>
      )}
    </section>
  )
}
```

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean). If `listClasses`/`SchoolClass` or `Subject` import paths differ from what's shown, fix the import to match whatever `BooksPage.tsx` currently imports them as (read that file's import block if this step reports a missing-export error).

- [ ] **Step 3: Commit**

```bash
git add frontend/apps/student/src/pages/ExamsPage.tsx
git commit -m "Rewrite ExamsPage as a class/subject/chapter-checkbox practice exam picker"
```

---

## Task 9: `ExamRunnerPage.tsx` — login-required message, tiered result, certificate button

**Files:**
- Modify: `frontend/apps/student/src/pages/ExamRunnerPage.tsx`

**Interfaces:**
- Consumes: `getPracticeExamConfig` (Task 6), `examPreview.is_practice` (Task 2's frontend type change).
- Produces: navigates to `/exams/:id/certificate` with `state: { score, total, percent, tier, examId, date }` (certificate page, Task 10, reads this).

- [ ] **Step 1: Add the config fetch and tier helper**

In `frontend/apps/student/src/pages/ExamRunnerPage.tsx`, add `getPracticeExamConfig` and `type PracticeExamConfig` to the existing `@ttla/api-client` import list, and add `Link` is already imported from `react-router-dom` — also add `useNavigate` there.

Add this function near the top of the file, after the existing `questionFromExamQuestionLink` function and before `interface LoadResult`:

```tsx
type ResultTier = 'failed' | 'pass' | 'good' | 'best'

function tierFor(percent: number, config: PracticeExamConfig): ResultTier {
  if (percent < config.tier_pass_percent) return 'failed'
  if (percent < config.tier_good_percent) return 'pass'
  if (percent < config.tier_best_percent) return 'good'
  return 'best'
}

const TIER_LABEL_KEY: Record<ResultTier, string> = {
  failed: 'student.exams.tierFailed',
  pass: 'student.exams.tierPass',
  good: 'student.exams.tierGood',
  best: 'student.exams.tierBest',
}

const TIER_TONE: Record<ResultTier, string> = {
  failed: 'text-danger-600',
  pass: 'text-success-600',
  good: 'text-success-600',
  best: 'text-success-600',
}
```

- [ ] **Step 2: Add `config` state, fetch it on mount, and the login-required check**

In the `ExamRunnerPage` component, add `useNavigate` to the destructured import and add this state alongside the other `useState` declarations (right after `const [examPreview, setExamPreview] = useState<Exam | null>(null)`):

```tsx
  const [config, setConfig] = useState<PracticeExamConfig | null>(null)
  const navigate = useNavigate()
```

In the existing `useEffect` that resets state and fetches `getExam(examId)` on mount/examId change, add a config fetch alongside it (the effect already has a `let active = true` guard and an `if (active) ...` pattern — add this right after the `getExam(examId).then(...).catch(...)` call, before the `return () => { active = false }` line):

```tsx
    getPracticeExamConfig()
      .then((result) => {
        if (active) setConfig(result)
      })
      .catch(() => {
        if (active) setConfig(null)
      })
```

- [ ] **Step 3: Add the login-required guard to the "setup" screen**

In the `status === 'setup'` render branch, the current structure is:

```tsx
        ) : !examPreview ? (
          <p className="text-sm text-primary-600">{t['student.exams.loading']}</p>
        ) : (
          <>
            <h1 className="text-2xl font-bold text-primary-800">{examPreview.bn_name ?? examPreview.name}</h1>

            {setupError && <p className="mt-4 text-sm text-danger-600">{setupError}</p>}

            <button
              type="button"
              onClick={handleStart}
              disabled={starting}
              className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
            >
              {starting ? t['student.exams.loading'] : t['student.exams.start']}
            </button>
          </>
        )}
```

Change it to show a login-required message instead of the Start button when there's no token and the exam isn't a practice exam:

```tsx
        ) : !examPreview ? (
          <p className="text-sm text-primary-600">{t['student.exams.loading']}</p>
        ) : !token && !examPreview.is_practice ? (
          <>
            <h1 className="text-2xl font-bold text-primary-800">{examPreview.bn_name ?? examPreview.name}</h1>
            <p className="mt-4 text-sm text-danger-600">{t['student.exams.loginRequired']}</p>
            <Link to="/login" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
              {t['student.nav.signIn']}
            </Link>
          </>
        ) : (
          <>
            <h1 className="text-2xl font-bold text-primary-800">{examPreview.bn_name ?? examPreview.name}</h1>

            {setupError && <p className="mt-4 text-sm text-danger-600">{setupError}</p>}

            <button
              type="button"
              onClick={handleStart}
              disabled={starting}
              className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
            >
              {starting ? t['student.exams.loading'] : t['student.exams.start']}
            </button>
          </>
        )}
```

- [ ] **Step 4: Add the tiered result + certificate button to the "submitted" screen**

The current "submitted" branch's result card is:

```tsx
          <div className="rounded-lg border border-primary-100 p-4">
            <p className="text-lg font-semibold text-primary-800">
              {t['student.exams.score']}: {result.score ?? 0} / {result.totalPossibleMarks ?? 0}
            </p>
            {result.passed !== null && (
              <p className={`mt-1 text-sm font-medium ${result.passed ? 'text-success-600' : 'text-danger-600'}`}>
                {result.passed ? t['student.exams.passed'] : t['student.exams.failed']}
              </p>
            )}
          </div>
```

Replace it with a tier-aware version that also offers the certificate button:

```tsx
          <div className="rounded-lg border border-primary-100 p-4">
            <p className="text-lg font-semibold text-primary-800">
              {t['student.exams.score']}: {result.score ?? 0} / {result.totalPossibleMarks ?? 0}
            </p>
            {config && result.totalPossibleMarks !== null && result.totalPossibleMarks > 0 && (() => {
              const percent = ((result.score ?? 0) / result.totalPossibleMarks) * 100
              const tier = tierFor(percent, config)

              return (
                <>
                  <p className={`mt-1 text-sm font-medium ${TIER_TONE[tier]}`}>{t[TIER_LABEL_KEY[tier]]}</p>
                  {tier !== 'failed' && (
                    <button
                      type="button"
                      onClick={() =>
                        navigate(`/exams/${examId}/certificate`, {
                          state: {
                            score: result.score ?? 0,
                            total: result.totalPossibleMarks,
                            percent,
                            tier,
                            date: new Date().toISOString(),
                          },
                        })
                      }
                      className="mt-3 cursor-pointer rounded-md border border-primary-300 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
                    >
                      {t['student.exams.viewCertificate']}
                    </button>
                  )}
                </>
              )
            })()}
          </div>
```

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean)

- [ ] **Step 6: Commit**

```bash
git add frontend/apps/student/src/pages/ExamRunnerPage.tsx
git commit -m "Add login-required message, tiered result, and certificate button to ExamRunnerPage"
```

---

## Task 10: `CertificatePage.tsx` and its route

**Files:**
- Create: `frontend/apps/student/src/pages/CertificatePage.tsx`
- Modify: `frontend/apps/student/src/App.tsx`

**Interfaces:**
- Consumes: `location.state` shape produced by Task 9 (`{ score, total, percent, tier, date }`), plus the route's own `:id` param for display/print only.

- [ ] **Step 1: Create the page**

```tsx
import { Link, useLocation, useParams } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

interface CertificateState {
  score: number
  total: number
  percent: number
  tier: 'pass' | 'good' | 'best'
  date: string
}

const TIER_HEADING_KEY: Record<CertificateState['tier'], string> = {
  pass: 'student.exams.tierPass',
  good: 'student.exams.tierGood',
  best: 'student.exams.tierBest',
}

function isCertificateState(value: unknown): value is CertificateState {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Partial<CertificateState>
  return (
    typeof candidate.score === 'number' &&
    typeof candidate.total === 'number' &&
    typeof candidate.percent === 'number' &&
    typeof candidate.tier === 'string' &&
    typeof candidate.date === 'string'
  )
}

export default function CertificatePage() {
  const { id } = useParams<{ id: string }>()
  const location = useLocation()
  const { token } = useAuth()

  const state = isCertificateState(location.state) ? location.state : null

  if (!state) {
    return (
      <section className="mx-auto max-w-xl px-4 py-12 text-center">
        <p className="text-sm text-primary-600">{t['student.certificate.missing']}</p>
        <Link to="/exams" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
          {t['student.certificate.backToExams']}
        </Link>
      </section>
    )
  }

  const studentName = token ? null : t['student.certificate.guestName']
  const formattedDate = new Date(state.date).toLocaleDateString('bn-BD', { year: 'numeric', month: 'long', day: 'numeric' })

  return (
    <section className="mx-auto max-w-2xl px-4 py-12">
      <div className="rounded-2xl border-4 border-double border-primary-500 bg-white p-10 text-center shadow-lg print:border-2 print:shadow-none">
        <img src="/logo.webp" alt="" aria-hidden="true" className="mx-auto h-14 w-14 object-contain" />
        <h1 className="mt-4 text-2xl font-bold font-display text-primary-800">{t['student.certificate.title']}</h1>

        <p className="mt-6 text-lg font-semibold text-primary-800">{studentName ?? t['student.certificate.guestName']}</p>

        <p className="mt-2 text-base text-primary-700">
          {t['student.certificate.achieved']} <strong>{t[TIER_HEADING_KEY[state.tier]]}</strong>
        </p>

        <p className="mt-2 text-sm text-primary-600">
          {t['student.certificate.scoreLine'].replace('{score}', String(state.score)).replace('{total}', String(state.total))}
        </p>

        <p className="mt-6 text-xs text-primary-500">{formattedDate}</p>
        <p className="mt-1 text-xs text-primary-400">#{id}</p>
      </div>

      <div className="mt-6 flex justify-center gap-4 print:hidden">
        <button
          type="button"
          onClick={() => window.print()}
          className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-[#EB1F27]"
        >
          {t['student.certificate.print']}
        </button>
        <Link to="/exams" className="text-sm font-semibold text-primary-700 underline">
          {t['student.certificate.backToExams']}
        </Link>
      </div>
    </section>
  )
}
```

- [ ] **Step 2: Add the route**

In `frontend/apps/student/src/App.tsx`, add the import (after the `ExamRunnerPage` import):

```tsx
import CertificatePage from './pages/CertificatePage'
```

Add the route (after the `exams/:id` route):

```tsx
            <Route path="exams/:id/certificate" element={<CertificatePage />} />
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: no output (clean)

- [ ] **Step 4: Commit**

```bash
git add frontend/apps/student/src/pages/CertificatePage.tsx frontend/apps/student/src/App.tsx
git commit -m "Add CertificatePage and its route"
```

---

## Task 11: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend suite**

Run: `cd backend && php artisan test --compact`
Expected: all tests pass

- [ ] **Step 2: Run Pint**

Run: `vendor/bin/pint --format agent`
Expected: `{"result":"passed"}`

- [ ] **Step 3: Run the full frontend test suite and typecheck**

Run: `cd frontend && npx vitest run && npx tsc --noEmit -p apps/student/tsconfig.json`
Expected: all vitest tests pass; tsc produces no output

- [ ] **Step 4: Live-verify the guest practice-exam flow**

With the backend (`php artisan serve` on port 8000) and student dev server running, use Playwright to:
1. Navigate to `/exams` as a guest (no token). Pick a class, a subject, check one chapter, confirm the "20 questions" hint text appears, click Start/Submit.
2. Confirm it lands on `/exams/:id` and the question-answering screen appears with roughly the configured question count.
3. Answer the questions, submit, confirm a tier message appears (not the old plain passed/failed line) and, if the tier isn't "failed", a "View certificate" button appears.
4. Click it, confirm the certificate page renders with the score, tier wording, and a print button, and that `window.print()` doesn't throw (click it, then dismiss/cancel the print dialog).

- [ ] **Step 5: Live-verify the logged-in practice-exam flow**

Log in as the seeded sample student (`STU-0001` / `password`), repeat the same `/exams` flow with 2+ chapters checked, confirm the "40 questions" hint, confirm the attempt is tracked (check `exam_attempts` table gained a row via `mysql -u root education_management -e "SELECT * FROM exam_attempts ORDER BY id DESC LIMIT 1;"`), and confirm the certificate flow still works.

- [ ] **Step 6: Live-verify guest access is blocked for a teacher-created exam**

Create a manual, published, non-practice exam directly via SQL or tinker (e.g. reusing the seeded demo exam from `LmsContentDemoSeeder` if `is_practice` is `false` on it by default), then as a guest (cleared localStorage) navigate directly to `/exams/{that-id}` and confirm the "login required" message appears instead of a Start button — then confirm logging in as the enrolled sample student and visiting the same URL correctly shows the Start button and lets the attempt proceed.

- [ ] **Step 7: Clean up any Playwright scratch artifacts**

Run: `rm -rf .playwright-mcp` from the repo root if any screenshots were saved there during verification.
