# Content Hierarchy Extension (Book Model + Chapter Restructure) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a `Book` model between `Subject` and `Chapter` so the hierarchy becomes `ClassVersion → Subject → Book → Chapter`, with `Book`/`Chapter` tenant-scoped like `ClassVersion`.

**Architecture:** `Book` gets its own table (`class_version_id`, `subject_id`, denormalized `school_id`/`district_id`, `TenantScoped`) following the exact `ClassVersion`/`Chapter`/`Subject` CRUD conventions already in this codebase (FormRequest + Policy + Resource + Controller, soft-delete-restore-on-recreate, `ControllerAuthorizationConventionTest` coverage). `Chapter` moves from `belongsTo(Subject)` to `belongsTo(Book)`, gaining the same tenant-scoping. `Subject` loses its now-invalid `chapters()` relation in favor of `books()`.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests (existing project convention).

**Spec:** `docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`

## Global Constraints

- `Book`/`Chapter` content is **year-independent** — no `academic_year_id` anywhere in this plan.
- A `(class_version_id, subject_id)` pair may have **multiple** `Book`s — never add a `unique(class_version_id, subject_id)` constraint; only `unique(class_version_id, subject_id, name)`.
- No production data exists yet for the `chapters` table (Phase 1 foundation) — this is a clean structural migration, not a data migration. Do not write any data-backfill code.
- Follow this repo's additive-migration convention: never edit `2026_10_28_125244_create_chapters_table.php` directly; add new migrations instead.
- `Book` and `Chapter` both denormalize `school_id`/`district_id` from their parent at creation time, never from the request — same convention as `ClassVersion`/`AcademicYear`.
- After every PHP file change, run `vendor/bin/pint --dirty --format agent` before considering a task done (project convention from `backend/CLAUDE.md`).
- All commands below run from the `backend/` directory.

---

### Task 1: `books` table + `Book` model

**Files:**
- Create: `backend/database/migrations/2026_11_01_070000_create_books_table.php`
- Create: `backend/app/Models/Book.php`
- Test: `backend/tests/Unit/BookTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\TenantScoped` (existing trait), `App\Models\ClassVersion`, `App\Models\Subject`, `App\Models\Chapter` (existing models).
- Produces: `App\Models\Book` — fillable `class_version_id`, `subject_id`, `school_id`, `district_id`, `name`, `bn_name`, `order_index`, `description`, `is_active`; relations `classVersion(): BelongsTo`, `subject(): BelongsTo`, `chapters(): HasMany` (ordered by `order_index`). Later tasks rely on this exact shape.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/BookTenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BookTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Book $bookA;

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

        $this->bookA = Book::create([
            'class_version_id' => $versionA->id,
            'subject_id' => $subject->id,
            'school_id' => $versionA->school_id,
            'district_id' => $versionA->district_id,
            'name' => 'Mathematics Book',
        ]);

        Book::create([
            'class_version_id' => $versionB->id,
            'subject_id' => $subject->id,
            'school_id' => $versionB->school_id,
            'district_id' => $versionB->district_id,
            'name' => 'Mathematics Book',
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_books(): void
    {
        $role = Role::create(['name' => 'school_admin_books', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Book::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->bookA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_books(): void
    {
        $role = Role::create(['name' => 'national_admin_books', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Book::all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/BookTenantScopeTest.php`
Expected: FAIL — class `App\Models\Book` does not exist.

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_070000_create_books_table.php`

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
     * A book sits between Subject and Chapter in the content hierarchy
     * (ClassVersion -> Subject -> Book -> Chapter). Multiple books are
     * allowed per (class_version_id, subject_id) — see
     * docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md.
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            // Denormalized from the class version's school/district at
            // creation time (never taken directly from the request) so
            // TenantScope can filter/compare without a join — same
            // convention as ClassVersion/AcademicYear.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->unsignedSmallInteger('order_index')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_version_id', 'subject_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
```

- [ ] **Step 4: Create the model** — `backend/app/Models/Book.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'class_version_id',
        'subject_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'order_index',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order_index');
    }
}
```

- [ ] **Step 5: Run migrations and the test**

Run: `php artisan migrate --env=testing` is not needed (RefreshDatabase runs migrations per-test); just run:
`php artisan test tests/Unit/BookTenantScopeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_070000_create_books_table.php backend/app/Models/Book.php backend/tests/Unit/BookTenantScopeTest.php
git commit -m "Add Book model and books table, tenant-scoped via ClassVersion"
```

---

### Task 2: `chapters` table restructure (`subject_id` → `book_id`) + `Chapter` model

**Files:**
- Create: `backend/database/migrations/2026_11_01_070100_add_book_id_to_chapters_table.php`
- Modify: `backend/app/Models/Chapter.php`
- Modify: `backend/tests/Feature/ReferenceDataAuthorizationTest.php` (remove the now-invalid chapter case — see Step 5)
- Test: `backend/tests/Unit/ChapterTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Book` (Task 1).
- Produces: `App\Models\Chapter` — fillable `book_id`, `school_id`, `district_id`, `name`, `bn_name`, `order_index`, `chapter_number`, `bn_chapter_number`, `description`; relation `book(): BelongsTo`. The `subject_id` column/relation no longer exists. Later tasks rely on this exact shape.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ChapterTenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ChapterTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Chapter $chapterA;

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
        Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_chapters(): void
    {
        $role = Role::create(['name' => 'school_admin_chapters', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Chapter::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->chapterA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_chapters(): void
    {
        $role = Role::create(['name' => 'national_admin_chapters', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Chapter::all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ChapterTenantScopeTest.php`
Expected: FAIL — `Book::create(['subject_id' => ...])` in setUp works, but `Chapter::create(['book_id' => ...])` fails because the `chapters` table has no `book_id` column yet (and still requires `subject_id`).

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_070100_add_book_id_to_chapters_table.php`

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
     * Chapter moves from belonging directly to Subject to belonging to
     * Book (Subject -> Book -> Chapter), and becomes tenant-scoped via
     * the same denormalized school_id/district_id convention as Book.
     */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique(['subject_id', 'name']);
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');

            $table->foreignId('book_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->after('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();

            $table->unique(['book_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique(['book_id', 'name']);
            $table->dropForeign(['book_id']);
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['book_id', 'school_id', 'district_id']);

            $table->foreignId('subject_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['subject_id', 'name']);
        });
    }
};
```

- [ ] **Step 4: Update the model** — replace `backend/app/Models/Chapter.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'book_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'order_index',
        'chapter_number',
        'bn_chapter_number',
        'description',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
```

- [ ] **Step 5: Remove the now-invalid chapter case from `ReferenceDataAuthorizationTest`**

`backend/tests/Feature/ReferenceDataAuthorizationTest.php`'s `test_chapter_store_and_update_are_authorized` creates a `Chapter` with `subject_id` and posts a `subject_id` payload to `/api/v1/chapters` — both now fail (`Chapter` has no `subject_id` column/fillable any more, so the direct `Chapter::create()` in the test either drops the key silently or, since `book_id`/`school_id`/`district_id` are `NOT NULL` with no default, throws a database constraint error). `ChapterController` itself isn't fixed until Task 4, so this case cannot be repaired here — only removed, so the suite doesn't stay red between this task and Task 4.

Delete the `test_chapter_store_and_update_are_authorized` method entirely from `ReferenceDataAuthorizationTest.php`, and remove the now-unused `use App\Models\Chapter;` import (keep `use App\Models\Subject;` — still used by `test_subject_store_and_update_are_authorized`). Full chapter CRUD coverage is rebuilt as `ChapterControllerTest` in Task 4.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Unit/ChapterTenantScopeTest.php tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (2 unit tests; reference-data tests pass with the chapter case removed).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_070100_add_book_id_to_chapters_table.php backend/app/Models/Chapter.php backend/tests/Feature/ReferenceDataAuthorizationTest.php backend/tests/Unit/ChapterTenantScopeTest.php
git commit -m "Move Chapter from Subject to Book and make it tenant-scoped"
```

**Note:** this intentionally leaves `backend/app/Models/Subject.php` (`chapters()` relation), `backend/app/Http/Controllers/Api/V1/SubjectController.php`, `backend/app/Http/Resources/Api/V1/SubjectResource.php`, `backend/app/Http/Controllers/Api/V1/ChapterController.php`, and its Request/Resource classes still referencing the old `subject_id` shape — they are fixed in Tasks 4 and 5. `/api/v1/chapters` and `/api/v1/subjects/{id}` will not work correctly between this task and Task 4/5 (no test exercises them in that window, since the one that did was just removed above); that is expected mid-plan state, not a regression to fix here.

---

### Task 3: `BookController` + policy + requests + resource + routes + permissions

**Files:**
- Create: `backend/app/Policies/BookPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreBookRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateBookRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/BookResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Unit/ControllerAuthorizationConventionTest.php`
- Test: `backend/tests/Feature/BookControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Book` (Task 1), `App\Models\ClassVersion`, `App\Models\Subject`, `Tests\Concerns\CreatesAuthorizedActors`.
- Produces: `GET/POST /api/v1/books`, `GET/PATCH/DELETE /api/v1/books/{book}`; permissions `books.view`/`books.create`/`books.update`/`books.delete`. `App\Http\Resources\Api\V1\BookResource` (consumed by `ChapterResource` in Task 4).

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/BookControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

    private School $schoolA;

    private School $schoolB;

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
    }

    public function test_store_and_update_are_authorized(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Existing Book',
        ]);
        $payload = ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'New Book'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/books', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertOk();
    }

    public function test_multiple_books_allowed_per_class_version_and_subject(): void
    {
        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Practice Book'])
            ->assertCreated();

        $this->assertSame(2, Book::where('class_version_id', $this->versionA->id)->where('subject_id', $this->subject->id)->count());
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_book(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionB->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionB->school_id,
            'district_id' => $this->versionB->district_id,
            'name' => 'School B Book',
        ]);

        $actor = $this->actorWithPermissions(['books.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_store_restores_a_soft_deleted_book_with_the_same_identity(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Mathematics',
        ]);
        $book->delete();

        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated()
            ->assertJsonPath('data.id', $book->id);
    }

    public function test_destroy_is_blocked_while_the_book_still_has_chapters(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Mathematics',
        ]);
        $book->chapters()->create([
            'name' => 'Chapter 1',
            'school_id' => $book->school_id,
            'district_id' => $book->district_id,
        ]);

        $actor = $this->actorWithPermissions(['books.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}")
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BookControllerTest.php`
Expected: FAIL — route `/api/v1/books` does not exist (404 instead of the expected status codes).

- [ ] **Step 3: Create the policy** — `backend/app/Policies/BookPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

class BookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('books.view');
    }

    public function view(User $user, Book $book): bool
    {
        return $user->hasPermission('books.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('books.create');
    }

    public function update(User $user, Book $book): bool
    {
        return $user->hasPermission('books.update');
    }

    public function delete(User $user, Book $book): bool
    {
        return $user->hasPermission('books.delete');
    }
}
```

`Book` → `BookPolicy` matches Laravel's auto-discovery naming convention (same as `Chapter` → `ChapterPolicy`), so no `Gate::policy()` registration in `AppServiceProvider` is needed (only `SchoolClass` needs that, because "Class" can't be a class name).

- [ ] **Step 4: Create the requests** — `backend/app/Http/Requests/Api/V1/StoreBookRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Book;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('books', 'name')->withoutTrashed()
                    ->where('class_version_id', $this->integer('class_version_id'))
                    ->where('subject_id', $this->integer('subject_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateBookRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('book')) ?? false;
    }

    /**
     * class_version_id and subject_id are immutable after creation —
     * moving a book to a different version/subject isn't supported by
     * this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $book = $this->route('book');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('books', 'name')->ignore($book)
                    ->where('class_version_id', $book->class_version_id)
                    ->where('subject_id', $book->subject_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 5: Create the resource** — `backend/app/Http/Resources/Api/V1/BookResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_version_id' => $this->class_version_id,
            'subject_id' => $this->subject_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'order_index' => $this->order_index,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'chapters' => ChapterResource::collection($this->whenLoaded('chapters')),
        ];
    }
}
```

- [ ] **Step 6: Create the controller** — `backend/app/Http/Controllers/Api/V1/BookController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookRequest;
use App\Http\Requests\Api\V1\UpdateBookRequest;
use App\Http\Resources\Api\V1\BookResource;
use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $books = Book::query()
            ->with(['classVersion', 'subject'])
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->orderBy('class_version_id')
            ->orderBy('subject_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return BookResource::collection($books);
    }

    /**
     * Display the specified resource.
     */
    public function show(Book $book): BookResource
    {
        return new BookResource($book->load(['classVersion', 'subject']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted book with the same (class_version_id, subject_id,
     * name) is restored and updated in place, rather than attempting a
     * second insert that would collide with the still-present unique
     * index on a trashed row.
     */
    public function store(StoreBookRequest $request): JsonResponse
    {
        $this->authorize('create', Book::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
        Subject::findOrFail($request->integer('subject_id'));

        $data = $request->validated();
        $data['school_id'] = $classVersion->school_id;
        $data['district_id'] = $classVersion->district_id;

        $book = Book::onlyTrashed()
            ->where('class_version_id', $data['class_version_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('name', $data['name'])
            ->first();

        if ($book) {
            $book->restore();
            $book->update($data);
        } else {
            $book = Book::create($data);
        }

        return (new BookResource($book->load(['classVersion', 'subject'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBookRequest $request, Book $book): BookResource
    {
        $this->authorize('update', $book);

        $book->update($request->validated());

        return new BookResource($book->load(['classVersion', 'subject']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book): Response
    {
        $this->authorize('delete', $book);

        if ($book->chapters()->exists()) {
            throw ValidationException::withMessages([
                'book' => ['This book still has chapters and cannot be deleted.'],
            ]);
        }

        $book->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Register routes** — in `backend/routes/api.php`, add the import `use App\Http\Controllers\Api\V1\BookController;` right after the `use App\Http\Controllers\Api\V1\AuthController;` line (and before `ChapterController`'s import). Then add these two lines:

In the public group (right before the `chapters` line):

```php
    Route::apiResource('books', BookController::class)->only(['index', 'show']);
```

In the `auth:sanctum` group (right before the `chapters` line):

```php
        Route::apiResource('books', BookController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 8: Seed permissions** — in `backend/database/seeders/PermissionSeeder.php`, insert this block right after the `subjects.*` block and before the `chapters.*` block:

```php
            ['name' => 'books.view', 'label' => 'View books'],
            ['name' => 'books.create', 'label' => 'Create books'],
            ['name' => 'books.update', 'label' => 'Update books'],
            ['name' => 'books.delete', 'label' => 'Delete books'],

```

- [ ] **Step 9: Map roles to the new permissions** — in `backend/database/seeders/RolePermissionSeeder.php`, add `'books.view'` immediately before every existing `'chapters.view'` entry, for every role that has one (`district_officer`, `school_admin`, `teacher`, `student`, `office_staff`, `parent_guardian`, `it_admin`, `auditor`). For the `trainer` role, which has `'chapters.view', 'chapters.create', 'chapters.update',`, add `'books.view', 'books.create', 'books.update',` immediately before it. `national_admin` needs no edit (`$allPermissions` already includes every seeded permission).

- [ ] **Step 10: Add Book to the authorization convention test** — in `backend/tests/Unit/ControllerAuthorizationConventionTest.php`, add the import `use App\Http\Controllers\Api\V1\BookController;` right after the `AcademicYearController` import, and add this block to `mutatingActions()` right after the `AcademicYearController` entries and before the `ChapterController` entries:

```php
            [BookController::class, 'store'],
            [BookController::class, 'update'],
            [BookController::class, 'destroy'],

```

- [ ] **Step 11: Run the tests**

Run: `php artisan test tests/Feature/BookControllerTest.php tests/Unit/ControllerAuthorizationConventionTest.php`
Expected: PASS (5 feature tests + all convention-test cases, including the 3 new Book ones).

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Policies/BookPolicy.php backend/app/Http/Requests/Api/V1/StoreBookRequest.php backend/app/Http/Requests/Api/V1/UpdateBookRequest.php backend/app/Http/Resources/Api/V1/BookResource.php backend/app/Http/Controllers/Api/V1/BookController.php backend/routes/api.php backend/database/seeders/PermissionSeeder.php backend/database/seeders/RolePermissionSeeder.php backend/tests/Unit/ControllerAuthorizationConventionTest.php backend/tests/Feature/BookControllerTest.php
git commit -m "Add Book CRUD API: policy, requests, resource, controller, routes, permissions"
```

---

### Task 4: Update the `Chapter` API stack to key off `book_id`

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterController.php`
- Modify: `backend/app/Http/Requests/Api/V1/StoreChapterRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/UpdateChapterRequest.php`
- Modify: `backend/app/Http/Resources/Api/V1/ChapterResource.php`
- Test: `backend/tests/Feature/ChapterControllerTest.php` (new — replaces the chapter case removed from `ReferenceDataAuthorizationTest` in Task 2 with full coverage)

**Interfaces:**
- Consumes: `App\Models\Book`, `App\Http\Resources\Api\V1\BookResource` (Task 3), `App\Support\Ordinal` (existing).
- Produces: `GET/POST /api/v1/chapters` (now filterable by `?book_id=`), `GET/PATCH/DELETE /api/v1/chapters/{chapter}`, all keyed off `book_id` instead of `subject_id`.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ChapterControllerTest.php`

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
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $bookA;

    private Book $bookB;

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

        $this->bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $this->bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->school_id, 'district_id' => $this->bookA->district_id, 'name' => 'Existing Chapter']);
        $payload = ['book_id' => $this->bookA->id, 'name' => 'New Chapter'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertOk();
    }

    public function test_index_filters_by_book_id(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->school_id, 'district_id' => $this->bookA->district_id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $this->bookB->id, 'school_id' => $this->bookB->school_id, 'district_id' => $this->bookB->district_id, 'name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['chapters.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/chapters?book_id={$this->bookA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_chapter(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookB->id, 'school_id' => $this->bookB->school_id, 'district_id' => $this->bookB->district_id, 'name' => 'School B Chapter']);

        $actor = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_store_restores_a_soft_deleted_chapter_with_the_same_identity(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'school_id' => $this->bookA->school_id, 'district_id' => $this->bookA->district_id, 'name' => 'Chapter 1']);
        $chapter->delete();

        $allowed = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookA->id, 'name' => 'Chapter 1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $chapter->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ChapterControllerTest.php`
Expected: FAIL — `StoreChapterRequest` still requires `subject_id`, so posting `book_id` fails validation (422 instead of 201/200).

- [ ] **Step 3: Update the controller** — replace `backend/app/Http/Controllers/Api/V1/ChapterController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChapterRequest;
use App\Http\Requests\Api\V1\UpdateChapterRequest;
use App\Http\Resources\Api\V1\ChapterResource;
use App\Models\Book;
use App\Models\Chapter;
use App\Support\Ordinal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ChapterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $chapters = Chapter::query()
            ->with('book')
            ->when($request->integer('book_id'), fn ($query, $bookId) => $query->where('book_id', $bookId))
            ->orderBy('book_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return ChapterResource::collection($chapters);
    }

    /**
     * Display the specified resource.
     */
    public function show(Chapter $chapter): ChapterResource
    {
        return new ChapterResource($chapter->load('book'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted chapter with the same (book_id, name) is restored
     * and updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed row.
     */
    public function store(StoreChapterRequest $request): JsonResponse
    {
        $this->authorize('create', Chapter::class);

        $book = Book::findOrFail($request->integer('book_id'));
        $data = $this->applyAutoNumbering($request->validated());
        $data['school_id'] = $book->school_id;
        $data['district_id'] = $book->district_id;

        $chapter = Chapter::onlyTrashed()
            ->where('book_id', $book->id)
            ->where('name', $data['name'])
            ->first();

        if ($chapter) {
            $chapter->restore();
            $chapter->update($data);
        } else {
            $chapter = Chapter::create($data);
        }

        return (new ChapterResource($chapter->load('book')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateChapterRequest $request, Chapter $chapter): ChapterResource
    {
        $this->authorize('update', $chapter);

        $chapter->update($this->applyAutoNumbering($request->validated()));

        return new ChapterResource($chapter->load('book'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Chapter $chapter): Response
    {
        $this->authorize('delete', $chapter);

        $chapter->delete();

        return response()->noContent();
    }

    /**
     * Fill chapter_number/bn_chapter_number from order_index when the
     * caller gave an order_index but left the ordinal labels unset.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyAutoNumbering(array $data): array
    {
        if (($data['order_index'] ?? null) !== null) {
            $data['chapter_number'] ??= Ordinal::englishChapter($data['order_index']);
            $data['bn_chapter_number'] ??= Ordinal::banglaChapter($data['order_index']);
        }

        return $data;
    }
}
```

- [ ] **Step 4: Update the requests** — replace `backend/app/Http/Requests/Api/V1/StoreChapterRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Chapter::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'book_id' => ['required', 'integer', Rule::exists('books', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('chapters', 'name')->withoutTrashed()->where('book_id', $this->integer('book_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'chapter_number' => ['nullable', 'string', 'max:50'],
            'bn_chapter_number' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

Replace `backend/app/Http/Requests/Api/V1/UpdateChapterRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter')) ?? false;
    }

    /**
     * book_id is immutable after creation — moving a chapter to a
     * different book isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $chapter = $this->route('chapter');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('chapters', 'name')->ignore($chapter)->where('book_id', $chapter->book_id),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'chapter_number' => ['nullable', 'string', 'max:50'],
            'bn_chapter_number' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 5: Update the resource** — replace `backend/app/Http/Resources/Api/V1/ChapterResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'order_index' => $this->order_index,
            'chapter_number' => $this->chapter_number,
            'bn_chapter_number' => $this->bn_chapter_number,
            'description' => $this->description,
            'book' => new BookResource($this->whenLoaded('book')),
        ];
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/ChapterControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Controllers/Api/V1/ChapterController.php backend/app/Http/Requests/Api/V1/StoreChapterRequest.php backend/app/Http/Requests/Api/V1/UpdateChapterRequest.php backend/app/Http/Resources/Api/V1/ChapterResource.php backend/tests/Feature/ChapterControllerTest.php
git commit -m "Key the Chapter API off book_id instead of subject_id"
```

---

### Task 5: `Subject` cleanup (`chapters()` → `books()`)

**Files:**
- Modify: `backend/app/Models/Subject.php`
- Modify: `backend/app/Http/Controllers/Api/V1/SubjectController.php`
- Modify: `backend/app/Http/Resources/Api/V1/SubjectResource.php`
- Test: `backend/tests/Feature/SubjectBooksRelationTest.php`

**Interfaces:**
- Consumes: `App\Models\Book` (Task 1).
- Produces: `Subject::books(): HasMany` (replaces the removed `Subject::chapters()`); `SubjectController::destroy()` now blocks on books instead of chapters; `SubjectResource` no longer exposes a `chapters` key.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/SubjectBooksRelationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SubjectBooksRelationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_subject_destroy_is_blocked_while_it_still_has_books(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);

        $subject = Subject::create(['name' => 'Mathematics']);
        Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id, 'name' => 'Mathematics Book']);

        $actor = $this->actorWithPermissions(['subjects.delete'], null, null);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/subjects/{$subject->id}")
            ->assertUnprocessable();
    }

    public function test_subject_show_no_longer_exposes_a_chapters_key(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);

        $response = $this->getJson("/api/v1/subjects/{$subject->id}");

        $response->assertOk()->assertJsonMissingPath('data.chapters');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SubjectBooksRelationTest.php`
Expected: FAIL — `Subject::books()` does not exist yet, so the `Book::create()` call in the first test errors, and `SubjectController::show()` still loads `chapters` (so the second test's `assertJsonMissingPath` fails).

- [ ] **Step 3: Update the model** — replace `backend/app/Models/Subject.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'bn_name',
        'code',
        'description',
    ];

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subjects', 'subject_id', 'class_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
```

- [ ] **Step 4: Update the controller** — in `backend/app/Http/Controllers/Api/V1/SubjectController.php`, change `show()`:

```php
    public function show(Subject $subject): SubjectResource
    {
        return new SubjectResource($subject);
    }
```

and change the `chapters` check in `destroy()`:

```php
        if ($subject->books()->exists()) {
            throw ValidationException::withMessages([
                'subject' => ['This subject still has books and cannot be deleted.'],
            ]);
        }
```

(replacing the existing `if ($subject->chapters()->exists())` block; leave the preceding `classes()->exists()` check untouched.)

- [ ] **Step 5: Update the resource** — replace `backend/app/Http/Resources/Api/V1/SubjectResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'code' => $this->code,
            'description' => $this->description,
        ];
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/SubjectBooksRelationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full backend test suite**

Run: `php artisan test`
Expected: PASS — every test in `backend/tests/`, including all tests added/modified in Tasks 1-5 and every pre-existing test (e.g. `TenantScopeLeakageTest`, `ReferenceDataAuthorizationTest`, `ControllerAuthorizationConventionTest`).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Models/Subject.php backend/app/Http/Controllers/Api/V1/SubjectController.php backend/app/Http/Resources/Api/V1/SubjectResource.php backend/tests/Feature/SubjectBooksRelationTest.php
git commit -m "Replace Subject::chapters() with Subject::books()"
```

---

## Out of scope for this plan

This plan is backend-API-only (corrected in the spec's "Frontend" section after planning found `frontend/apps/management/src` has no existing Subject/Chapter admin screen to extend — only a placeholder dashboard). Book/Chapter CRUD screens are deferred to the separate Management Portal sub-project already decomposed in `docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md`.
