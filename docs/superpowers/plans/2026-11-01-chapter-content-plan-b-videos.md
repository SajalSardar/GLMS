# YouTube Video Management Implementation Plan (Plan B of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `Chapter` an ordered set of YouTube tutorial videos with metadata, enable/disable, and per-student progress tracking derived from a configurable percentage-of-duration completion threshold.

**Architecture:** A `YoutubeUrl` support class (same role as the existing `App\Support\Ordinal`) extracts/validates video IDs from four URL forms and generates embed URLs. `chapter_videos` is tenant-scoped like `chapter_materials` (Plan A) — resolved by direct route-model-binding, so it denormalizes `school_id`/`district_id` from `Chapter`. `chapter_video_progress` tracks a per-`(video, user)` high-water-mark position, never a naive sum, and derives `status` by comparing that position against the video's (or a global default) completion threshold.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-11-01-chapter-content-management-design.md`

## Global Constraints

- No `academic_year_id` anywhere — content stays year-independent.
- `chapter_videos` denormalizes `school_id`/`district_id` from its parent `Chapter` at creation time and uses `TenantScoped` — same reasoning as `chapter_materials` in Plan A (resolved by direct route-model-binding, not only through `Chapter`'s scoped relation).
- No calls to YouTube's Data API anywhere — `duration_seconds` is admin-entered or backfilled from the first progress report that includes one.
- `max_position_seconds` on `chapter_video_progress` is a high-water mark: a progress report with a lower position than what's already stored **never decreases it**, and `status` never reverts from `completed` back to `in_progress`/`not_started` once set.
- **Implementation-detail refinement from the spec** (does not change any functional requirement): the spec described dedicated `PATCH .../enable` and `.../disable` endpoints. This plan folds enable/disable into the normal `update` endpoint's `is_enabled` boolean field instead — same reasoning as Plan A's book-PDF archive/unarchive consolidation (one flag, no second code path). "Reorder" keeps its own dedicated endpoint because it mutates multiple rows' `order_index` atomically from one ordered ID list, which a per-resource `update` cannot do.
- Progress reporting (`PUT /chapter-videos/{chapter_video}/progress`) needs no new permission — any authenticated user reports their own progress, scoped strictly by `$request->user()->id`; the controller never accepts a different user's ID from the request body.
- This codebase's route-model-binding convention for multi-word resources is **snake_case** (`$chapter_video`, `{chapter_video}`), confirmed against `ClassVersionController`/`SubjectTeacherAssignmentController` and already used by Plan A's `ChapterMaterialController`. Follow it exactly — a camelCase/snake_case mismatch silently breaks implicit binding.
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `App\Support\YoutubeUrl` support class

**Files:**
- Create: `backend/app/Support/YoutubeUrl.php`
- Test: `backend/tests/Unit/YoutubeUrlTest.php`

**Interfaces:**
- Consumes: nothing (pure utility, no DB).
- Produces: `App\Support\YoutubeUrl::extractVideoId(string $url): ?string`, `App\Support\YoutubeUrl::isValidVideoId(string $videoId): bool`, `App\Support\YoutubeUrl::embedUrl(string $videoId): string`. Task 4's `StoreChapterVideoRequest`/`UpdateChapterVideoRequest`/`ChapterVideoController` consume `extractVideoId()` and `embedUrl()` by these exact names.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/YoutubeUrlTest.php`

```php
<?php

namespace Tests\Unit;

use App\Support\YoutubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YoutubeUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validUrls(): array
    {
        return [
            'watch?v=' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch?v= with trailing params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', 'dQw4w9WgXcQ'],
            'youtu.be short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'youtu.be with query' => ['https://youtu.be/dQw4w9WgXcQ?si=abc123', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'no scheme' => ['youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_extract_video_id_recognizes_every_supported_url_form(string $url, string $expectedId): void
    {
        $this->assertSame($expectedId, YoutubeUrl::extractVideoId($url));
    }

    public function test_extract_video_id_returns_null_for_an_unrecognized_url(): void
    {
        $this->assertNull(YoutubeUrl::extractVideoId('https://vimeo.com/12345678'));
    }

    public function test_extract_video_id_returns_null_for_a_truncated_id(): void
    {
        $this->assertNull(YoutubeUrl::extractVideoId('https://youtu.be/short'));
    }

    public function test_is_valid_video_id_accepts_eleven_characters(): void
    {
        $this->assertTrue(YoutubeUrl::isValidVideoId('dQw4w9WgXcQ'));
    }

    public function test_is_valid_video_id_rejects_wrong_length(): void
    {
        $this->assertFalse(YoutubeUrl::isValidVideoId('short'));
    }

    public function test_embed_url_builds_the_expected_url(): void
    {
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0', YoutubeUrl::embedUrl('dQw4w9WgXcQ'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/YoutubeUrlTest.php`
Expected: FAIL — `App\Support\YoutubeUrl` does not exist.

- [ ] **Step 3: Create the class** — `backend/app/Support/YoutubeUrl.php`

```php
<?php

namespace App\Support;

class YoutubeUrl
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Extract an 11-character YouTube video ID from any of the four
     * supported URL forms (watch?v=, youtu.be/, embed/, shorts/). Does
     * not require a scheme. Returns null for anything else, including a
     * recognized host with a too-short or malformed ID.
     */
    public static function extractVideoId(string $url): ?string
    {
        $patterns = [
            '/youtube\.com\/watch\?v=([A-Za-z0-9_-]{11})/',
            '/youtu\.be\/([A-Za-z0-9_-]{11})/',
            '/youtube\.com\/embed\/([A-Za-z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([A-Za-z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public static function isValidVideoId(string $videoId): bool
    {
        return preg_match(self::ID_PATTERN, $videoId) === 1;
    }

    /**
     * The embeddable player URL for a video ID, with rel=0 so YouTube
     * doesn't surface "recommended" videos from other channels at the
     * end of playback.
     */
    public static function embedUrl(string $videoId): string
    {
        return "https://www.youtube.com/embed/{$videoId}?rel=0";
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Unit/YoutubeUrlTest.php`
Expected: PASS (11 tests across the data provider + the other 5 methods).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Support/YoutubeUrl.php backend/tests/Unit/YoutubeUrlTest.php
git commit -m "Add YoutubeUrl support class for video ID extraction and embed URLs"
```

---

### Task 2: `chapter_videos` table + `ChapterVideo` model + `Chapter::videos()`

**Files:**
- Create: `backend/database/migrations/2026_11_01_090000_create_chapter_videos_table.php`
- Create: `backend/app/Models/ChapterVideo.php`
- Modify: `backend/app/Models/Chapter.php`
- Test: `backend/tests/Unit/ChapterVideoTenantScopeTest.php`

**Interfaces:**
- Consumes: `App\Models\Chapter` (existing, `TenantScoped`).
- Produces: `App\Models\ChapterVideo` — fillable `chapter_id`, `school_id`, `district_id`, `youtube_video_id`, `title`, `bn_title`, `description`, `instructor_name`, `duration_seconds`, `learning_objectives`, `completion_threshold_percent`, `order_index`, `is_enabled`; cast `is_enabled` to `boolean`; relation `chapter(): BelongsTo`. `Chapter` gains `videos(): HasMany` (ordered by `order_index`). Tasks 3-4 build against this exact shape.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ChapterVideoTenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
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

class ChapterVideoTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private ChapterVideo $videoA;

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

        $this->videoA = ChapterVideo::create([
            'chapter_id' => $chapterA->id,
            'school_id' => $chapterA->school_id,
            'district_id' => $chapterA->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
            'order_index' => 1,
        ]);

        ChapterVideo::create([
            'chapter_id' => $chapterB->id,
            'school_id' => $chapterB->school_id,
            'district_id' => $chapterB->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
            'order_index' => 1,
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_videos(): void
    {
        $role = Role::create(['name' => 'school_admin_videos', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = ChapterVideo::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->videoA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_videos(): void
    {
        $role = Role::create(['name' => 'national_admin_videos', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, ChapterVideo::all());
    }

    public function test_is_enabled_defaults_to_true(): void
    {
        $this->assertTrue($this->videoA->is_enabled);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ChapterVideoTenantScopeTest.php`
Expected: FAIL — `App\Models\ChapterVideo` does not exist.

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_090000_create_chapter_videos_table.php`

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
        Schema::create('chapter_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time — chapter_videos
            // is resolved by direct route-model-binding, so it needs its own
            // TenantScope filter, same as Book/Chapter/ChapterMaterial.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id', 11);
            $table->string('title');
            $table->string('bn_title')->nullable();
            $table->text('description')->nullable();
            $table->string('instructor_name')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->unsignedTinyInteger('completion_threshold_percent')->nullable();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_videos');
    }
};
```

- [ ] **Step 4: Create the model** — `backend/app/Models/ChapterVideo.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterVideo extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'chapter_id',
        'school_id',
        'district_id',
        'youtube_video_id',
        'title',
        'bn_title',
        'description',
        'instructor_name',
        'duration_seconds',
        'learning_objectives',
        'completion_threshold_percent',
        'order_index',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(ChapterVideoProgress::class);
    }
}
```

- [ ] **Step 5: Update the model** — in `backend/app/Models/Chapter.php`, add:

```php
public function videos(): HasMany
{
    return $this->hasMany(ChapterVideo::class)->orderBy('order_index');
}
```

(`HasMany` is already imported in `Chapter.php` from Plan A's `materials()` relation — no new import needed.)

- [ ] **Step 6: Run the test**

Run: `php artisan test tests/Unit/ChapterVideoTenantScopeTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_090000_create_chapter_videos_table.php backend/app/Models/ChapterVideo.php backend/app/Models/Chapter.php backend/tests/Unit/ChapterVideoTenantScopeTest.php
git commit -m "Add ChapterVideo model and table, tenant-scoped via Chapter"
```

---

### Task 3: `chapter_video_progress` table + `ChapterVideoProgress` model

**Files:**
- Create: `backend/database/migrations/2026_11_01_090100_create_chapter_video_progress_table.php`
- Create: `backend/app/Models/ChapterVideoProgress.php`
- Test: `backend/tests/Unit/ChapterVideoProgressModelTest.php`

**Interfaces:**
- Consumes: `App\Models\ChapterVideo` (Task 2), `App\Models\User` (existing).
- Produces: `App\Models\ChapterVideoProgress` — fillable `chapter_video_id`, `user_id`, `status`, `max_position_seconds`, `started_at`, `completed_at`; unique on `(chapter_video_id, user_id)`; relations `chapterVideo(): BelongsTo`, `user(): BelongsTo`. Task 5 builds the progress-reporting endpoint against this exact shape.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/ChapterVideoProgressModelTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterVideoProgressModelTest extends TestCase
{
    use RefreshDatabase;

    private ChapterVideo $video;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $this->video = ChapterVideo::create([
            'chapter_id' => $chapter->id,
            'school_id' => $chapter->school_id,
            'district_id' => $chapter->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
        ]);

        $role = Role::create(['name' => 'student_role', 'label' => 'Student']);
        $this->student = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_progress_row_defaults_to_not_started_and_zero_position(): void
    {
        $progress = ChapterVideoProgress::create([
            'chapter_video_id' => $this->video->id,
            'user_id' => $this->student->id,
        ]);

        $this->assertSame('not_started', $progress->status);
        $this->assertSame(0, $progress->max_position_seconds);
    }

    public function test_only_one_progress_row_per_video_and_user(): void
    {
        ChapterVideoProgress::create(['chapter_video_id' => $this->video->id, 'user_id' => $this->student->id]);

        $this->expectException(QueryException::class);

        ChapterVideoProgress::create(['chapter_video_id' => $this->video->id, 'user_id' => $this->student->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ChapterVideoProgressModelTest.php`
Expected: FAIL — `App\Models\ChapterVideoProgress` does not exist.

- [ ] **Step 3: Create the migration** — `backend/database/migrations/2026_11_01_090100_create_chapter_video_progress_table.php`

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
     * max_position_seconds is a high-water mark (the furthest playback
     * position ever reported), not a sum of watched segments — see
     * this plan's Global Constraints for why.
     */
    public function up(): void
    {
        Schema::create('chapter_video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_started');
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['chapter_video_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_video_progress');
    }
};
```

- [ ] **Step 4: Create the model** — `backend/app/Models/ChapterVideoProgress.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChapterVideoProgress extends Model
{
    protected $fillable = [
        'chapter_video_id',
        'user_id',
        'status',
        'max_position_seconds',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'max_position_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function chapterVideo(): BelongsTo
    {
        return $this->belongsTo(ChapterVideo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Unit/ChapterVideoProgressModelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_01_090100_create_chapter_video_progress_table.php backend/app/Models/ChapterVideoProgress.php backend/tests/Unit/ChapterVideoProgressModelTest.php
git commit -m "Add ChapterVideoProgress model and table"
```

---

### Task 4: `ChapterVideo` CRUD API (create, update, delete, reorder)

**Files:**
- Create: `backend/app/Policies/ChapterVideoPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreChapterVideoRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateChapterVideoRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/ReorderChapterVideosRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/ChapterVideoResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Unit/ControllerAuthorizationConventionTest.php`
- Test: `backend/tests/Feature/ChapterVideoControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\ChapterVideo` (Task 2), `App\Support\YoutubeUrl` (Task 1).
- Produces: `GET/POST /api/v1/chapter-videos`, `GET/PATCH/DELETE /api/v1/chapter-videos/{chapter_video}`, `PATCH /api/v1/chapter-videos/reorder`. Permissions `chapter_videos.view/create/update/delete`.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ChapterVideoControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
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

class ChapterVideoControllerTest extends TestCase
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

    public function test_store_and_update_are_authorized(): void
    {
        $payload = ['chapter_id' => $this->chapterA->id, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Intro'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapter-videos', $payload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapter_videos.create', 'chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);
        $id = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapter-videos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.youtube_video_id', 'dQw4w9WgXcQ')
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$id}", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_store_rejects_an_unrecognized_youtube_url(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapter-videos', ['chapter_id' => $this->chapterA->id, 'youtube_url' => 'https://vimeo.com/123', 'title' => 'Intro'])
            ->assertUnprocessable();
    }

    public function test_actor_with_permission_gets_404_updating_another_schools_video(): void
    {
        $video = ChapterVideo::create([
            'chapter_id' => $this->chapterB->id,
            'school_id' => $this->chapterB->school_id,
            'district_id' => $this->chapterB->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'School B video',
        ]);

        $actor = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$video->id}", ['title' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_reorder_sets_order_index_from_the_given_sequence(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.create', 'chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $first = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'First', 'order_index' => 0]);
        $second = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Second', 'order_index' => 1]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/chapter-videos/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$second->id, $first->id]])
            ->assertOk();

        $this->assertSame(0, $second->refresh()->order_index);
        $this->assertSame(1, $first->refresh()->order_index);
    }

    public function test_reorder_rejects_an_id_from_a_different_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $foreign = ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'school_id' => $this->chapterB->school_id, 'district_id' => $this->chapterB->district_id, 'youtube_video_id' => 'ccccccccccc', 'title' => 'Foreign']);
        $own = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id, 'youtube_video_id' => 'ddddddddddd', 'title' => 'Own']);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson('/api/v1/chapter-videos/reorder', ['chapter_id' => $this->chapterA->id, 'ordered_ids' => [$own->id, $foreign->id]])
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ChapterVideoControllerTest.php`
Expected: FAIL — none of these routes/classes exist yet.

- [ ] **Step 3: Create the policy** — `backend/app/Policies/ChapterVideoPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\ChapterVideo;
use App\Models\User;

class ChapterVideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('chapter_videos.view');
    }

    public function view(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('chapter_videos.create');
    }

    public function update(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.update');
    }

    /**
     * Reordering spans multiple rows identified only by a chapter_id in
     * the request body — there is no single ChapterVideo instance to
     * check against, so this is a no-instance ability (same shape as
     * create()), not a reuse of update(). Calling
     * $user->can('update', ChapterVideo::class) instead would pass only
     * $user to update(), which requires a second typed ChapterVideo
     * parameter with no default — an ArgumentCountError, not a graceful
     * denial.
     */
    public function reorder(User $user): bool
    {
        return $user->hasPermission('chapter_videos.update');
    }

    public function delete(User $user, ChapterVideo $chapterVideo): bool
    {
        return $user->hasPermission('chapter_videos.delete');
    }
}
```

- [ ] **Step 4: Create the requests**

`backend/app/Http/Requests/Api/V1/StoreChapterVideoRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ChapterVideo;
use App\Support\YoutubeUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChapterVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChapterVideo::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'youtube_url' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'bn_title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructor_name' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'learning_objectives' => ['nullable', 'string', 'max:2000'],
            'completion_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reject a youtube_url that doesn't resolve to a valid video ID.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $url = $this->input('youtube_url');

            if ($url && YoutubeUrl::extractVideoId($url) === null) {
                $validator->errors()->add('youtube_url', 'This is not a recognized YouTube video URL.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateChapterVideoRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Support\YoutubeUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateChapterVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter_video')) ?? false;
    }

    /**
     * chapter_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'youtube_url' => ['sometimes', 'string'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'instructor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'learning_objectives' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completion_threshold_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'order_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reject a youtube_url that doesn't resolve to a valid video ID.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $url = $this->input('youtube_url');

            if ($url && YoutubeUrl::extractVideoId($url) === null) {
                $validator->errors()->add('youtube_url', 'This is not a recognized YouTube video URL.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/ReorderChapterVideosRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ChapterVideo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderChapterVideosRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', ChapterVideo::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', Rule::exists('chapter_videos', 'id')->withoutTrashed()],
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

            $matching = ChapterVideo::where('chapter_id', $chapterId)->whereIn('id', $ids)->count();

            if ($matching !== count($ids)) {
                $validator->errors()->add('ordered_ids', 'One or more video IDs do not belong to the given chapter.');
            }
        });
    }
}
```

(`ChapterVideo` → `ChapterVideoPolicy`, like `Book`/`Chapter`/`ChapterMaterial`, matches Laravel's auto-discovery convention — no `Gate::policy()` registration needed. `ReorderChapterVideosRequest::authorize()` checks the dedicated `reorder` ability against the `ChapterVideo` class — not `update`, and not an instance, since reordering spans multiple rows with no single model to bind against. See `ChapterVideoPolicy::reorder()` in Step 3 above for why a no-instance `update` check would actually throw.)

- [ ] **Step 5: Create the resource** — `backend/app/Http/Resources/Api/V1/ChapterVideoResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Support\YoutubeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterVideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'youtube_video_id' => $this->youtube_video_id,
            'embed_url' => YoutubeUrl::embedUrl($this->youtube_video_id),
            'title' => $this->title,
            'bn_title' => $this->bn_title,
            'description' => $this->description,
            'instructor_name' => $this->instructor_name,
            'duration_seconds' => $this->duration_seconds,
            'learning_objectives' => $this->learning_objectives,
            'completion_threshold_percent' => $this->completion_threshold_percent,
            'order_index' => $this->order_index,
            'is_enabled' => $this->is_enabled,
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
```

- [ ] **Step 6: Create the controller** — `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderChapterVideosRequest;
use App\Http\Requests\Api\V1\StoreChapterVideoRequest;
use App\Http\Requests\Api\V1\UpdateChapterVideoRequest;
use App\Http\Resources\Api\V1\ChapterVideoResource;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ChapterVideoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $videos = ChapterVideo::query()
            ->with('chapter')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return ChapterVideoResource::collection($videos);
    }

    public function show(ChapterVideo $chapter_video): ChapterVideoResource
    {
        return new ChapterVideoResource($chapter_video->load('chapter'));
    }

    public function store(StoreChapterVideoRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterVideo::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->validated();
        $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
        unset($data['youtube_url']);
        $data['school_id'] = $chapter->school_id;
        $data['district_id'] = $chapter->district_id;

        $video = ChapterVideo::create($data);

        return (new ChapterVideoResource($video->load('chapter')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateChapterVideoRequest $request, ChapterVideo $chapter_video): ChapterVideoResource
    {
        $this->authorize('update', $chapter_video);

        $data = $request->validated();

        if (array_key_exists('youtube_url', $data)) {
            $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
            unset($data['youtube_url']);
        }

        $chapter_video->update($data);

        return new ChapterVideoResource($chapter_video->load('chapter'));
    }

    public function destroy(ChapterVideo $chapter_video): Response
    {
        $this->authorize('delete', $chapter_video);

        $chapter_video->delete();

        return response()->noContent();
    }

    public function reorder(ReorderChapterVideosRequest $request): JsonResponse
    {
        $this->authorize('reorder', ChapterVideo::class);

        DB::transaction(function () use ($request) {
            foreach ($request->validated()['ordered_ids'] as $index => $id) {
                ChapterVideo::where('id', $id)->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }
}
```

- [ ] **Step 7: Register routes** — in `backend/routes/api.php`:

Add the import `use App\Http\Controllers\Api\V1\ChapterVideoController;` alphabetically (between `ChapterMaterialController` and `ClassController` — `ChapterMaterialController`'s import was added by Plan A).

Public group (right after Plan A's `chapter-materials` line):

```php
Route::apiResource('chapter-videos', ChapterVideoController::class)->only(['index', 'show']);
```

`auth:sanctum` group (right after Plan A's `chapter-materials` lines). **Order matters**: `/chapter-videos/reorder` is a literal path competing with `/chapter-videos/{chapter_video}` (the `update`/`destroy` routes `apiResource` registers) — Laravel matches routes in registration order, and `{chapter_video}` would greedily capture the literal string `"reorder"` as an ID if the `apiResource` line came first, so the explicit `reorder` route must be registered BEFORE it:

```php
Route::patch('/chapter-videos/reorder', [ChapterVideoController::class, 'reorder']);
Route::apiResource('chapter-videos', ChapterVideoController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 8: Seed permissions** — in `backend/database/seeders/PermissionSeeder.php`, insert right after the `chapter_materials.*` block (added by Plan A):

```php
            ['name' => 'chapter_videos.view', 'label' => 'View chapter videos'],
            ['name' => 'chapter_videos.create', 'label' => 'Create chapter videos'],
            ['name' => 'chapter_videos.update', 'label' => 'Update chapter videos'],
            ['name' => 'chapter_videos.delete', 'label' => 'Delete chapter videos'],

```

- [ ] **Step 9: Map roles** — in `backend/database/seeders/RolePermissionSeeder.php`, add `'chapter_videos.view'` immediately before every existing `'chapter_materials.view'` entry (added by Plan A, same roles). For `trainer` (which now has `'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update',`), add `'chapter_videos.view', 'chapter_videos.create', 'chapter_videos.update',` immediately before it. `national_admin` needs no edit.

- [ ] **Step 10: Add to the authorization convention test** — in `backend/tests/Unit/ControllerAuthorizationConventionTest.php`, add the import `use App\Http\Controllers\Api\V1\ChapterVideoController;` right after the `ChapterMaterialController` import, and add this block to `mutatingActions()` right after the `ChapterMaterialController` entries and before the `ChapterController` entries:

```php
            [ChapterVideoController::class, 'store'],
            [ChapterVideoController::class, 'update'],
            [ChapterVideoController::class, 'destroy'],
            [ChapterVideoController::class, 'reorder'],

```

- [ ] **Step 11: Run the tests**

Run: `php artisan test tests/Feature/ChapterVideoControllerTest.php tests/Unit/ControllerAuthorizationConventionTest.php`
Expected: PASS (5 feature tests + all convention-test cases, including the 4 new ones).

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Policies/ChapterVideoPolicy.php backend/app/Http/Requests/Api/V1/StoreChapterVideoRequest.php backend/app/Http/Requests/Api/V1/UpdateChapterVideoRequest.php backend/app/Http/Requests/Api/V1/ReorderChapterVideosRequest.php backend/app/Http/Resources/Api/V1/ChapterVideoResource.php backend/app/Http/Controllers/Api/V1/ChapterVideoController.php backend/routes/api.php backend/database/seeders/PermissionSeeder.php backend/database/seeders/RolePermissionSeeder.php backend/tests/Unit/ControllerAuthorizationConventionTest.php backend/tests/Feature/ChapterVideoControllerTest.php
git commit -m "Add ChapterVideo CRUD API: policy, requests, resource, controller, routes, permissions"
```

---

### Task 5: Video progress reporting (`PUT /chapter-videos/{chapter_video}/progress`)

**Files:**
- Modify: `backend/config/content.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/ChapterVideoProgressResource.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ChapterVideoProgressControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\ChapterVideoProgress` (Task 3), `App\Models\ChapterVideo` (Task 2), `config('content.video_completion_threshold_percent')` (this task adds that key).
- Produces: `PUT /api/v1/chapter-videos/{chapter_video}/progress`, scoped to the authenticated user.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ChapterVideoProgressControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
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
use Tests\TestCase;

class ChapterVideoProgressControllerTest extends TestCase
{
    use RefreshDatabase;

    private ChapterVideo $video;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $this->video = ChapterVideo::create([
            'chapter_id' => $chapter->id,
            'school_id' => $chapter->school_id,
            'district_id' => $chapter->district_id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'title' => 'Intro video',
            'duration_seconds' => 100,
        ]);

        $role = Role::create(['name' => 'student_role', 'label' => 'Student']);
        $this->student = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_first_report_creates_an_in_progress_record(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.max_position_seconds', 10);

        $progress = ChapterVideoProgress::where('chapter_video_id', $this->video->id)->where('user_id', $this->student->id)->first();
        $this->assertNotNull($progress->started_at);
    }

    public function test_crossing_the_default_threshold_marks_completed(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 95])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $progress = ChapterVideoProgress::where('chapter_video_id', $this->video->id)->where('user_id', $this->student->id)->first();
        $this->assertNotNull($progress->completed_at);
    }

    public function test_a_lower_position_never_decreases_the_high_water_mark(): void
    {
        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 80]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 20])
            ->assertOk()
            ->assertJsonPath('data.max_position_seconds', 80);
    }

    public function test_status_never_reverts_from_completed(): void
    {
        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 95]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 5])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_custom_per_video_threshold_overrides_the_default(): void
    {
        $this->video->update(['completion_threshold_percent' => 50]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 55])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_a_reported_duration_backfills_a_missing_duration(): void
    {
        $this->video->update(['duration_seconds' => null]);

        $this->actingAs($this->student, 'sanctum')
            ->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10, 'duration_seconds' => 200])
            ->assertOk();

        $this->assertSame(200, $this->video->refresh()->duration_seconds);
    }

    public function test_progress_is_scoped_to_the_authenticated_user_only(): void
    {
        $otherRole = Role::create(['name' => 'other_student', 'label' => 'Student']);
        $other = User::factory()->create(['role_id' => $otherRole->id]);

        $this->actingAs($this->student, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 50]);
        $this->actingAs($other, 'sanctum')->putJson("/api/v1/chapter-videos/{$this->video->id}/progress", ['position_seconds' => 10]);

        $this->assertSame(2, ChapterVideoProgress::where('chapter_video_id', $this->video->id)->count());
        $this->assertSame(50, ChapterVideoProgress::where('user_id', $this->student->id)->first()->max_position_seconds);
        $this->assertSame(10, ChapterVideoProgress::where('user_id', $other->id)->first()->max_position_seconds);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ChapterVideoProgressControllerTest.php`
Expected: FAIL — the route doesn't exist yet (404s).

- [ ] **Step 3: Add the config key** — in `backend/config/content.php`, add (alongside the existing keys from Plan A):

```php
    /*
     * Default percentage-of-duration a student must reach for a video to
     * count as completed, used when a ChapterVideo has no per-video
     * override (completion_threshold_percent).
     */
    'video_completion_threshold_percent' => (int) env('VIDEO_COMPLETION_THRESHOLD_PERCENT', 90),
```

- [ ] **Step 4: Create the request** — `backend/app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChapterVideoProgressRequest extends FormRequest
{
    /**
     * Any authenticated user may report their own progress.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'position_seconds' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 5: Create the resource** — `backend/app/Http/Resources/Api/V1/ChapterVideoProgressResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterVideoProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_video_id' => $this->chapter_video_id,
            'status' => $this->status,
            'max_position_seconds' => $this->max_position_seconds,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
```

- [ ] **Step 6: Add the controller action** — in `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`, add:

```php
use App\Http\Requests\Api\V1\UpdateChapterVideoProgressRequest;
use App\Http\Resources\Api\V1\ChapterVideoProgressResource;
use App\Models\ChapterVideoProgress;
```

```php
public function updateProgress(UpdateChapterVideoProgressRequest $request, ChapterVideo $chapter_video): ChapterVideoProgressResource
{
    $position = $request->integer('position_seconds');
    $reportedDuration = $request->input('duration_seconds');

    if ($reportedDuration && ! $chapter_video->duration_seconds) {
        $chapter_video->update(['duration_seconds' => $reportedDuration]);
    }

    $progress = ChapterVideoProgress::firstOrNew([
        'chapter_video_id' => $chapter_video->id,
        'user_id' => $request->user()->id,
    ]);

    if (! $progress->exists) {
        $progress->status = 'not_started';
        $progress->max_position_seconds = 0;
    }

    if ($position > $progress->max_position_seconds) {
        $progress->max_position_seconds = $position;
    }

    $progress->started_at ??= now();

    $duration = $chapter_video->duration_seconds ?? $reportedDuration;
    $threshold = $chapter_video->completion_threshold_percent ?? config('content.video_completion_threshold_percent');

    if ($progress->status !== 'completed') {
        if ($duration && $duration > 0 && ($progress->max_position_seconds / $duration) * 100 >= $threshold) {
            $progress->status = 'completed';
            $progress->completed_at = now();
        } else {
            $progress->status = 'in_progress';
        }
    }

    $progress->save();

    return new ChapterVideoProgressResource($progress);
}
```

- [ ] **Step 7: Register the route** — in `backend/routes/api.php`, inside the `auth:sanctum` group, right after the `chapter-videos` lines from Task 4:

```php
Route::put('/chapter-videos/{chapter_video}/progress', [ChapterVideoController::class, 'updateProgress']);
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/ChapterVideoProgressControllerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 9: Run the full backend suite**

Run: `php artisan test`
Expected: PASS — every test in `backend/tests/`, old and new, across both plans.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/config/content.php backend/app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php backend/app/Http/Resources/Api/V1/ChapterVideoProgressResource.php backend/app/Http/Controllers/Api/V1/ChapterVideoController.php backend/routes/api.php backend/tests/Feature/ChapterVideoProgressControllerTest.php
git commit -m "Add video progress reporting with configurable completion threshold"
```

---

## Out of scope for this plan

Frontend playback UI (YouTube IFrame Player API integration, polling `position_seconds` to the backend) — see the spec's "Frontend correction." This plan delivers the API the student frontend will call once that sub-project is built. Calling YouTube's Data API for metadata/availability verification is also out of scope — see the spec.
