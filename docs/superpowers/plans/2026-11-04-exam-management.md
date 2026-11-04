# Exam Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a trainer/teacher/school_admin assemble an `Exam` for a `(ClassVersion, Subject, AcademicYear)` from the question bank — manual selection, a one-time server-side random draw ("fixed_random"), or stored criteria for a later per-attempt random draw ("per_attempt_random") — with time/attempt limits, a passing score, a scheduling window, and a draft/published lifecycle.

**Architecture:** `Exam` is `TenantScoped`, denormalizing `school_id`/`district_id` from its parent `ClassVersion` at creation (same shape as `Book`). `exam_questions` (used by `manual`/`fixed_random`) and `exam_random_chapters` (optional narrowing for `per_attempt_random`) are plain pivot tables with no tenant columns, reached only through `exam->questions()`/`exam->randomChapters()` — same reasoning as `question_options` (sub-project 3). `total_marks` is a computed `Attribute` accessor, never a stored column, so it can never go stale. Unlike every other resource in this codebase, `exams` has **no public `index`/`show`** — every route requires `auth:sanctum` + `exams.view`, which is itself granted to a much narrower role list than any prior content permission.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-11-04-exam-management-design.md`

## Global Constraints

- `exams` denormalizes `school_id`/`district_id` from its parent `ClassVersion` at creation time and uses `TenantScoped` — same `TenantScope::apply()` reasoning as every other tenant-scoped table in this codebase (district-scoped actors query `district_id` directly; omitting it throws, doesn't silently no-op).
- `exam_questions`/`exam_random_chapters` have **no** `TenantScoped`/denormalized tenant columns — always reached through their parent `Exam`, never queried standalone.
- `total_marks` is a computed `Attribute` accessor on the `Exam` model, **never** a persisted column.
- `class_version_id`, `subject_id`, `academic_year_id`, and `selection_mode` are immutable after an exam is created — not accepted by `UpdateExamRequest`.
- **No public route for `exams` at all** — `Route::apiResource('exams', ExamController::class)` is registered entirely inside the `auth:sanctum` group, with no separate public `->only(['index', 'show'])` line the way every other resource in `routes/api.php` has.
- `exams.view` is granted only to `national_admin`, `district_officer`, `school_admin`, `trainer`, `teacher`. `student`, `parent_guardian`, `office_staff`, `it_admin`, and `auditor` get **no** `exams.*` permission, even though every one of them already has `questions.view`.
- `exams.create`/`exams.update` go to `school_admin`, `trainer`, `teacher`. `exams.delete` stays `national_admin`-only (via `$allPermissions`), matching `questions.delete`'s precedent.
- Publishing an exam (`status: draft → published`) is rejected unless it's actually takeable: `manual`/`fixed_random` needs ≥1 attached question; `per_attempt_random` needs `random_question_count` set and an eligible question pool at least that large.
- `PATCH /exams/{exam}/questions` is rejected for `per_attempt_random` exams (that mode never populates `exam_questions`). `POST /exams/{exam}/randomize` is rejected for anything but `fixed_random`, and rejected (422, not a short draw) if the eligible pool is smaller than `random_question_count`.
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `Exam` schema and models

**Files:**
- Create: `backend/database/migrations/2026_11_04_080000_create_exams_table.php`
- Create: `backend/database/migrations/2026_11_04_080100_create_exam_questions_table.php`
- Create: `backend/database/migrations/2026_11_04_080200_create_exam_random_chapters_table.php`
- Create: `backend/app/Models/Exam.php`
- Test: `backend/tests/Unit/ExamTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\ClassVersion`, `App\Models\Subject`, `App\Models\AcademicYear`, `App\Models\Chapter`, `App\Models\Question` (all existing), `App\Models\Concerns\TenantScoped`.
- Produces: `App\Models\Exam` (`TenantScoped`; fillable `class_version_id, subject_id, academic_year_id, school_id, district_id, name, bn_name, selection_mode, time_limit_minutes, max_attempts, passing_score_percent, available_from, available_until, status, random_question_count, random_difficulty, marks_per_question`; relations `classVersion(): BelongsTo`, `subject(): BelongsTo`, `academicYear(): BelongsTo`, `questions(): BelongsToMany` (pivot `exam_questions`, `withPivot('id', 'order_index')`, ordered), `randomChapters(): BelongsToMany` (pivot `exam_random_chapters`); computed accessor `total_marks` — Task 3's `ExamResource` and Task 4's `randomize()`/publish-validation logic consume these exact relation/accessor names).

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ExamTenantScopeTest.php`

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
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ExamTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Exam $examA;

    private School $schoolA;

    private ClassVersion $versionA;

    private Subject $subject;

    private AcademicYear $academicYearA;

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

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $this->academicYearA = AcademicYear::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $academicYearB = AcademicYear::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->examA = Exam::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ]);

        Exam::create([
            'class_version_id' => $versionB->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $academicYearB->id,
            'school_id' => $versionB->school_id,
            'district_id' => $versionB->district_id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_exams(): void
    {
        $role = Role::create(['name' => 'school_admin_exams', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Exam::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->examA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_exams(): void
    {
        $role = Role::create(['name' => 'national_admin_exams', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Exam::all());
    }

    public function test_status_defaults_to_draft(): void
    {
        $this->assertSame('draft', $this->examA->status);
    }

    public function test_denormalized_tenant_columns_match_the_parent_class_version(): void
    {
        $this->assertSame($this->versionA->school_id, $this->examA->school_id);
        $this->assertSame($this->versionA->district_id, $this->examA->district_id);
    }

    public function test_total_marks_sums_attached_questions_for_manual_mode(): void
    {
        $book = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $q1 = Question::create(['chapter_id' => $chapter->id, 'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q1', 'marks' => 2]);
        $q2 = Question::create(['chapter_id' => $chapter->id, 'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q2', 'marks' => 3]);

        $this->examA->questions()->attach([$q1->id => ['order_index' => 0], $q2->id => ['order_index' => 1]]);

        $this->assertSame(5, $this->examA->load('questions')->total_marks);
    }

    public function test_total_marks_multiplies_count_by_marks_per_question_for_per_attempt_random_mode(): void
    {
        $exam = Exam::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Final',
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 5,
            'marks_per_question' => 2,
        ]);

        $this->assertSame(10, $exam->total_marks);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ExamTenantScopeTest.php`
Expected: FAIL — `App\Models\Exam` does not exist.

- [ ] **Step 3: Create the migrations**

`backend/database/migrations/2026_11_04_080000_create_exams_table.php`:

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
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            // Denormalized from the class version at creation time (never
            // taken directly from the request) — exams is resolved by
            // direct route-model-binding, so it needs its own TenantScope
            // filter, same as Book/Chapter/Question.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->string('selection_mode');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->unsignedTinyInteger('passing_score_percent')->nullable();
            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('random_question_count')->nullable();
            $table->string('random_difficulty')->nullable();
            $table->unsignedSmallInteger('marks_per_question')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['class_version_id', 'subject_id', 'academic_year_id', 'name'],
                'exams_version_subject_year_name_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
```

`backend/database/migrations/2026_11_04_080100_create_exam_questions_table.php`:

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
     * No tenant columns — exam_questions is always reached through its
     * parent Exam (exam->questions()), never queried standalone, same as
     * question_options.
     */
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
```

`backend/database/migrations/2026_11_04_080200_create_exam_random_chapters_table.php`:

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
     * Optional narrowing for per_attempt_random exams: empty means "any
     * chapter under this exam's subject+class-version is eligible". No
     * tenant columns, same reasoning as exam_questions.
     */
    public function up(): void
    {
        Schema::create('exam_random_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'chapter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_random_chapters');
    }
};
```

- [ ] **Step 4: Create the model**

`backend/app/Models/Exam.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use SoftDeletes, TenantScoped;

    /**
     * In-memory default mirroring the migration's column default.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'class_version_id',
        'subject_id',
        'academic_year_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'selection_mode',
        'time_limit_minutes',
        'max_attempts',
        'passing_score_percent',
        'available_from',
        'available_until',
        'status',
        'random_question_count',
        'random_difficulty',
        'marks_per_question',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'exam_questions')
            ->withPivot('id', 'order_index')
            ->orderBy('exam_questions.order_index');
    }

    public function randomChapters(): BelongsToMany
    {
        return $this->belongsToMany(Chapter::class, 'exam_random_chapters');
    }

    /**
     * Never persisted — a stored total would go stale the moment the
     * question list or marks_per_question changes, and nothing in this
     * sub-project needs to query by total marks. Requires `questions` to
     * be loaded for the manual/fixed_random branch.
     */
    protected function totalMarks(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->selection_mode === 'per_attempt_random') {
                return ($this->random_question_count ?? 0) * ($this->marks_per_question ?? 0);
            }

            return $this->questions->sum('marks');
        });
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Unit/ExamTenantScopeTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_04_080000_create_exams_table.php database/migrations/2026_11_04_080100_create_exam_questions_table.php database/migrations/2026_11_04_080200_create_exam_random_chapters_table.php app/Models/Exam.php tests/Unit/ExamTenantScopeTest.php
git commit -m "Add Exam model and tables, tenant-scoped via ClassVersion, with a computed total_marks accessor"
```

---

### Task 2: `exams.*` permissions

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly.
- Produces: permissions `exams.view`, `exams.create`, `exams.update`, `exams.delete`, seeded and mapped **only** to `national_admin` (all), `district_officer` (view), `school_admin`/`trainer`/`teacher` (view/create/update). Task 3's `ExamPolicy` consumes these exact permission-name strings.

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
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: FAIL — `exams.view` permission does not exist.

- [ ] **Step 3: Add the permissions** — modify `backend/database/seeders/PermissionSeeder.php`, inserting right after the `questions.delete` entry and before `users.view`:

```php
            ['name' => 'questions.view', 'label' => 'View questions'],
            ['name' => 'questions.create', 'label' => 'Create questions'],
            ['name' => 'questions.update', 'label' => 'Update questions'],
            ['name' => 'questions.delete', 'label' => 'Delete questions'],

            ['name' => 'exams.view', 'label' => 'View exams'],
            ['name' => 'exams.create', 'label' => 'Create exams'],
            ['name' => 'exams.update', 'label' => 'Update exams'],
            ['name' => 'exams.delete', 'label' => 'Delete exams'],

            ['name' => 'users.view', 'label' => 'View users'],
```

- [ ] **Step 4: Map the permissions to roles** — in `backend/database/seeders/RolePermissionSeeder.php`, replace the entire `$rolePermissions = [ ... ];` array with:

```php
        $rolePermissions = [
            'national_admin' => $allPermissions,

            'district_officer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'exams.view', 'academic_years.view',
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
                'exams.view', 'exams.create', 'exams.update',
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
                'exams.view', 'exams.create', 'exams.update',
                'teacher_profiles.view', 'student_profiles.view',
            ],

            'student' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view',
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
                'exams.view', 'exams.create', 'exams.update',
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

This is a full-array replacement — verify role-by-role against the "before" version that only `district_officer`, `school_admin`, `teacher`, and `trainer` gained `exams.*` entries, and that every other permission on every role is byte-for-byte identical to before (same lesson as the prior sub-project's seeder-rewrite incident). `student`, `office_staff`, `parent_guardian`, `it_admin`, and `auditor` must end up with **no** `exams.*` entry at all.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "Seed exams.* permissions and map to a restricted role list"
```

---

### Task 3: Exam CRUD API (no public routes)

**Files:**
- Create: `backend/app/Policies/ExamPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreExamRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateExamRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/ExamResource.php`
- Create: `backend/app/Http/Resources/Api/V1/ExamQuestionResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/ExamController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ExamControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Exam` (Task 1), `exams.*` permissions (Task 2), `App\Http\Resources\Api\V1\QuestionResource`/`ChapterResource`/`ClassVersionResource`/`SubjectResource`/`AcademicYearResource` (all existing — `QuestionResource` already nests `QuestionOptionResource`, which already strips `is_correct` from any viewer without `questions.update`, so `ExamQuestionResource` gets that protection for free).
- Produces: routes `GET/POST /api/v1/exams`, `GET/PUT/DELETE /api/v1/exams/{exam}` — **all inside `auth:sanctum`, no public branch**. `App\Http\Controllers\Api\V1\ExamController` with a `private const WITH` eager-load list Task 4's `setQuestions()`/`randomize()` reuse by the same name.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ExamControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ExamControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

    private AcademicYear $academicYearA;

    private AcademicYear $academicYearB;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Bangla Version']);

        $this->academicYearA = AcademicYear::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->academicYearB = AcademicYear::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    private function examPayload(array $overrides = []): array
    {
        return array_merge([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ], $overrides);
    }

    public function test_index_and_show_require_authentication(): void
    {
        $this->getJson('/api/v1/exams')->assertUnauthorized();

        $exam = Exam::create($this->examPayload() + ['school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id]);
        $this->getJson("/api/v1/exams/{$exam->id}")->assertUnauthorized();
    }

    public function test_store_and_update_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->assertForbidden();

        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update', 'exams.view'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['passing_score_percent' => 40])
            ->assertOk()
            ->assertJsonPath('data.passing_score_percent', 40);
    }

    public function test_every_role_without_exams_permissions_is_forbidden_even_with_full_questions_access(): void
    {
        // Mirrors every role that gets questions.view/create/update but
        // deliberately NOT exams.* — proves the exclusion is real, not an
        // accidental gap this test suite never checked.
        $actor = $this->actorWithPermissions(['questions.view', 'questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/exams')->assertForbidden();
        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->assertForbidden();
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_exam(): void
    {
        $exam = Exam::create($this->examPayload([
            'class_version_id' => $this->versionB->id,
            'academic_year_id' => $this->academicYearB->id,
        ]) + ['school_id' => $this->versionB->school_id, 'district_id' => $this->versionB->district_id]);

        $actor = $this->actorWithPermissions(['exams.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/exams/{$exam->id}", ['passing_score_percent' => 50])
            ->assertNotFound();
    }

    public function test_class_version_subject_academic_year_and_selection_mode_are_immutable(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->json('data.id');

        // UpdateExamRequest's rules() simply doesn't accept these fields,
        // so sending them is silently ignored rather than rejected — this
        // test proves the stored values never change.
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/exams/{$id}", [
            'class_version_id' => $this->versionB->id,
            'selection_mode' => 'fixed_random',
        ])->assertOk();

        $exam = Exam::findOrFail($id);
        $this->assertSame($this->versionA->id, $exam->class_version_id);
        $this->assertSame('manual', $exam->selection_mode);
    }

    public function test_publish_is_rejected_for_a_manual_exam_with_no_questions(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['status' => 'published'])
            ->assertUnprocessable();
    }

    public function test_publish_is_rejected_for_a_per_attempt_random_exam_with_too_small_a_pool(): void
    {
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload([
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 5,
            'marks_per_question' => 2,
        ]))->json('data.id');

        // No questions exist under this subject+class-version at all yet.
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['status' => 'published'])
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ExamControllerTest.php`
Expected: FAIL — route `/api/v1/exams` does not exist.

- [ ] **Step 3: Create the policy**

`backend/app/Policies/ExamPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function view(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('exams.create');
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.update');
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $user->hasPermission('exams.delete');
    }
}
```

- [ ] **Step 4: Create the form requests**

`backend/app/Http/Requests/Api/V1/StoreExamRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Exam;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Exam::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('exams', 'name')->withoutTrashed()
                    ->where('class_version_id', $this->integer('class_version_id'))
                    ->where('subject_id', $this->integer('subject_id'))
                    ->where('academic_year_id', $this->integer('academic_year_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'selection_mode' => ['required', 'string', Rule::in(['manual', 'fixed_random', 'per_attempt_random'])],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'passing_score_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],
            'random_question_count' => ['required_if:selection_mode,fixed_random,per_attempt_random', 'nullable', 'integer', 'min:1', 'max:200'],
            'random_difficulty' => ['nullable', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'marks_per_question' => ['required_if:selection_mode,per_attempt_random', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateExamRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    /**
     * class_version_id, subject_id, academic_year_id, and selection_mode
     * are immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $exam = $this->route('exam');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('exams', 'name')->ignore($exam)
                    ->where('class_version_id', $exam->class_version_id)
                    ->where('subject_id', $exam->subject_id)
                    ->where('academic_year_id', $exam->academic_year_id),
            ],
            'bn_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'passing_score_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'available_until' => ['sometimes', 'nullable', 'date', 'after:available_from'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
            'random_question_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'random_difficulty' => ['sometimes', 'nullable', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'marks_per_question' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Publishing an exam requires it to actually be takeable: a
     * manual/fixed_random exam needs at least one attached question; a
     * per_attempt_random exam needs its random criteria set and an
     * eligible pool large enough to satisfy random_question_count.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('status') !== 'published') {
                return;
            }

            $exam = $this->route('exam');

            if (in_array($exam->selection_mode, ['manual', 'fixed_random'], true)) {
                if ($exam->questions()->count() === 0) {
                    $validator->errors()->add('status', 'This exam has no questions yet and cannot be published.');
                }

                return;
            }

            // per_attempt_random
            $count = $this->input('random_question_count') ?? $exam->random_question_count;
            $difficulty = $this->input('random_difficulty') ?? $exam->random_difficulty;

            if (! $count) {
                $validator->errors()->add('status', 'This exam has no random_question_count set and cannot be published.');

                return;
            }

            $chapterIds = Chapter::query()
                ->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
                ->pluck('id');

            $allowedChapterIds = $exam->randomChapters()->pluck('chapters.id');

            if ($allowedChapterIds->isNotEmpty()) {
                $chapterIds = $chapterIds->intersect($allowedChapterIds);
            }

            $poolSize = Question::query()
                ->whereIn('chapter_id', $chapterIds)
                ->when($difficulty, fn ($query, $value) => $query->where('difficulty', $value))
                ->count();

            if ($poolSize < $count) {
                $validator->errors()->add('status', "Only {$poolSize} eligible question(s) match this exam's random criteria, but random_question_count is {$count}.");
            }
        });
    }
}
```

- [ ] **Step 5: Create the resources**

`backend/app/Http/Resources/Api/V1/ExamQuestionResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->pivot->id,
            'order_index' => $this->pivot->order_index,
            // QuestionResource already nests QuestionOptionResource,
            // which already strips is_correct from any viewer without
            // questions.update — that protection applies here for free.
            'question' => new QuestionResource($this->resource),
        ];
    }
}
```

`backend/app/Http/Resources/Api/V1/ExamResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_version_id' => $this->class_version_id,
            'subject_id' => $this->subject_id,
            'academic_year_id' => $this->academic_year_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'selection_mode' => $this->selection_mode,
            'time_limit_minutes' => $this->time_limit_minutes,
            'max_attempts' => $this->max_attempts,
            'passing_score_percent' => $this->passing_score_percent,
            'available_from' => $this->available_from,
            'available_until' => $this->available_until,
            'status' => $this->status,
            'random_question_count' => $this->random_question_count,
            'random_difficulty' => $this->random_difficulty,
            'marks_per_question' => $this->marks_per_question,
            'total_marks' => $this->total_marks,
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
            'random_chapters' => ChapterResource::collection($this->whenLoaded('randomChapters')),
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

`backend/app/Http/Controllers/Api/V1/ExamController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExamRequest;
use App\Http\Requests\Api\V1\UpdateExamRequest;
use App\Http\Resources\Api\V1\ExamResource;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\Exam;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExamController extends Controller
{
    /**
     * Shared eager-load list — Task 4's setQuestions()/randomize() reuse
     * this by the same name so the response shape stays consistent
     * across every action.
     */
    private const WITH = ['classVersion', 'subject', 'academicYear', 'questions.options', 'randomChapters'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $exams = Exam::query()
            ->with(self::WITH)
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->orderBy('name')
            ->get();

        return ExamResource::collection($exams);
    }

    public function show(Exam $exam): ExamResource
    {
        return new ExamResource($exam->load(self::WITH));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $this->authorize('create', Exam::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
        Subject::findOrFail($request->integer('subject_id'));
        AcademicYear::findOrFail($request->integer('academic_year_id'));

        $data = $request->validated();
        $data['school_id'] = $classVersion->school_id;
        $data['district_id'] = $classVersion->district_id;

        $exam = Exam::create($data);

        return (new ExamResource($exam->load(self::WITH)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateExamRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        $exam->update($request->validated());

        return new ExamResource($exam->load(self::WITH));
    }

    public function destroy(Exam $exam): Response
    {
        $this->authorize('delete', $exam);

        $exam->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Register routes** — modify `backend/routes/api.php`

Add the import alongside the other controller imports (alphabetical, after `DivisionController`):

```php
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\GuardianController;
```

Add the route **entirely inside** the `auth:sanctum` group (there is no public branch at all for `exams`) — insert right after the `questions` block:

```php
        Route::patch('/questions/reorder', [QuestionController::class, 'reorder']);
        Route::post('/questions/import', [QuestionController::class, 'import']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('exams', ExamController::class);

        Route::post('/books/{book}/pdf', [BookController::class, 'uploadPdf']);
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/ExamControllerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/ExamPolicy.php app/Http/Requests/Api/V1/StoreExamRequest.php app/Http/Requests/Api/V1/UpdateExamRequest.php app/Http/Resources/Api/V1/ExamResource.php app/Http/Resources/Api/V1/ExamQuestionResource.php app/Http/Controllers/Api/V1/ExamController.php routes/api.php tests/Feature/ExamControllerTest.php
git commit -m "Add Exam CRUD API with no public routes: policy, requests, resources, controller"
```

---

### Task 4: Manual question list + fixed_random draw

**Files:**
- Create: `backend/app/Http/Requests/Api/V1/SetExamQuestionsRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ExamQuestionsControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Exam`/`Question`/`Chapter` (existing), `ExamController::WITH` (Task 3, same file).
- Produces: routes `PATCH /api/v1/exams/{exam}/questions`, `POST /api/v1/exams/{exam}/randomize`; `ExamController::setQuestions()`/`randomize()`.

- [ ] **Step 1: Write the failing tests** — create `backend/tests/Feature/ExamQuestionsControllerTest.php`

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
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ExamQuestionsControllerTest.php`
Expected: FAIL — route `/api/v1/exams/{exam}/questions` does not exist.

- [ ] **Step 3: Create the request**

`backend/app/Http/Requests/Api/V1/SetExamQuestionsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetExamQuestionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', Rule::exists('questions', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Rejected for per_attempt_random exams (which never use
     * exam_questions), and every given question must belong to a chapter
     * reachable through a book under this exam's (class_version_id,
     * subject_id) pair — a plain Rule::exists can't express that
     * relational constraint.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $exam = $this->route('exam');

            if ($exam->selection_mode === 'per_attempt_random') {
                $validator->errors()->add('question_ids', 'This exam uses per_attempt_random selection and does not accept a manual question list.');

                return;
            }

            $ids = $this->input('question_ids', []);

            if (! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Question::query()
                ->whereIn('id', $ids)
                ->whereHas('chapter.book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))
                ->count();

            if ($matching !== count(array_unique($ids))) {
                $validator->errors()->add('question_ids', "One or more questions do not belong to a chapter under this exam's subject and class version.");
            }
        });
    }
}
```

- [ ] **Step 4: Add the controller actions** — modify `backend/app/Http/Controllers/Api/V1/ExamController.php`

Add imports:

```php
use App\Http\Requests\Api\V1\SetExamQuestionsRequest;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
```

Add the methods after `destroy()`:

```php
    public function setQuestions(SetExamQuestionsRequest $request, Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        DB::transaction(function () use ($exam, $request) {
            $exam->questions()->detach();

            foreach ($request->validated()['question_ids'] as $index => $questionId) {
                $exam->questions()->attach($questionId, ['order_index' => $index]);
            }
        });

        return new ExamResource($exam->load(self::WITH));
    }

    public function randomize(Exam $exam): ExamResource
    {
        $this->authorize('update', $exam);

        if ($exam->selection_mode !== 'fixed_random') {
            throw ValidationException::withMessages([
                'selection_mode' => ['Only fixed_random exams can be randomized.'],
            ]);
        }

        if (! $exam->random_question_count) {
            throw ValidationException::withMessages([
                'random_question_count' => ['This exam has no random_question_count configured.'],
            ]);
        }

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

        if ($eligible->count() < $exam->random_question_count) {
            throw ValidationException::withMessages([
                'random_question_count' => ["Only {$eligible->count()} eligible question(s) are available, but random_question_count is {$exam->random_question_count}."],
            ]);
        }

        $drawn = $eligible->shuffle()->take($exam->random_question_count)->values();

        DB::transaction(function () use ($exam, $drawn) {
            $exam->questions()->detach();

            foreach ($drawn as $index => $questionId) {
                $exam->questions()->attach($questionId, ['order_index' => $index]);
            }
        });

        return new ExamResource($exam->load(self::WITH));
    }
```

Add the `Chapter` import too (used by `randomize()`):

```php
use App\Models\Chapter;
```

- [ ] **Step 5: Register routes** — modify `backend/routes/api.php`, adding right after the `exams` apiResource line:

```php
        Route::apiResource('exams', ExamController::class);
        Route::patch('/exams/{exam}/questions', [ExamController::class, 'setQuestions']);
        Route::post('/exams/{exam}/randomize', [ExamController::class, 'randomize']);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/ExamQuestionsControllerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS — no regressions in any existing test.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/SetExamQuestionsRequest.php app/Http/Controllers/Api/V1/ExamController.php routes/api.php tests/Feature/ExamQuestionsControllerTest.php
git commit -m "Add manual question-list endpoint and fixed_random server-side draw"
```
