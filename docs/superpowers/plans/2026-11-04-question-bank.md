# Question Bank Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `Chapter` an ordered set of questions (`mcq_single`/`mcq_multiple`/`true_false`), with CRUD, reordering, permissions, and CSV bulk import/export with a row-by-row error report.

**Architecture:** `Question` is `TenantScoped` and `belongsTo(Chapter)`, denormalizing `school_id`/`district_id` from its parent `Chapter` at creation — same shape as `ChapterVideo`/`ChapterMaterial`. `QuestionOption` is a plain child table (no tenant columns, no `TenantScoped`), always reached through `question->options()`, mirroring `ChapterVideoProgress`. A question's option set is replaced wholesale (delete-and-recreate inside a transaction) on every `store`/`update` rather than diffed — correct and simple for a list that never exceeds 6 rows. A new `App\Support\QuestionCsvImporter` class turns one CSV row into `Question`/`QuestionOption` creation data (or throws `InvalidArgumentException` with a human-readable message), kept separate from HTTP/DB concerns so it's unit-testable and reused by both `import` (parse) and `export` (the inverse `toRow()`).

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-11-04-question-bank-design.md`

## Global Constraints

- `questions` denormalizes `school_id`/`district_id` from its parent `Chapter` at creation time and uses `TenantScoped` — confirmed necessary because `App\Models\Scopes\TenantScope::apply()` queries `district_id` directly for any district-scoped actor; omitting the column throws a missing-column SQL error for that actor class, not a graceful empty result.
- `question_options` has **no** `TenantScoped`/denormalized tenant columns — it is always reached through `question->options()`, never queried standalone, same reasoning as `chapter_video_progress`.
- Question images live on a **public** disk (`config('content.question_image_disk')`, default `public`), never the private signed-URL disk `Book`'s PDFs use — an exam-taking student needs the image visible for a whole attempt, which can outlast a 5-minute signed link, and it isn't confidential.
- Bulk import is **synchronous** — no Horizon/queued Job exists anywhere in this codebase yet (`QUEUE_CONNECTION=database` sits unused), and adding the project's first background job is out of scope here.
- Import never aborts the whole file on one bad row — each row is parsed and persisted independently, and a parse/DB failure for one row is recorded in the response, not thrown to the client.
- `QuestionOptionResource` strips `is_correct` from the response for any viewer without `questions.update` — this matters once a later sub-project lets students fetch exam questions through the same resource class.
- Reordering uses a dedicated no-instance `QuestionPolicy::reorder(User $user): bool` ability, never `can('update', Question::class)` against the instance-typed `update()` method — this exact `ArgumentCountError` trip-wire has recurred three times in prior sub-projects (see `ChapterVideoPolicy::reorder()`'s docblock for the full explanation).
- This codebase's route-model-binding convention is the resource's singular snake_case name; `Question` is one word, so `{question}` needs no special casing — but still bind it exactly as `Question $question` / route segment `{question}`, matching every other single-word resource (`Book $book`, `Chapter $chapter`).
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `Question`/`QuestionOption` schema and models

**Files:**
- Create: `backend/database/migrations/2026_11_04_070000_create_questions_table.php`
- Create: `backend/database/migrations/2026_11_04_070100_create_question_options_table.php`
- Create: `backend/app/Models/Question.php`
- Create: `backend/app/Models/QuestionOption.php`
- Modify: `backend/app/Models/Chapter.php`
- Modify: `backend/config/content.php`
- Test: `backend/tests/Unit/QuestionTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Chapter` (`belongsTo` target, already has `school_id`/`district_id`), `App\Models\Concerns\TenantScoped` trait.
- Produces: `App\Models\Question` (`TenantScoped`, fillable `chapter_id,school_id,district_id,question_type,question_text,bn_question_text,image_path,image_disk,marks,difficulty,order_index,is_active`; relations `chapter(): BelongsTo`, `options(): HasMany`; in-memory defaults `marks=1, difficulty='medium', order_index=0, is_active=true`). `App\Models\QuestionOption` (fillable `question_id,option_text,bn_option_text,is_correct,order_index`; relation `question(): BelongsTo`; in-memory defaults `is_correct=false, order_index=0`). `Chapter::questions(): HasMany`. Config keys `content.question_image_disk` (default `public`) and `content.max_image_size_kb` (default `2048`) — Task 3's image upload validation and storage consume these by these exact names.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/QuestionTenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\District;
use App\Models\Division;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class QuestionTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Question $questionA;

    private School $schoolA;

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

        $versionA = \App\Models\ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = \App\Models\ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        $chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);

        $this->questionA = Question::create([
            'chapter_id' => $chapterA->id,
            'school_id' => $chapterA->school_id,
            'district_id' => $chapterA->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
        ]);

        Question::create([
            'chapter_id' => $chapterB->id,
            'school_id' => $chapterB->school_id,
            'district_id' => $chapterB->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 3+3?',
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_questions(): void
    {
        $role = Role::create(['name' => 'school_admin_questions', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Question::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->questionA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_questions(): void
    {
        $role = Role::create(['name' => 'national_admin_questions', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Question::all());
    }

    public function test_defaults_are_applied(): void
    {
        $this->assertSame(1, $this->questionA->marks);
        $this->assertSame('medium', $this->questionA->difficulty);
        $this->assertSame(0, $this->questionA->order_index);
        $this->assertTrue($this->questionA->is_active);
    }

    public function test_question_option_has_no_tenant_columns_and_belongs_to_question(): void
    {
        $option = QuestionOption::create([
            'question_id' => $this->questionA->id,
            'option_text' => '4',
            'is_correct' => true,
        ]);

        $this->assertFalse($this->questionA->getConnection()->getSchemaBuilder()->hasColumn('question_options', 'school_id'));
        $this->assertSame($this->questionA->id, $option->question->id);
        $this->assertTrue($option->is_correct);
        $this->assertSame(0, $option->order_index);
    }

    /**
     * Confirms the denormalize-at-creation pattern was followed
     * correctly, mirroring the same check Book/Chapter's own test suite
     * runs for their denormalized school_id/district_id.
     */
    public function test_denormalized_tenant_columns_match_the_parent_chapter(): void
    {
        // No actor is logged in at this point in the test, so TenantScope
        // is inactive and the relation loads unfiltered.
        $chapter = $this->questionA->chapter;

        $this->assertSame($chapter->school_id, $this->questionA->school_id);
        $this->assertSame($chapter->district_id, $this->questionA->district_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/QuestionTenantScopeTest.php`
Expected: FAIL — `App\Models\Question` does not exist.

- [ ] **Step 3: Create the migrations**

`backend/database/migrations/2026_11_04_070000_create_questions_table.php`:

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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time (never taken
            // directly from the request) — questions is resolved by direct
            // route-model-binding, so it needs its own TenantScope filter,
            // same as Book/Chapter/ChapterVideo/ChapterMaterial.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('question_type');
            $table->text('question_text');
            $table->text('bn_question_text')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_disk')->nullable();
            $table->unsignedSmallInteger('marks')->default(1);
            $table->string('difficulty')->default('medium');
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
```

`backend/database/migrations/2026_11_04_070100_create_question_options_table.php`:

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
     * No denormalized tenant columns here — question_options is always
     * reached through its parent Question (question->options()), never
     * queried standalone, same as chapter_video_progress.
     */
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('option_text');
            $table->string('bn_option_text')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
```

- [ ] **Step 4: Create the models**

`backend/app/Models/Question.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use SoftDeletes, TenantScoped;

    /**
     * In-memory defaults mirroring the migration's column defaults, so a
     * freshly-created instance reflects them without an extra DB round
     * trip.
     */
    protected $attributes = [
        'marks' => 1,
        'difficulty' => 'medium',
        'order_index' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'chapter_id',
        'school_id',
        'district_id',
        'question_type',
        'question_text',
        'bn_question_text',
        'image_path',
        'image_disk',
        'marks',
        'difficulty',
        'order_index',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order_index');
    }
}
```

`backend/app/Models/QuestionOption.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    /**
     * In-memory defaults mirroring the migration's column defaults.
     */
    protected $attributes = [
        'is_correct' => false,
        'order_index' => 0,
    ];

    protected $fillable = [
        'question_id',
        'option_text',
        'bn_option_text',
        'is_correct',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
```

Modify `backend/app/Models/Chapter.php` — add a `questions()` relation alongside the existing `materials()`/`videos()`:

```php
    public function videos(): HasMany
    {
        return $this->hasMany(ChapterVideo::class)->orderBy('order_index');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('order_index');
    }
}
```

Modify `backend/config/content.php` — the file currently ends with:

```php
    'video_completion_threshold_percent' => (int) env('VIDEO_COMPLETION_THRESHOLD_PERCENT', 90),
];
```

Replace that (the key line **and** the closing `];`) with:

```php
    'video_completion_threshold_percent' => (int) env('VIDEO_COMPLETION_THRESHOLD_PERCENT', 90),

    /*
     * The filesystem disk question/option images are stored on — public,
     * unlike pdf_disk, because an exam-taking student needs the image to
     * stay visible for the whole attempt (can outlast a 5-minute signed
     * link) and it isn't confidential the way a licensed PDF is.
     */
    'question_image_disk' => env('CONTENT_QUESTION_IMAGE_DISK', 'public'),

    /*
     * Maximum upload size, in kilobytes, for a question or option image.
     */
    'max_image_size_kb' => (int) env('MAX_IMAGE_SIZE_KB', 2048),
];
```

- [ ] **Step 5: Run migrations and the test**

Run: `php artisan test tests/Unit/QuestionTenantScopeTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_04_070000_create_questions_table.php database/migrations/2026_11_04_070100_create_question_options_table.php app/Models/Question.php app/Models/QuestionOption.php app/Models/Chapter.php config/content.php tests/Unit/QuestionTenantScopeTest.php
git commit -m "Add Question and QuestionOption models and tables, tenant-scoped via Chapter"
```

---

### Task 2: `questions.*` permissions

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly (permissions are string names, not FK-coupled to the `questions` table).
- Produces: permissions `questions.view`, `questions.create`, `questions.update`, `questions.delete`, seeded and mapped to roles. Task 3's `QuestionPolicy` consumes these exact permission-name strings via `$user->hasPermission(...)`.

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
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: FAIL — `questions.view` permission does not exist.

- [ ] **Step 3: Add the permissions** — modify `backend/database/seeders/PermissionSeeder.php`, inserting right after the `chapters.delete` entry and before `users.view`:

```php
            ['name' => 'chapters.view', 'label' => 'View chapters'],
            ['name' => 'chapters.create', 'label' => 'Create chapters'],
            ['name' => 'chapters.update', 'label' => 'Update chapters'],
            ['name' => 'chapters.delete', 'label' => 'Delete chapters'],

            ['name' => 'questions.view', 'label' => 'View questions'],
            ['name' => 'questions.create', 'label' => 'Create questions'],
            ['name' => 'questions.update', 'label' => 'Update questions'],
            ['name' => 'questions.delete', 'label' => 'Delete questions'],

            ['name' => 'users.view', 'label' => 'View users'],
```

- [ ] **Step 4: Map the permissions to roles** — in `backend/database/seeders/RolePermissionSeeder.php`, replace the entire `$rolePermissions = [ ... ];` array (from `'national_admin' => $allPermissions,` through the closing `];` before the `foreach`) with:

```php
        $rolePermissions = [
            'national_admin' => $allPermissions,

            'district_officer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
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

This is a full-array replacement — verify role-by-role against the "before" version read during planning (every role above is identical to its current definition except the added `questions.*` entries; no other permission was dropped or changed). This matches the lesson from the prior sub-project's seeder incident: when a shared seeder array is rewritten wholesale rather than edited per-line, re-derive it role-by-role rather than trusting a diff skim.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "Seed questions.* permissions and map to roles"
```

---

### Task 3: Question CRUD API

**Files:**
- Create: `backend/app/Policies/QuestionPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreQuestionRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateQuestionRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/QuestionResource.php`
- Create: `backend/app/Http/Resources/Api/V1/QuestionOptionResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/QuestionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/QuestionControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Question`/`App\Models\QuestionOption` (Task 1), `questions.*` permissions (Task 2), `App\Models\Chapter` (existing).
- Produces: routes `GET/POST /api/v1/questions`, `GET/PATCH/DELETE /api/v1/questions/{question}`; `App\Http\Controllers\Api\V1\QuestionController` with a private `syncOptions(Question $question, array $options): void` method Task 4 does not need but Task 5/6's import/export logic does **not** reuse (they build `Question`/`QuestionOption` directly — see Task 5). `App\Policies\QuestionPolicy` with abilities `viewAny`, `view`, `create`, `update`, `delete` — Task 4 adds `reorder` to this same file.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/QuestionControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

    private School $schoolA;

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

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);
    }

    private function mcqPayload(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => $this->chapterA->id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
            'options' => [
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => true],
            ],
        ], $overrides);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())->assertForbidden();

        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_text', 'What is 2+2?')
            ->assertJsonCount(2, 'data.options')
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/questions/{$id}", ['question_text' => 'What is 2+2, really?'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'What is 2+2, really?')
            ->assertJsonCount(2, 'data.options');
    }

    public function test_store_rejects_true_false_without_exactly_two_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'true_false',
            'options' => [
                ['option_text' => 'True', 'is_correct' => true],
                ['option_text' => 'False', 'is_correct' => false],
                ['option_text' => 'Maybe', 'is_correct' => false],
            ],
        ]))->assertUnprocessable();
    }

    public function test_store_rejects_mcq_single_with_two_correct_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'options' => [
                ['option_text' => '3', 'is_correct' => true],
                ['option_text' => '4', 'is_correct' => true],
            ],
        ]))->assertUnprocessable();
    }

    public function test_store_rejects_mcq_multiple_with_no_correct_options(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload([
            'question_type' => 'mcq_multiple',
            'options' => [
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => false],
            ],
        ]))->assertUnprocessable();
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_question(): void
    {
        $question = Question::create([
            'chapter_id' => $this->chapterB->id,
            'school_id' => $this->chapterB->school_id,
            'district_id' => $this->chapterB->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'School B question',
        ]);

        $actor = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/v1/questions/{$question->id}", ['question_text' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_index_excludes_inactive_questions_unless_the_caller_can_manage_them(): void
    {
        $enabled = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Enabled']);
        $disabled = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Disabled', 'is_active' => false]);

        $ids = $this->getJson('/api/v1/questions')->assertOk()->json('data.*.id');
        $this->assertContains($enabled->id, $ids);
        $this->assertNotContains($disabled->id, $ids);

        $manager = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $ids = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/questions')->assertOk()->json('data.*.id');
        $this->assertContains($disabled->id, $ids);
    }

    public function test_is_correct_is_hidden_from_a_viewer_without_update_permission(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())->json('data.id');

        $viewer = $this->actorWithPermissions(['questions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/questions/{$id}")->assertOk();
        $this->assertArrayNotHasKey('is_correct', $response->json('data.options.0'));

        $manager = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/questions/{$id}")->assertOk();
        $this->assertArrayHasKey('is_correct', $response->json('data.options.0'));
    }

    public function test_store_with_image_uploads_and_destroy_removes_the_file(): void
    {
        Storage::fake('public');

        $allowed = $this->actorWithPermissions(['questions.create', 'questions.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $image = UploadedFile::fake()->image('diagram.png');

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/questions', array_merge(
            $this->mcqPayload(),
            ['image' => $image]
        ))->assertCreated();

        $path = $response->json('data.image_url');
        $this->assertNotNull($path);

        $question = Question::findOrFail($response->json('data.id'));
        Storage::disk('public')->assertExists($question->image_path);

        $this->actingAs($allowed, 'sanctum')->deleteJson("/api/v1/questions/{$question->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($question->image_path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/QuestionControllerTest.php`
Expected: FAIL — route `/api/v1/questions` does not exist (404s).

- [ ] **Step 3: Create the policy**

`backend/app/Policies/QuestionPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('questions.view');
    }

    public function view(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('questions.create');
    }

    public function update(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.update');
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.delete');
    }
}
```

- [ ] **Step 4: Create the form requests**

`backend/app/Http/Requests/Api/V1/StoreQuestionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Question::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'question_type' => ['required', 'string', Rule::in(['mcq_single', 'mcq_multiple', 'true_false'])],
            'question_text' => ['required', 'string'],
            'bn_question_text' => ['nullable', 'string'],
            'marks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'difficulty' => ['sometimes', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'order_index' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.config('content.max_image_size_kb')],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string', 'max:500'],
            'options.*.bn_option_text' => ['nullable', 'string', 'max:500'],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Option-count and correctness rules are per question_type and span
     * the whole `options` array — a plain field rule can't express them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('question_type');
            $options = $this->input('options');

            if (! is_array($options) || ! in_array($type, ['mcq_single', 'mcq_multiple', 'true_false'], true)) {
                return;
            }

            $correctCount = count(array_filter($options, fn ($option) => (bool) ($option['is_correct'] ?? false)));

            if ($type === 'true_false' && count($options) !== 2) {
                $validator->errors()->add('options', 'A true_false question must have exactly 2 options.');
            }

            if (in_array($type, ['mcq_single', 'true_false'], true) && $correctCount !== 1) {
                $validator->errors()->add('options', "A {$type} question must have exactly 1 correct option.");
            }

            if ($type === 'mcq_multiple' && $correctCount < 1) {
                $validator->errors()->add('options', 'An mcq_multiple question must have at least 1 correct option.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateQuestionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQuestionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('question')) ?? false;
    }

    /**
     * chapter_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_type' => ['sometimes', 'string', Rule::in(['mcq_single', 'mcq_multiple', 'true_false'])],
            'question_text' => ['sometimes', 'required', 'string'],
            'bn_question_text' => ['sometimes', 'nullable', 'string'],
            'marks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'difficulty' => ['sometimes', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'order_index' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png', 'max:'.config('content.max_image_size_kb')],
            'options' => ['sometimes', 'array', 'min:2'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:500'],
            'options.*.bn_option_text' => ['nullable', 'string', 'max:500'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
            'options.*.order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Option-count and correctness rules are per the (possibly unchanged)
     * question_type and span the whole `options` array.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $options = $this->input('options');

            if (! is_array($options)) {
                return;
            }

            $type = $this->input('question_type') ?? $this->route('question')->question_type;
            $correctCount = count(array_filter($options, fn ($option) => (bool) ($option['is_correct'] ?? false)));

            if ($type === 'true_false' && count($options) !== 2) {
                $validator->errors()->add('options', 'A true_false question must have exactly 2 options.');
            }

            if (in_array($type, ['mcq_single', 'true_false'], true) && $correctCount !== 1) {
                $validator->errors()->add('options', "A {$type} question must have exactly 1 correct option.");
            }

            if ($type === 'mcq_multiple' && $correctCount < 1) {
                $validator->errors()->add('options', 'An mcq_multiple question must have at least 1 correct option.');
            }
        });
    }
}
```

- [ ] **Step 5: Create the resources**

`backend/app/Http/Resources/Api/V1/QuestionOptionResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'bn_option_text' => $this->bn_option_text,
            'order_index' => $this->order_index,
            // A student fetching exam questions must never see which
            // option is flagged correct through this same resource class.
            'is_correct' => $this->when(
                $request->user()?->hasPermission('questions.update') ?? false,
                $this->is_correct
            ),
        ];
    }
}
```

`backend/app/Http/Resources/Api/V1/QuestionResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'bn_question_text' => $this->bn_question_text,
            'image_url' => $this->image_path ? Storage::disk($this->image_disk)->url($this->image_path) : null,
            'marks' => $this->marks,
            'difficulty' => $this->difficulty,
            'order_index' => $this->order_index,
            'is_active' => $this->is_active,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

`backend/app/Http/Controllers/Api/V1/QuestionController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreQuestionRequest;
use App\Http\Requests\Api\V1\UpdateQuestionRequest;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QuestionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = Question::query()
            ->with(['chapter', 'options'])
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->when(
                ! $request->user()?->hasPermission('questions.update'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return QuestionResource::collection($questions);
    }

    public function show(Question $question): QuestionResource
    {
        if (! $question->is_active && ! request()->user()?->hasPermission('questions.update')) {
            abort(404);
        }

        return new QuestionResource($question->load(['chapter', 'options']));
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $this->authorize('create', Question::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->safe()->except(['image', 'options']);
        $data['school_id'] = $chapter->school_id;
        $data['district_id'] = $chapter->district_id;

        if ($request->hasFile('image')) {
            $disk = config('content.question_image_disk');
            $file = $request->file('image');
            $data['image_path'] = $file->storeAs("questions/{$chapter->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), $disk);
            $data['image_disk'] = $disk;
        }

        $question = Question::create($data);
        $this->syncOptions($question, $request->input('options'));

        return (new QuestionResource($question->load(['chapter', 'options'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateQuestionRequest $request, Question $question): QuestionResource
    {
        $this->authorize('update', $question);

        $data = $request->safe()->except(['image', 'options']);

        if ($request->hasFile('image')) {
            if ($question->image_path) {
                Storage::disk($question->image_disk)->delete($question->image_path);
            }

            $disk = config('content.question_image_disk');
            $file = $request->file('image');
            $data['image_path'] = $file->storeAs("questions/{$question->chapter_id}", Str::uuid().'.'.$file->getClientOriginalExtension(), $disk);
            $data['image_disk'] = $disk;
        }

        $question->update($data);

        if ($request->has('options')) {
            $this->syncOptions($question, $request->input('options'));
        }

        return new QuestionResource($question->load(['chapter', 'options']));
    }

    public function destroy(Question $question): Response
    {
        $this->authorize('delete', $question);

        if ($question->image_path) {
            Storage::disk($question->image_disk)->delete($question->image_path);
        }

        $question->delete();

        return response()->noContent();
    }

    /**
     * Replace a question's full option set transactionally — simplest
     * correct approach for a list this small (never more than 6 options),
     * avoiding a diffing algorithm for add/remove/reorder.
     *
     * @param  array<int, array{option_text: string, bn_option_text?: ?string, is_correct?: bool, order_index?: int}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        DB::transaction(function () use ($question, $options) {
            $question->options()->delete();

            foreach ($options as $index => $option) {
                $question->options()->create([
                    'option_text' => $option['option_text'],
                    'bn_option_text' => $option['bn_option_text'] ?? null,
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'order_index' => $option['order_index'] ?? $index,
                ]);
            }
        });
    }
}
```

- [ ] **Step 7: Register routes** — modify `backend/routes/api.php`

Add the import alongside the other controller imports (alphabetical, after `PermissionController`):

```php
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\RoleController;
```

Add the public routes next to `chapters`:

```php
    Route::apiResource('chapters', ChapterController::class)->only(['index', 'show']);
    Route::apiResource('questions', QuestionController::class)->only(['index', 'show']);
    Route::apiResource('chapter-materials', ChapterMaterialController::class)->only(['index', 'show']);
```

Add the mutating routes next to `chapters`' auth block entry:

```php
        Route::apiResource('chapters', ChapterController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/QuestionControllerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/QuestionPolicy.php app/Http/Requests/Api/V1/StoreQuestionRequest.php app/Http/Requests/Api/V1/UpdateQuestionRequest.php app/Http/Resources/Api/V1/QuestionResource.php app/Http/Resources/Api/V1/QuestionOptionResource.php app/Http/Controllers/Api/V1/QuestionController.php routes/api.php tests/Feature/QuestionControllerTest.php
git commit -m "Add Question CRUD API: policy, requests, resource, controller, routes, permissions"
```

---

### Task 4: Reorder endpoint

**Files:**
- Modify: `backend/app/Policies/QuestionPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/ReorderQuestionsRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/QuestionController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/QuestionControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Question` (Task 1), `QuestionPolicy` (Task 3).
- Produces: `QuestionPolicy::reorder(User $user): bool`; route `PATCH /api/v1/questions/reorder`; `QuestionController::reorder(ReorderQuestionsRequest $request): JsonResponse`.

- [ ] **Step 1: Write the failing tests** — append to `backend/tests/Feature/QuestionControllerTest.php` (inside the class, before the final closing brace)

```php
    public function test_reorder_sets_order_index_from_the_given_sequence(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $first = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'First', 'order_index' => 0]);
        $second = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Second', 'order_index' => 1]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$second->id, $first->id]])
            ->assertOk();

        $this->assertSame(0, $second->refresh()->order_index);
        $this->assertSame(1, $first->refresh()->order_index);
    }

    public function test_reorder_rejects_an_id_from_a_different_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $foreign = Question::create(['chapter_id' => $this->chapterB->id, 'school_id' => $this->chapterB->school_id, 'district_id' => $this->chapterB->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Foreign']);
        $own = Question::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Own']);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$own->id, $foreign->id]])
            ->assertUnprocessable();
    }

    public function test_reorder_is_forbidden_without_update_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->patchJson('/api/v1/questions/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => []])
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/QuestionControllerTest.php --filter=reorder`
Expected: FAIL — route `/api/v1/questions/reorder` does not exist.

- [ ] **Step 3: Add the policy ability** — modify `backend/app/Policies/QuestionPolicy.php`, inserting before `delete()`:

```php
    /**
     * Reordering spans multiple rows identified only by a chapter_id in
     * the request body — there is no single Question instance to check
     * against, so this is a no-instance ability (same shape as create()),
     * not a reuse of update(). Calling $user->can('update',
     * Question::class) instead would pass only $user to update(), which
     * requires a second typed Question parameter with no default — an
     * ArgumentCountError, not a graceful denial. See
     * ChapterVideoPolicy::reorder() for the same pattern.
     */
    public function reorder(User $user): bool
    {
        return $user->hasPermission('questions.update');
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->hasPermission('questions.delete');
    }
```

- [ ] **Step 4: Create the request**

`backend/app/Http/Requests/Api/V1/ReorderQuestionsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderQuestionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', Question::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', Rule::exists('questions', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Every ID in ordered_ids must belong to the given chapter_id — a
     * plain Rule::exists can't express that relational constraint.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $chapterId = $this->input('chapter_id');
            $ids = $this->input('ordered_ids', []);

            if (! $chapterId || ! is_array($ids) || $ids === []) {
                return;
            }

            $matching = Question::where('chapter_id', $chapterId)->whereIn('id', $ids)->count();

            if ($matching !== count($ids)) {
                $validator->errors()->add('ordered_ids', 'One or more question IDs do not belong to the given chapter.');
            }
        });
    }
}
```

- [ ] **Step 5: Add the controller action** — modify `backend/app/Http/Controllers/Api/V1/QuestionController.php`

Add the import:

```php
use App\Http\Requests\Api\V1\ReorderQuestionsRequest;
```

Add the method after `index()`:

```php
    public function reorder(ReorderQuestionsRequest $request): JsonResponse
    {
        $this->authorize('reorder', Question::class);

        DB::transaction(function () use ($request) {
            foreach ($request->validated()['ordered_ids'] as $index => $id) {
                Question::where('id', $id)->where('chapter_id', $request->validated()['chapter_id'])->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }
```

- [ ] **Step 6: Register the route** — modify `backend/routes/api.php`, adding before the mutating `questions` apiResource line from Task 3:

```php
        Route::patch('/questions/reorder', [QuestionController::class, 'reorder']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/QuestionControllerTest.php`
Expected: PASS (11 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/QuestionPolicy.php app/Http/Requests/Api/V1/ReorderQuestionsRequest.php app/Http/Controllers/Api/V1/QuestionController.php routes/api.php tests/Feature/QuestionControllerTest.php
git commit -m "Add question reorder endpoint with a no-instance policy ability"
```

---

### Task 5: Bulk CSV import

**Files:**
- Create: `backend/app/Support/QuestionCsvImporter.php`
- Create: `backend/app/Http/Requests/Api/V1/ImportQuestionsRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/QuestionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Unit/QuestionCsvImporterTest.php`
- Test: `backend/tests/Feature/QuestionImportExportTest.php`

**Interfaces:**
- Consumes: `App\Models\Question`/`App\Models\QuestionOption` (Task 1), `QuestionPolicy::create` (Task 3).
- Produces: `App\Support\QuestionCsvImporter::HEADERS` (array of 13 column names, exact order given in the spec), `App\Support\QuestionCsvImporter::parseRow(array $row): array{question: array<string, mixed>, options: list<array<string, mixed>>}` (throws `\InvalidArgumentException` on any structural problem). Task 6's `toRow()` is added to this same file and is the inverse of `parseRow()` — both must stay column-order-compatible. Route `POST /api/v1/questions/import`.

- [ ] **Step 1: Write the failing unit test** — create `backend/tests/Unit/QuestionCsvImporterTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\QuestionCsvImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionCsvImporterTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => '1',
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
            'bn_question_text' => '',
            'marks' => '2',
            'difficulty' => 'easy',
            'option_1' => '3',
            'option_2' => '4',
            'option_3' => '',
            'option_4' => '',
            'option_5' => '',
            'option_6' => '',
            'correct_options' => '2',
        ], $overrides);
    }

    public function test_parses_a_valid_mcq_single_row(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row());

        $this->assertSame(1, $parsed['question']['chapter_id']);
        $this->assertSame('mcq_single', $parsed['question']['question_type']);
        $this->assertSame(2, $parsed['question']['marks']);
        $this->assertSame('easy', $parsed['question']['difficulty']);
        $this->assertCount(2, $parsed['options']);
        $this->assertFalse($parsed['options'][0]['is_correct']);
        $this->assertTrue($parsed['options'][1]['is_correct']);
    }

    public function test_parses_a_valid_mcq_multiple_row_with_several_correct_options(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row([
            'question_type' => 'mcq_multiple',
            'option_3' => '5',
            'correct_options' => '2;3',
        ]));

        $this->assertCount(3, $parsed['options']);
        $this->assertTrue($parsed['options'][1]['is_correct']);
        $this->assertTrue($parsed['options'][2]['is_correct']);
    }

    public function test_defaults_marks_to_one_and_difficulty_to_medium_when_blank(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row(['marks' => '', 'difficulty' => '']));

        $this->assertSame(1, $parsed['question']['marks']);
        $this->assertSame('medium', $parsed['question']['difficulty']);
    }

    public static function invalidRows(): array
    {
        return [
            'unknown type' => [['question_type' => 'essay']],
            'blank question_text' => [['question_text' => '']],
            'no options at all' => [['option_1' => '', 'option_2' => '']],
            'correct_options blank' => [['correct_options' => '']],
            'correct_options references a blank option' => [['correct_options' => '5']],
            'true_false with 3 options' => [['question_type' => 'true_false', 'option_1' => 'True', 'option_2' => 'False', 'option_3' => 'Maybe', 'correct_options' => '1']],
            'mcq_single with 2 correct' => [['correct_options' => '1;2']],
            'unknown difficulty' => [['difficulty' => 'impossible']],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_rejects_structurally_invalid_rows(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QuestionCsvImporter::parseRow($this->row($overrides));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/QuestionCsvImporterTest.php`
Expected: FAIL — `App\Support\QuestionCsvImporter` does not exist.

- [ ] **Step 3: Create the support class**

`backend/app/Support/QuestionCsvImporter.php`:

```php
<?php

namespace App\Support;

use App\Models\Question;
use InvalidArgumentException;

class QuestionCsvImporter
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'chapter_id', 'question_type', 'question_text', 'bn_question_text',
        'marks', 'difficulty', 'option_1', 'option_2', 'option_3', 'option_4',
        'option_5', 'option_6', 'correct_options',
    ];

    private const TYPES = ['mcq_single', 'mcq_multiple', 'true_false'];

    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * Parse one CSV data row (an associative array keyed by HEADERS) into
     * Question/QuestionOption creation data. Throws InvalidArgumentException
     * with a human-readable message on any structural problem — the
     * caller is responsible for catching it and recording a per-row
     * error, never letting one bad row abort the whole import.
     *
     * @param  array<string, string>  $row
     * @return array{question: array<string, mixed>, options: list<array<string, mixed>>}
     */
    public static function parseRow(array $row): array
    {
        $type = trim($row['question_type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown question_type '{$type}'.");
        }

        if (trim($row['question_text'] ?? '') === '') {
            throw new InvalidArgumentException('question_text is required.');
        }

        $options = [];
        for ($i = 1; $i <= 6; $i++) {
            $text = trim($row["option_{$i}"] ?? '');
            if ($text !== '') {
                $options[$i] = $text;
            }
        }

        if ($options === []) {
            throw new InvalidArgumentException('At least one option is required.');
        }

        $correctIndexes = array_values(array_filter(
            array_map('trim', explode(';', (string) ($row['correct_options'] ?? ''))),
            fn ($value) => $value !== ''
        ));
        $correctIndexes = array_map('intval', $correctIndexes);

        if ($correctIndexes === []) {
            throw new InvalidArgumentException('correct_options must name at least one correct option.');
        }

        foreach ($correctIndexes as $index) {
            if (! array_key_exists($index, $options)) {
                throw new InvalidArgumentException("correct_options references option_{$index}, which is blank or missing.");
            }
        }

        if ($type === 'true_false' && count($options) !== 2) {
            throw new InvalidArgumentException('true_false questions must have exactly 2 options.');
        }

        if (in_array($type, ['mcq_single', 'true_false'], true) && count($correctIndexes) !== 1) {
            throw new InvalidArgumentException("{$type} questions must have exactly 1 correct option.");
        }

        if ($type === 'mcq_multiple' && count($options) < 2) {
            throw new InvalidArgumentException('mcq_multiple questions must have at least 2 options.');
        }

        $difficulty = trim($row['difficulty'] ?? '') ?: 'medium';

        if (! in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new InvalidArgumentException("Unknown difficulty '{$difficulty}'.");
        }

        $marksRaw = trim((string) ($row['marks'] ?? ''));
        $marks = $marksRaw === '' ? 1 : (int) $marksRaw;

        $optionRows = [];
        $orderIndex = 0;

        foreach ($options as $index => $text) {
            $optionRows[] = [
                'option_text' => $text,
                'bn_option_text' => null,
                'is_correct' => in_array($index, $correctIndexes, true),
                'order_index' => $orderIndex++,
            ];
        }

        return [
            'question' => [
                'chapter_id' => (int) ($row['chapter_id'] ?? 0),
                'question_type' => $type,
                'question_text' => $row['question_text'],
                'bn_question_text' => trim($row['bn_question_text'] ?? '') ?: null,
                'marks' => $marks,
                'difficulty' => $difficulty,
            ],
            'options' => $optionRows,
        ];
    }

    /**
     * The inverse of parseRow() — flattens a Question (with its options
     * relation already loaded, ordered by order_index) back into a CSV
     * row matching HEADERS exactly, so an exported file re-imports
     * cleanly. Used by QuestionController::export() (Task 6).
     *
     * @return list<string|int|null>
     */
    public static function toRow(Question $question): array
    {
        $columns = ['', '', '', '', '', ''];
        $correctIndexes = [];

        foreach ($question->options as $position => $option) {
            $columns[$position] = $option->option_text;

            if ($option->is_correct) {
                $correctIndexes[] = $position + 1;
            }
        }

        return [
            $question->chapter_id,
            $question->question_type,
            $question->question_text,
            $question->bn_question_text,
            $question->marks,
            $question->difficulty,
            ...$columns,
            implode(';', $correctIndexes),
        ];
    }
}
```

- [ ] **Step 4: Run the unit test**

Run: `php artisan test tests/Unit/QuestionCsvImporterTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Write the failing feature test** — create `backend/tests/Feature/QuestionImportExportTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionImportExportTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

    private School $schoolA;

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

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'questions').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'questions.csv', 'text/csv', null, true);
    }

    public function test_import_creates_valid_rows_and_reports_bad_rows_without_aborting(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_single,What is 2+2?,,1,easy,3,4,,,,,2\n"
            .'999999,mcq_single,Bad chapter,,1,easy,3,4,,,,,2'."\n"
            ."{$this->chapterA->id},essay,Unsupported type,,1,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 1);
        $response->assertJsonPath('failed', 2);
        $response->assertJsonPath('rows.0.status', 'imported');
        $response->assertJsonPath('rows.1.status', 'error');
        $response->assertJsonPath('rows.2.status', 'error');

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_import_cannot_target_a_chapter_outside_the_actors_tenant(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterB->id},mcq_single,Cross-tenant question,,1,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 0);
        $response->assertJsonPath('failed', 1);
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_import_is_forbidden_without_create_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n";

        $this->actingAs($denied, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertForbidden();
    }
}
```

- [ ] **Step 6: Run the feature test to verify it fails**

Run: `php artisan test tests/Feature/QuestionImportExportTest.php`
Expected: FAIL — route `/api/v1/questions/import` does not exist.

- [ ] **Step 7: Create the import request**

`backend/app/Http/Requests/Api/V1/ImportQuestionsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Question;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportQuestionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Question::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
```

- [ ] **Step 8: Add the controller action** — modify `backend/app/Http/Controllers/Api/V1/QuestionController.php`

Add imports:

```php
use App\Http\Requests\Api\V1\ImportQuestionsRequest;
use App\Support\QuestionCsvImporter;
use InvalidArgumentException;
```

Add the method after `reorder()`:

```php
    public function import(ImportQuestionsRequest $request): JsonResponse
    {
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $imported = 0;
        $rows = [];
        $rowNumber = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $row = array_combine($header, $line);

            try {
                $parsed = QuestionCsvImporter::parseRow($row);
                $chapter = Chapter::find($parsed['question']['chapter_id']);

                if (! $chapter) {
                    throw new InvalidArgumentException('chapter_id does not exist or is not accessible.');
                }

                $question = DB::transaction(function () use ($parsed, $chapter) {
                    $question = Question::create([
                        ...$parsed['question'],
                        'school_id' => $chapter->school_id,
                        'district_id' => $chapter->district_id,
                    ]);

                    foreach ($parsed['options'] as $option) {
                        $question->options()->create($option);
                    }

                    return $question;
                });

                $imported++;
                $rows[] = ['row' => $rowNumber, 'status' => 'imported', 'question_id' => $question->id];
            } catch (InvalidArgumentException $e) {
                $rows[] = ['row' => $rowNumber, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        fclose($handle);

        return response()->json([
            'imported' => $imported,
            'failed' => count($rows) - $imported,
            'rows' => $rows,
        ]);
    }
```

- [ ] **Step 9: Register the route** — modify `backend/routes/api.php`, adding next to the other `questions` mutating routes:

```php
        Route::patch('/questions/reorder', [QuestionController::class, 'reorder']);
        Route::post('/questions/import', [QuestionController::class, 'import']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 10: Run the tests**

Run: `php artisan test tests/Feature/QuestionImportExportTest.php`
Expected: PASS (3 tests).

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/QuestionCsvImporter.php app/Http/Requests/Api/V1/ImportQuestionsRequest.php app/Http/Controllers/Api/V1/QuestionController.php routes/api.php tests/Unit/QuestionCsvImporterTest.php tests/Feature/QuestionImportExportTest.php
git commit -m "Add synchronous CSV bulk import with a row-by-row error report"
```

---

### Task 6: Bulk CSV export

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/QuestionController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/QuestionImportExportTest.php`

**Interfaces:**
- Consumes: `QuestionCsvImporter::HEADERS`/`toRow()` (Task 5), `QuestionPolicy::viewAny` (Task 3).
- Produces: route `GET /api/v1/questions/export`; `QuestionController::export(Request $request): StreamedResponse`.

- [ ] **Step 1: Write the failing tests** — append to `backend/tests/Feature/QuestionImportExportTest.php` (inside the class, before the final closing brace)

```php
    public function test_export_produces_a_csv_that_reimports_cleanly(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_multiple,Which are even?,,3,hard,1,2,3,4,,,2;4\n";

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJsonPath('imported', 1);

        $viewer = $this->actorWithPermissions(['questions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $exported = $this->actingAs($viewer, 'sanctum')
            ->get("/api/v1/questions/export?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->streamedContent();

        $this->assertDatabaseCount('questions', 1);

        $reimported = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($exported)])
            ->assertOk();

        $reimported->assertJsonPath('imported', 1);
        $reimported->assertJsonPath('failed', 0);
        $this->assertDatabaseCount('questions', 2);
    }

    public function test_export_is_forbidden_without_view_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->get('/api/v1/questions/export')
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/QuestionImportExportTest.php --filter=export`
Expected: FAIL — route `/api/v1/questions/export` does not exist.

- [ ] **Step 3: Add the controller action** — modify `backend/app/Http/Controllers/Api/V1/QuestionController.php`

Add the import:

```php
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Add the method after `import()`:

```php
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Question::class);

        $questions = Question::query()
            ->with('options')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return response()->streamDownload(function () use ($questions) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, QuestionCsvImporter::HEADERS);

            foreach ($questions as $question) {
                fputcsv($handle, QuestionCsvImporter::toRow($question));
            }

            fclose($handle);
        }, 'questions-export.csv', ['Content-Type' => 'text/csv']);
    }
```

- [ ] **Step 4: Register the route** — modify `backend/routes/api.php`, adding next to the other `questions` mutating routes (it needs `auth:sanctum` like `import`, even though it only reads, because `questions.view` is not granted to unauthenticated callers):

```php
        Route::patch('/questions/reorder', [QuestionController::class, 'reorder']);
        Route::post('/questions/import', [QuestionController::class, 'import']);
        Route::get('/questions/export', [QuestionController::class, 'export']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/QuestionImportExportTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — no regressions in any existing test (Book/Chapter/ChapterVideo/ChapterMaterial/DatabaseSeeder suites included).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/QuestionController.php routes/api.php tests/Feature/QuestionImportExportTest.php
git commit -m "Add CSV export, round-trippable with import"
```
