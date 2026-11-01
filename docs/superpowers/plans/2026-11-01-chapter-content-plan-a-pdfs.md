# Book/Chapter PDF Management Implementation Plan (Plan A of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `Book` a single complete PDF and `Chapter` a type-flagged set of PDF materials (one primary + unlimited supplementary), both stored privately with signed temporary download/preview links and a basic access log.

**Architecture:** `Book`'s PDF is flat nullable columns on `books` (strict 1:1, no child table needed). `Chapter`'s PDFs use a new `chapter_materials` table (`type` enum `primary`/`supplementary`), tenant-scoped like `Book`/`Chapter` because it's resolved directly by route-model-binding. Both expose upload/replace, delete, archive-via-status, and signed download/preview endpoints following the exact Policy+FormRequest+Resource+Controller shape `BookController`/`ChapterController` already established. A new `content_access_logs` table records every download/preview.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests, Laravel's local private disk (`storage/app/private`) with `URL::temporarySignedRoute()` for signed links (the `Storage::temporaryUrl()` helper only works on cloud disks, not `local`).

**Spec:** `docs/superpowers/specs/2026-11-01-chapter-content-management-design.md`

## Global Constraints

- No `academic_year_id` anywhere — content stays year-independent (sub-project 1's decision, still binding).
- `chapter_materials` denormalizes `school_id`/`district_id` from its parent `Chapter` at creation time and uses the `TenantScoped` trait — confirmed necessary in the spec's self-review because it's resolved by direct route-model-binding, not only through `Chapter`'s own scoped relation.
- Both the book PDF and chapter materials are PDF-only: validate `mimes:pdf` and a configurable max size (`config('content.max_pdf_size_kb')`, default 50MB).
- Signed download/preview links expire after `config('content.signed_download_expiry_minutes')` (default 5 minutes) and use Laravel's `signed` middleware, not `auth:sanctum` — the signature itself is the authorization (it's only ever issued after a `books.view`/`chapter_materials.view` check at link-generation time).
- **Implementation-detail refinement from the spec** (does not change any functional requirement): the spec described `/pdf/archive` and `/pdf/unarchive` as two endpoints. This plan merges them into one `PATCH /books/{book}/pdf/status` accepting `{"status": "active"|"archived"}`, since archive/unarchive on `Book`'s single PDF has no other metadata to update — `chapter_materials`' archive/unarchive is already just a `status` field on its normal `update` endpoint. Both still satisfy "support replace, update, delete, and archive operations."
- `chapter_materials` has **no DB-level restore-on-recreate dance** (unlike `Book`/`Chapter`/`Subject`): there is no DB unique constraint on `(chapter_id, type)` for `primary` (MySQL has no partial unique index), so the "exactly one active primary per chapter" rule is enforced purely at the validation layer. A new `primary` upload after a prior one was soft-deleted is a plain insert, not a restore.
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `config/content.php` + `books` PDF columns + `Book` model updates

**Files:**
- Create: `backend/config/content.php`
- Create: `backend/database/migrations/2026_11_01_080000_add_pdf_columns_to_books_table.php`
- Modify: `backend/app/Models/Book.php`
- Test: `backend/tests/Unit/BookPdfTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `config('content.max_pdf_size_kb')`, `config('content.signed_download_expiry_minutes')`, `config('content.pdf_disk')`. `Book` gains fillable `pdf_original_filename`, `pdf_stored_path`, `pdf_disk`, `pdf_mime_type`, `pdf_size_bytes`, `pdf_uploaded_by_id`, `pdf_uploaded_at`, `pdf_status`; cast `pdf_uploaded_at` to `datetime`; method `hasAvailablePdf(): bool` (true only when `pdf_stored_path` is set AND `pdf_status === 'active'`). Later tasks rely on this exact method name and column set.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/BookPdfTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(): Book
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);

        return Book::create([
            'class_version_id' => $version->id,
            'subject_id' => $subject->id,
            'school_id' => $version->school_id,
            'district_id' => $version->district_id,
            'name' => 'Mathematics Book',
        ]);
    }

    public function test_book_has_no_available_pdf_by_default(): void
    {
        $book = $this->makeBook();

        $this->assertFalse($book->hasAvailablePdf());
    }

    public function test_book_has_available_pdf_once_uploaded_and_active(): void
    {
        $book = $this->makeBook();
        $book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/1/pdf/abc.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 1024,
            'pdf_status' => 'active',
        ]);

        $this->assertTrue($book->hasAvailablePdf());
    }

    public function test_book_does_not_have_available_pdf_once_archived(): void
    {
        $book = $this->makeBook();
        $book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/1/pdf/abc.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 1024,
            'pdf_status' => 'archived',
        ]);

        $this->assertFalse($book->hasAvailablePdf());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/BookPdfTest.php`
Expected: FAIL — `Book::hasAvailablePdf()` does not exist and the `pdf_*` columns don't exist yet.

- [ ] **Step 3: Create the config file** — `backend/config/content.php`

```php
<?php

return [
    /*
     * Maximum upload size, in kilobytes, for a book or chapter-material PDF.
     */
    'max_pdf_size_kb' => (int) env('MAX_PDF_SIZE_KB', 51200),

    /*
     * How long a signed download/preview link stays valid.
     */
    'signed_download_expiry_minutes' => (int) env('SIGNED_DOWNLOAD_EXPIRY_MINUTES', 5),

    /*
     * The filesystem disk PDFs are stored on — must be a private disk.
     */
    'pdf_disk' => env('CONTENT_PDF_DISK', 'local'),
];
```

- [ ] **Step 4: Create the migration** — `backend/database/migrations/2026_11_01_080000_add_pdf_columns_to_books_table.php`

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
     * A book has at most one complete PDF — flat nullable columns rather
     * than a child table, since there's no multiplicity to model (unlike
     * chapter_materials, which allows many supplementary files).
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('pdf_original_filename')->nullable();
            $table->string('pdf_stored_path')->nullable();
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_mime_type')->nullable();
            $table->unsignedBigInteger('pdf_size_bytes')->nullable();
            $table->foreignId('pdf_uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pdf_uploaded_at')->nullable();
            $table->string('pdf_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['pdf_uploaded_by_id']);
            $table->dropColumn([
                'pdf_original_filename',
                'pdf_stored_path',
                'pdf_disk',
                'pdf_mime_type',
                'pdf_size_bytes',
                'pdf_uploaded_by_id',
                'pdf_uploaded_at',
                'pdf_status',
            ]);
        });
    }
};
```

- [ ] **Step 5: Update the model** — in `backend/app/Models/Book.php`, add the new fillable columns, a cast, and the `hasAvailablePdf()` method:

```php
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
    'pdf_original_filename',
    'pdf_stored_path',
    'pdf_disk',
    'pdf_mime_type',
    'pdf_size_bytes',
    'pdf_uploaded_by_id',
    'pdf_uploaded_at',
    'pdf_status',
];

protected function casts(): array
{
    return [
        'is_active' => 'boolean',
        'pdf_uploaded_at' => 'datetime',
    ];
}

public function hasAvailablePdf(): bool
{
    return $this->pdf_stored_path !== null && $this->pdf_status === 'active';
}
```

(Add these alongside the existing `fillable`/`casts`/relations — don't remove anything already there.)

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Unit/BookPdfTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/config/content.php backend/database/migrations/2026_11_01_080000_add_pdf_columns_to_books_table.php backend/app/Models/Book.php backend/tests/Unit/BookPdfTest.php
git commit -m "Add PDF columns to Book and a content.php config for upload/signing limits"
```

---

### Task 2: `chapter_materials` table + `ChapterMaterial` model + `Chapter::materials()`

**Files:**
- Create: `backend/database/migrations/2026_11_01_080100_create_chapter_materials_table.php`
- Create: `backend/app/Models/ChapterMaterial.php`
- Modify: `backend/app/Models/Chapter.php`
- Test: `backend/tests/Unit/ChapterMaterialTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Chapter` (existing, `TenantScoped`), `App\Models\Concerns\TenantScoped`.
- Produces: `App\Models\ChapterMaterial` — fillable `chapter_id`, `school_id`, `district_id`, `type`, `title`, `original_filename`, `stored_path`, `disk`, `mime_type`, `size_bytes`, `uploaded_by_id`, `order_index`, `status`; relations `chapter(): BelongsTo`, `uploadedBy(): BelongsTo` (to `User`). `Chapter` gains `materials(): HasMany` (ordered by `order_index`). Task 3 (`ContentAccessLog`'s `chapter_material_id` foreign key) and Task 5 (the full CRUD API) build against this exact shape — this is why this task runs before Task 3, even though it was originally drafted after it.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ChapterMaterialTenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
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

class ChapterMaterialTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private ChapterMaterial $materialA;

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

        $chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);

        $this->materialA = ChapterMaterial::create([
            'chapter_id' => $chapterA->id,
            'school_id' => $chapterA->school_id,
            'district_id' => $chapterA->district_id,
            'type' => 'primary',
            'original_filename' => 'chapter1.pdf',
            'stored_path' => 'chapter-materials/1/abc.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        ChapterMaterial::create([
            'chapter_id' => $chapterB->id,
            'school_id' => $chapterB->school_id,
            'district_id' => $chapterB->district_id,
            'type' => 'primary',
            'original_filename' => 'chapter1.pdf',
            'stored_path' => 'chapter-materials/2/def.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_materials(): void
    {
        $role = Role::create(['name' => 'school_admin_materials', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = ChapterMaterial::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->materialA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_materials(): void
    {
        $role = Role::create(['name' => 'national_admin_materials', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, ChapterMaterial::all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ChapterMaterialTenantScopeTest.php`
Expected: FAIL — `App\Models\ChapterMaterial` does not exist.

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_080100_create_chapter_materials_table.php`

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
     * A chapter's PDF materials: exactly one non-archived 'primary' row
     * per chapter (enforced at the validation layer, not a DB
     * constraint — MySQL has no partial unique index), plus any number
     * of 'supplementary' rows.
     */
    public function up(): void
    {
        Schema::create('chapter_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time (never taken
            // directly from the request) — chapter_materials is resolved
            // by direct route-model-binding, so it needs its own
            // TenantScope filter, same as Book/Chapter.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title')->nullable();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('disk');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('order_index')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_materials');
    }
};
```

- [ ] **Step 4: Create the model** — `backend/app/Models/ChapterMaterial.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterMaterial extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'chapter_id',
        'school_id',
        'district_id',
        'type',
        'title',
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'uploaded_by_id',
        'order_index',
        'status',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
```

- [ ] **Step 5: Update the model** — in `backend/app/Models/Chapter.php`, add:

```php
public function materials(): HasMany
{
    return $this->hasMany(ChapterMaterial::class)->orderBy('order_index');
}
```

(Add the `use Illuminate\Database\Eloquent\Relations\HasMany;` import if `Chapter.php` doesn't already have it — check the existing imports first, since `Chapter` currently only imports `BelongsTo`.)

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Unit/ChapterMaterialTenantScopeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_080100_create_chapter_materials_table.php backend/app/Models/ChapterMaterial.php backend/app/Models/Chapter.php backend/tests/Unit/ChapterMaterialTenantScopeTest.php
git commit -m "Add ChapterMaterial model and table, tenant-scoped via Chapter"
```

---

### Task 3: `content_access_logs` table + `ContentAccessLog` model

**Files:**
- Create: `backend/database/migrations/2026_11_01_080200_create_content_access_logs_table.php`
- Create: `backend/app/Models/ContentAccessLog.php`
- Test: `backend/tests/Unit/ContentAccessLogTest.php`

**Interfaces:**
- Consumes: `App\Models\Book`, `App\Models\Chapter`, `App\Models\ChapterMaterial` (Task 2), `App\Models\User` (existing).
- Produces: `App\Models\ContentAccessLog` — fillable `book_id`, `chapter_id`, `chapter_material_id`, `user_id`, `action`; no `updated_at` column (immutable log). Tasks 4-5 create rows via `ContentAccessLog::create([...])`.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ContentAccessLogTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\ContentAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_log_row_can_be_created_with_only_a_book_id(): void
    {
        $log = ContentAccessLog::create([
            'book_id' => 1,
            'action' => 'downloaded',
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->chapter_id);
        $this->assertNull($log->chapter_material_id);
        $this->assertNull($log->user_id);
    }

    public function test_the_model_has_no_updated_at_column(): void
    {
        $log = ContentAccessLog::create(['book_id' => 1, 'action' => 'previewed']);

        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ContentAccessLogTest.php`
Expected: FAIL — table/class don't exist.

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_080200_create_content_access_logs_table.php`

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
     * An immutable log of every PDF download/preview. Exactly one of
     * book_id / chapter_material_id is set per row (whichever was
     * accessed); chapter_id rides along with chapter_material_id for
     * convenient chapter-scoped queries without a join. No polymorphic
     * relation — this codebase uses explicit nullable FKs throughout.
     * Runs after both books' PDF columns (Task 1) and chapter_materials
     * (Task 2), since chapter_material_id's foreign key needs that
     * table to already exist.
     */
    public function up(): void
    {
        Schema::create('content_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_material_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_access_logs');
    }
};
```

- [ ] **Step 4: Create the model** — `backend/app/Models/ContentAccessLog.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'book_id',
        'chapter_id',
        'chapter_material_id',
        'user_id',
        'action',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function chapterMaterial(): BelongsTo
    {
        return $this->belongsTo(ChapterMaterial::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Unit/ContentAccessLogTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_080200_create_content_access_logs_table.php backend/app/Models/ContentAccessLog.php backend/tests/Unit/ContentAccessLogTest.php
git commit -m "Add ContentAccessLog model and table for PDF download/preview tracking"
```

---

### Task 4: Book PDF API (upload, status, delete, signed download/preview)

**Files:**
- Create: `backend/app/Http/Requests/Api/V1/UploadBookPdfRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateBookPdfStatusRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Modify: `backend/app/Http/Resources/Api/V1/BookResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BookPdfControllerTest.php`

**Interfaces:**
- Consumes: `Book::hasAvailablePdf()` (Task 1), `App\Models\ContentAccessLog` (Task 3), `config('content.*')` (Task 1).
- Produces: `POST /api/v1/books/{book}/pdf`, `PATCH /api/v1/books/{book}/pdf/status`, `DELETE /api/v1/books/{book}/pdf`, `POST /api/v1/books/{book}/pdf/download-link`, `GET /api/v1/books/{book}/pdf/download` (named `books.pdf.download`), `GET /api/v1/books/{book}/pdf/preview` (named `books.pdf.preview`).

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/BookPdfControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class BookPdfControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $book;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);

        $this->book = Book::create([
            'class_version_id' => $version->id,
            'subject_id' => $subject->id,
            'school_id' => $version->school_id,
            'district_id' => $version->district_id,
            'name' => 'Mathematics Book',
        ]);
    }

    public function test_upload_requires_books_update_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');

        $this->actingAs($denied, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file])
            ->assertForbidden();
    }

    public function test_upload_stores_the_file_and_updates_metadata(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');

        $response = $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.pdf_original_filename', 'math.pdf')
            ->assertJsonPath('data.pdf_status', 'active');

        $this->book->refresh();
        $this->assertTrue($this->book->hasAvailablePdf());
        Storage::disk('local')->assertExists($this->book->pdf_stored_path);
    }

    public function test_upload_rejects_a_non_pdf_file(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.docx', 100, 'application/msword');

        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file])
            ->assertUnprocessable();
    }

    public function test_replacing_an_existing_pdf_deletes_the_old_file(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $first]);
        $this->book->refresh();
        $oldPath = $this->book->pdf_stored_path;

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $second]);

        Storage::disk('local')->assertMissing($oldPath);
        $this->book->refresh();
        $this->assertSame('v2.pdf', $this->book->pdf_original_filename);
    }

    public function test_status_endpoint_archives_and_unarchives(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/books/{$this->book->id}/pdf/status", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.pdf_status', 'archived');

        $this->book->refresh();
        $this->assertFalse($this->book->hasAvailablePdf());
    }

    public function test_delete_removes_the_file_and_clears_metadata(): void
    {
        $allowed = $this->actorWithPermissions(['books.update', 'books.delete'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);
        $this->book->refresh();
        $path = $this->book->pdf_stored_path;

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/books/{$this->book->id}/pdf")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->book->refresh();
        $this->assertNull($this->book->pdf_stored_path);
        $this->assertFalse($this->book->hasAvailablePdf());
    }

    public function test_download_link_requires_an_available_pdf(): void
    {
        $allowed = $this->actorWithPermissions(['books.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnprocessable();
    }

    public function test_download_link_returns_a_working_signed_url_that_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['books.update', 'books.view'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $url = $response->json('download_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();

        $this->assertSame(1, ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->count());
    }

    public function test_download_rejects_an_invalid_signature(): void
    {
        $this->get("/api/v1/books/{$this->book->id}/pdf/download?signature=invalid&expires=9999999999")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BookPdfControllerTest.php`
Expected: FAIL — none of these routes exist yet (404s).

- [ ] **Step 3: Create the requests**

`backend/app/Http/Requests/Api/V1/UploadBookPdfRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Book;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadBookPdfRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('book')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateBookPdfStatusRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Book;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookPdfStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('book')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['active', 'archived'])],
        ];
    }
}
```

(Note: `Book` import in `UploadBookPdfRequest`/`UpdateBookPdfStatusRequest` is unused if you only reference `$this->route('book')` — remove the `use App\Models\Book;` line from both if Pint/your IDE flags it as unused; the existing `StoreChapterRequest`/`UpdateChapterRequest` pattern in this codebase keeps such an import only when the class name is referenced directly, which it isn't here.)

- [ ] **Step 4: Update `BookResource`** — in `backend/app/Http/Resources/Api/V1/BookResource.php`, add the PDF fields to the returned array (keep every existing key):

```php
'pdf_original_filename' => $this->pdf_original_filename,
'pdf_mime_type' => $this->pdf_mime_type,
'pdf_size_bytes' => $this->pdf_size_bytes,
'pdf_uploaded_at' => $this->pdf_uploaded_at,
'pdf_status' => $this->pdf_status,
'has_available_pdf' => $this->hasAvailablePdf(),
```

(Deliberately omit `pdf_stored_path`, `pdf_disk`, and `pdf_uploaded_by_id` from the API response — the stored path is an internal storage detail, not something a client needs or should be able to guess at.)

- [ ] **Step 5: Add the controller actions** — in `backend/app/Http/Controllers/Api/V1/BookController.php`, add (keep every existing method):

```php
use App\Http\Requests\Api\V1\UpdateBookPdfStatusRequest;
use App\Http\Requests\Api\V1\UploadBookPdfRequest;
use App\Models\ContentAccessLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
```

```php
public function uploadPdf(UploadBookPdfRequest $request, Book $book): BookResource
{
    $this->authorize('update', $book);

    $disk = config('content.pdf_disk');
    $file = $request->file('pdf');

    if ($book->pdf_stored_path) {
        Storage::disk($book->pdf_disk ?? $disk)->delete($book->pdf_stored_path);
    }

    $path = $file->storeAs("books/{$book->id}/pdf", Str::uuid().'.pdf', $disk);

    $book->update([
        'pdf_original_filename' => $file->getClientOriginalName(),
        'pdf_stored_path' => $path,
        'pdf_disk' => $disk,
        'pdf_mime_type' => $file->getClientMimeType(),
        'pdf_size_bytes' => $file->getSize(),
        'pdf_uploaded_by_id' => $request->user()->id,
        'pdf_uploaded_at' => now(),
        'pdf_status' => 'active',
    ]);

    return new BookResource($book);
}

public function updatePdfStatus(UpdateBookPdfStatusRequest $request, Book $book): BookResource
{
    $this->authorize('update', $book);

    $book->update(['pdf_status' => $request->validated()['status']]);

    return new BookResource($book);
}

public function deletePdf(Book $book): Response
{
    $this->authorize('delete', $book);

    if ($book->pdf_stored_path) {
        Storage::disk($book->pdf_disk)->delete($book->pdf_stored_path);
    }

    $book->update([
        'pdf_original_filename' => null,
        'pdf_stored_path' => null,
        'pdf_disk' => null,
        'pdf_mime_type' => null,
        'pdf_size_bytes' => null,
        'pdf_uploaded_by_id' => null,
        'pdf_uploaded_at' => null,
        'pdf_status' => null,
    ]);

    return response()->noContent();
}

public function pdfDownloadLink(Request $request, Book $book): JsonResponse
{
    $this->authorize('view', $book);

    if (! $book->hasAvailablePdf()) {
        throw ValidationException::withMessages([
            'pdf' => ['This book has no available PDF to download.'],
        ]);
    }

    $url = URL::temporarySignedRoute(
        'books.pdf.download',
        now()->addMinutes(config('content.signed_download_expiry_minutes')),
        ['book' => $book->id]
    );

    return response()->json(['download_url' => $url]);
}

public function downloadPdf(Book $book): mixed
{
    if (! $book->hasAvailablePdf()) {
        abort(404);
    }

    ContentAccessLog::create(['book_id' => $book->id, 'action' => 'downloaded']);

    return Storage::disk($book->pdf_disk)->download($book->pdf_stored_path, $book->pdf_original_filename);
}

public function previewPdf(Book $book): mixed
{
    if (! $book->hasAvailablePdf()) {
        abort(404);
    }

    ContentAccessLog::create(['book_id' => $book->id, 'action' => 'previewed']);

    return Storage::disk($book->pdf_disk)->response($book->pdf_stored_path, $book->pdf_original_filename);
}
```

- [ ] **Step 6: Register routes** — in `backend/routes/api.php`:

Inside the `auth:sanctum` group, right after the existing `chapters` lines:

```php
Route::post('/books/{book}/pdf', [BookController::class, 'uploadPdf']);
Route::patch('/books/{book}/pdf/status', [BookController::class, 'updatePdfStatus']);
Route::delete('/books/{book}/pdf', [BookController::class, 'deletePdf']);
Route::post('/books/{book}/pdf/download-link', [BookController::class, 'pdfDownloadLink']);
```

Outside any auth group, using the `signed` middleware instead (add this new group right after the `auth:sanctum` group closes):

```php
Route::middleware('signed')->group(function (): void {
    Route::get('/books/{book}/pdf/download', [BookController::class, 'downloadPdf'])->name('books.pdf.download');
    Route::get('/books/{book}/pdf/preview', [BookController::class, 'previewPdf'])->name('books.pdf.preview');
});
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/BookPdfControllerTest.php`
Expected: PASS (9 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/UploadBookPdfRequest.php backend/app/Http/Requests/Api/V1/UpdateBookPdfStatusRequest.php backend/app/Http/Controllers/Api/V1/BookController.php backend/app/Http/Resources/Api/V1/BookResource.php backend/routes/api.php backend/tests/Feature/BookPdfControllerTest.php
git commit -m "Add Book PDF upload/status/delete/signed-download API"
```

---

### Task 5: `ChapterMaterial` CRUD API (upload, update/replace, delete, signed download/preview)

**Files:**
- Create: `backend/app/Policies/ChapterMaterialPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateChapterMaterialRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/ChapterMaterialResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Unit/ControllerAuthorizationConventionTest.php`
- Test: `backend/tests/Feature/ChapterMaterialControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\ChapterMaterial` (Task 2), `App\Models\Chapter`, `App\Models\ContentAccessLog` (Task 3), `config('content.*')` (Task 1).
- Produces: `GET/POST /api/v1/chapter-materials`, `GET/PATCH/DELETE /api/v1/chapter-materials/{chapter_material}`, `POST /api/v1/chapter-materials/{chapter_material}/download-link`, `GET /api/v1/chapter-materials/{chapter_material}/download` (named `chapter-materials.download`), `GET /api/v1/chapter-materials/{chapter_material}/preview` (named `chapter-materials.preview`). Permissions `chapter_materials.view/create/update/delete`.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ChapterMaterialControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterMaterialControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

    public function test_store_and_update_are_authorized(): void
    {
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $created = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$created}", ['order_index' => 2])
            ->assertOk();
    }

    public function test_only_one_active_primary_material_allowed_per_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertUnprocessable();
    }

    public function test_multiple_supplementary_materials_are_allowed(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('extra.pdf', 100, 'application/pdf');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 1', 'pdf' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 2', 'pdf' => $file])
            ->assertCreated();

        $this->assertSame(2, ChapterMaterial::where('chapter_id', $this->chapterA->id)->where('type', 'supplementary')->count());
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_material(): void
    {
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterB->id,
            'school_id' => $this->chapterB->school_id,
            'district_id' => $this->chapterB->district_id,
            'type' => 'primary',
            'original_filename' => 'chapter1.pdf',
            'stored_path' => 'chapter-materials/x/y.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $actor = $this->actorWithPermissions(['chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['order_index' => 1])
            ->assertNotFound();
    }

    public function test_update_can_replace_the_file(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $first])
            ->json('data.id');

        $material = ChapterMaterial::find($id);
        $oldPath = $material->stored_path;

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/chapter-materials/{$id}", ['_method' => 'PATCH', 'pdf' => $second])
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'v2.pdf');

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_destroy_deletes_the_physical_file(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $path = ChapterMaterial::find($id)->stored_path;

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/chapter-materials/{$id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_download_link_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $url = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$id}/download-link")
            ->assertOk()
            ->json('download_url');

        $this->get($url)->assertOk();

        $this->assertSame(1, ContentAccessLog::where('chapter_material_id', $id)->where('action', 'downloaded')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ChapterMaterialControllerTest.php`
Expected: FAIL — none of these routes/classes exist yet.

- [ ] **Step 3: Create the policy** — `backend/app/Policies/ChapterMaterialPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\ChapterMaterial;
use App\Models\User;

class ChapterMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    public function view(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chapter_materials.create');
    }

    public function update(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.update');
    }

    public function delete(User $user, ChapterMaterial $chapterMaterial): bool
    {
        return $user->hasPermission('chapter_materials.delete');
    }
}
```

- [ ] **Step 4: Create the requests**

`backend/app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Chapter;
use App\Models\ChapterMaterial;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChapterMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChapterMaterial::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'type' => ['required', 'string', Rule::in(['primary', 'supplementary'])],
            'title' => ['required_if:type,supplementary', 'nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }

    /**
     * Reject a second active 'primary' material for the same chapter.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== 'primary' || ! $this->input('chapter_id')) {
                return;
            }

            $chapter = Chapter::find($this->input('chapter_id'));

            if ($chapter && $chapter->materials()->where('type', 'primary')->where('status', 'active')->exists()) {
                $validator->errors()->add('type', 'This chapter already has an active primary material — update or archive it instead of creating a new one.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateChapterMaterialRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChapterMaterialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter_material')) ?? false;
    }

    /**
     * chapter_id and type are immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'order_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived'])],
            'pdf' => ['sometimes', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }
}
```

- [ ] **Step 5: Create the resource** — `backend/app/Http/Resources/Api/V1/ChapterMaterialResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'type' => $this->type,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'order_index' => $this->order_index,
            'status' => $this->status,
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
```

(Same deliberate omission as `BookResource`: no `stored_path`/`disk`/`uploaded_by_id` in the response.)

- [ ] **Step 6: Create the controller** — `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChapterMaterialRequest;
use App\Http\Requests\Api\V1\UpdateChapterMaterialRequest;
use App\Http\Resources\Api\V1\ChapterMaterialResource;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ContentAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChapterMaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $materials = ChapterMaterial::query()
            ->with('chapter')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->orderBy('chapter_id')
            ->orderBy('type')
            ->orderBy('order_index')
            ->get();

        return ChapterMaterialResource::collection($materials);
    }

    public function show(ChapterMaterial $chapter_material): ChapterMaterialResource
    {
        return new ChapterMaterialResource($chapter_material->load('chapter'));
    }

    public function store(StoreChapterMaterialRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterMaterial::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $disk = config('content.pdf_disk');
        $file = $request->file('pdf');
        $path = $file->storeAs("chapter-materials/{$chapter->id}", Str::uuid().'.pdf', $disk);

        $material = ChapterMaterial::create([
            'chapter_id' => $chapter->id,
            'school_id' => $chapter->school_id,
            'district_id' => $chapter->district_id,
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'order_index' => $request->input('order_index'),
        ]);

        return (new ChapterMaterialResource($material->load('chapter')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateChapterMaterialRequest $request, ChapterMaterial $chapter_material): ChapterMaterialResource
    {
        $this->authorize('update', $chapter_material);

        $data = $request->validated();

        if ($request->hasFile('pdf')) {
            Storage::disk($chapter_material->disk)->delete($chapter_material->stored_path);

            $file = $request->file('pdf');
            $path = $file->storeAs("chapter-materials/{$chapter_material->chapter_id}", Str::uuid().'.pdf', $chapter_material->disk);

            $data['original_filename'] = $file->getClientOriginalName();
            $data['stored_path'] = $path;
            $data['mime_type'] = $file->getClientMimeType();
            $data['size_bytes'] = $file->getSize();
        }

        $chapter_material->update($data);

        return new ChapterMaterialResource($chapter_material->load('chapter'));
    }

    public function destroy(ChapterMaterial $chapter_material): Response
    {
        $this->authorize('delete', $chapter_material);

        Storage::disk($chapter_material->disk)->delete($chapter_material->stored_path);
        $chapter_material->delete();

        return response()->noContent();
    }

    public function downloadLink(ChapterMaterial $chapter_material): JsonResponse
    {
        $this->authorize('view', $chapter_material);

        if ($chapter_material->status !== 'active') {
            throw ValidationException::withMessages([
                'material' => ['This material is archived and cannot be downloaded.'],
            ]);
        }

        $url = URL::temporarySignedRoute(
            'chapter-materials.download',
            now()->addMinutes(config('content.signed_download_expiry_minutes')),
            ['chapter_material' => $chapter_material->id]
        );

        return response()->json(['download_url' => $url]);
    }

    public function download(ChapterMaterial $chapter_material): mixed
    {
        ContentAccessLog::create([
            'chapter_id' => $chapter_material->chapter_id,
            'chapter_material_id' => $chapter_material->id,
            'action' => 'downloaded',
        ]);

        return Storage::disk($chapter_material->disk)->download($chapter_material->stored_path, $chapter_material->original_filename);
    }

    public function preview(ChapterMaterial $chapter_material): mixed
    {
        ContentAccessLog::create([
            'chapter_id' => $chapter_material->chapter_id,
            'chapter_material_id' => $chapter_material->id,
            'action' => 'previewed',
        ]);

        return Storage::disk($chapter_material->disk)->response($chapter_material->stored_path, $chapter_material->original_filename);
    }
}
```

- [ ] **Step 7: Register routes** — in `backend/routes/api.php`:

Add the import `use App\Http\Controllers\Api\V1\ChapterMaterialController;` alphabetically (between `ChapterController` and `ClassController`).

Public group (right after the `books`/`chapters` lines):

```php
Route::apiResource('chapter-materials', ChapterMaterialController::class)->only(['index', 'show']);
```

`auth:sanctum` group (right after the `books`/`chapters` lines, and right after Task 4's new Book-PDF lines):

```php
Route::apiResource('chapter-materials', ChapterMaterialController::class)->only(['store', 'update', 'destroy']);
Route::post('/chapter-materials/{chapter_material}/download-link', [ChapterMaterialController::class, 'downloadLink']);
```

`signed`-middleware group (added in Task 4 — add these two lines alongside the Book PDF ones already there):

```php
Route::get('/chapter-materials/{chapter_material}/download', [ChapterMaterialController::class, 'download'])->name('chapter-materials.download');
Route::get('/chapter-materials/{chapter_material}/preview', [ChapterMaterialController::class, 'preview'])->name('chapter-materials.preview');
```

- [ ] **Step 8: Seed permissions** — in `backend/database/seeders/PermissionSeeder.php`, insert right after the `books.*` block:

```php
            ['name' => 'chapter_materials.view', 'label' => 'View chapter materials'],
            ['name' => 'chapter_materials.create', 'label' => 'Create chapter materials'],
            ['name' => 'chapter_materials.update', 'label' => 'Update chapter materials'],
            ['name' => 'chapter_materials.delete', 'label' => 'Delete chapter materials'],

```

- [ ] **Step 9: Map roles** — in `backend/database/seeders/RolePermissionSeeder.php`, add `'chapter_materials.view'` immediately before every existing `'books.view'` entry (same roles the `books.view` edit touched: `district_officer`, `school_admin`, `teacher`, `student`, `office_staff`, `parent_guardian`, `it_admin`, `auditor`). For `trainer` (which has `'books.view', 'books.create', 'books.update',`), add `'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update',` immediately before it. `national_admin` needs no edit.

- [ ] **Step 10: Add to the authorization convention test** — in `backend/tests/Unit/ControllerAuthorizationConventionTest.php`, add the import `use App\Http\Controllers\Api\V1\ChapterMaterialController;` right after the `BookController` import, and add this block to `mutatingActions()` right after the `BookController` entries and before the `ChapterController` entries:

```php
            [ChapterMaterialController::class, 'store'],
            [ChapterMaterialController::class, 'update'],
            [ChapterMaterialController::class, 'destroy'],

```

- [ ] **Step 11: Run the tests**

Run: `php artisan test tests/Feature/ChapterMaterialControllerTest.php tests/Unit/ControllerAuthorizationConventionTest.php`
Expected: PASS (7 feature tests + all convention-test cases, including the 3 new ones).

- [ ] **Step 12: Run the full backend suite**

Run: `php artisan test`
Expected: PASS — every test in `backend/tests/`, old and new.

- [ ] **Step 13: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Policies/ChapterMaterialPolicy.php backend/app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php backend/app/Http/Requests/Api/V1/UpdateChapterMaterialRequest.php backend/app/Http/Resources/Api/V1/ChapterMaterialResource.php backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php backend/routes/api.php backend/database/seeders/PermissionSeeder.php backend/database/seeders/RolePermissionSeeder.php backend/tests/Unit/ControllerAuthorizationConventionTest.php backend/tests/Feature/ChapterMaterialControllerTest.php
git commit -m "Add ChapterMaterial CRUD API: policy, requests, resource, controller, routes, permissions"
```

---

## Out of scope for this plan

Frontend upload/preview UI — see the spec's "Frontend correction." Plan B (`docs/superpowers/plans/2026-11-01-chapter-content-plan-b-videos.md`) covers YouTube videos and progress tracking; it is independent of this plan's schema (no shared tables) but shares the `config/content.php` file this plan creates.
