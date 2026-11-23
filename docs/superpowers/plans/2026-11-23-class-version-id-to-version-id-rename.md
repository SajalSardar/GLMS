# Rename `class_version_id` to `version_id` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename `class_version_id` (and `to_class_version_id`) to `version_id`/`to_version_id` across 11 tables and every piece of code that reads, writes, filters, or eager-loads by that name — a pure naming-consistency rename, no change to what any of these FKs point at.

**Architecture:** One migration renames all 11 columns (MySQL 8 native `RENAME COLUMN`, no `doctrine/dbal`, preserves each column's FK and index membership automatically). Ten `classVersion()`/one `toClassVersion()` relation methods are renamed to `version()`/`toVersion()`. Every consumer — FormRequests, Controllers, Resources, Policies, Support classes, seeders, the full backend test suite, the frontend `api-client` package, and 26 frontend pages — is updated to match.

**Tech Stack:** Laravel 13 (PHP 8.5), MySQL (prod) / SQLite (tests), React + TypeScript frontend.

**Spec:** `docs/superpowers/specs/2026-11-23-class-version-id-to-version-id-rename-design.md`

## Global Constraints

**Rename these tokens, everywhere they appear in this form:**
- `'class_version_id'` (request key, array key, column name in a query/validation rule, query-string param) → `'version_id'`. `'to_class_version_id'` → `'to_version_id'`.
- `->class_version_id` / `->to_class_version_id` (property access) → `->version_id` / `->to_version_id`.
- The relation `classVersion()` / `->classVersion` / `->classVersion()` / `whenLoaded('classVersion')` / `with('classVersion')` / `load('classVersion')`, including as a segment of a dotted eager-load path (`'shift.classVersion'`, `'section.shift.classVersion'`, `'book.classVersion'`, `'classVersion.schoolClass'`) → `version` in every one of those forms. `toClassVersion` → `toVersion`.
- The camelCase variable `$classVersionId`/`classVersionId` (holding an id) → `$versionId`/`versionId`.
- The JSON/array key `'class_version'` (nested Resource object, or a flat hand-built display-label string) → `'version'`.

**Never rename these:**
- `ClassVersion` the class name (`ClassVersion::class`, `use App\Models\ClassVersion;`, `new ClassVersionResource(...)`, `ClassVersionController`, `ClassVersionPolicy`, `Store`/`UpdateClassVersionRequest`, a type-hint `ClassVersion $x`).
- A local variable merely *typed as* `ClassVersion`, e.g. `$classVersion = ClassVersion::findOrFail(...)` — the variable name stays `$classVersion`; only `$classVersion->class_version_id` (if it ever occurs, which it shouldn't on a `ClassVersion` instance itself) would change, not the variable's own name.
- The `class_versions` table / `ClassVersion` model concept itself.
- `ClassVersion::version()` / `class_versions.version_id` — already correct, from the national-version-catalog program. Do not touch.

**Every task after Task 1 will find its own domain's tests already failing** (the migration lands first and removes the old column name immediately) — that is expected and is Task 13's job, not any individual domain task's job. Do not try to fix test files inside a domain task.

Run `vendor/bin/pint --dirty --format agent` after any PHP file change.

---

### Task 1: Migration

**Files:**
- Create: `backend/database/migrations/2026_11_24_080000_rename_class_version_id_to_version_id.php`
- Test: `backend/tests/Feature/RenameClassVersionIdMigrationTest.php`

**Interfaces:**
- Produces: `version_id` column (FK → `class_versions.id`, same nullability/on-delete as before) on `shifts`, `version_subjects`, `books`, `exams`, `academic_exam_marks`, `attendances`, `student_leave_applications`, `class_routines`, `chapter_materials`, `student_profiles`; `to_version_id` on `student_class_promotions`. The old `class_version_id`/`to_class_version_id` columns no longer exist on any of them.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RenameClassVersionIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    public static function renamedTables(): array
    {
        return [
            'shifts', 'version_subjects', 'books', 'exams',
            'academic_exam_marks', 'attendances', 'student_leave_applications',
            'class_routines', 'chapter_materials', 'student_profiles',
        ];
    }

    public function test_version_id_replaces_class_version_id_on_every_table(): void
    {
        foreach (self::renamedTables() as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'version_id'), "{$table} should have version_id");
            $this->assertFalse(Schema::hasColumn($table, 'class_version_id'), "{$table} should not have class_version_id");
        }
    }

    public function test_to_version_id_replaces_to_class_version_id_on_student_class_promotions(): void
    {
        $this->assertTrue(Schema::hasColumn('student_class_promotions', 'to_version_id'));
        $this->assertFalse(Schema::hasColumn('student_class_promotions', 'class_version_id'));
        $this->assertFalse(Schema::hasColumn('student_class_promotions', 'to_class_version_id'));
    }

    /**
     * Schema-level check that data and the renamed column's FK/unique
     * constraint both survive, using the query builder directly — not
     * Eloquent, since Shift's own model/fillable rename is Task 2, not
     * this task.
     */
    public function test_shifts_version_id_still_enforces_its_unique_constraint_and_fk(): void
    {
        $division = \DB::table('divisions')->insertGetId(['name' => 'D', 'bn_name' => 'ব', 'created_at' => now(), 'updated_at' => now()]);
        $district = \DB::table('districts')->insertGetId(['division_id' => $division, 'name' => 'D', 'bn_name' => 'ব', 'created_at' => now(), 'updated_at' => now()]);
        $upazila = \DB::table('upazilas')->insertGetId(['district_id' => $district, 'name' => 'U', 'bn_name' => 'ব', 'created_at' => now(), 'updated_at' => now()]);
        $school = \DB::table('schools')->insertGetId(['division_id' => $division, 'district_id' => $district, 'upazila_id' => $upazila, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        $class = \DB::table('school_classes')->insertGetId(['name' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        $banglaId = \DB::table('versions')->where('name', 'Bangla')->value('id');
        $classVersionId = \DB::table('class_versions')->insertGetId([
            'class_id' => $class, 'school_id' => $school, 'district_id' => $district, 'version_id' => $banglaId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        \DB::table('shifts')->insert([
            'version_id' => $classVersionId, 'school_id' => $school, 'district_id' => $district,
            'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00',
            'days_of_week' => json_encode(['sunday']), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, \DB::table('shifts')->where('version_id', $classVersionId)->count());

        // The unique(version_id, name) constraint (renamed from
        // unique(class_version_id, name)) must still reject a duplicate.
        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('shifts')->insert([
            'version_id' => $classVersionId, 'school_id' => $school, 'district_id' => $district,
            'name' => 'Day Shift', 'start_time' => '09:00', 'end_time' => '14:00',
            'days_of_week' => json_encode(['monday']), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RenameClassVersionIdMigrationTest`
Expected: FAIL — `class_version_id` still exists, `version_id` doesn't, on every table.

- [ ] **Step 3: Confirm `renameColumn` needs no new dependency**

Before writing the migration, confirm this project's Laravel version compiles `RENAME COLUMN` natively for MySQL (no `doctrine/dbal`):

```bash
grep -n "compileRenameColumn" backend/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/MySqlGrammar.php
```

Expected: a method exists that checks the MySQL/MariaDB version and falls back to a legacy compiler only below MySQL 8.0.3 / MariaDB 10.5.2 — meaning native support exists for this project's target (confirmed during planning: this project's Laravel version has this natively). If this check comes back empty or shows a hard dependency on doctrine/dbal, STOP and report BLOCKED — do not add a new Composer dependency without explicit approval (this project's CLAUDE.md: "Do not change the application's dependencies without approval").

- [ ] **Step 4: Write the migration**

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
     * Pure rename for naming consistency — see docs/superpowers/specs/
     * 2026-11-23-class-version-id-to-version-id-rename-design.md. Every
     * one of these columns keeps its existing foreign key to
     * class_versions.id and its existing index/unique membership; only
     * the column's name changes. MySQL 8's native RENAME COLUMN (used
     * directly by this Laravel version, no doctrine/dbal) preserves a
     * column's FK and index membership automatically across the rename —
     * verified against real MySQL in this task's Step 6, not assumed
     * from a clean SQLite run, per this project's own documented
     * SQLite/MySQL migration-behavior gap.
     *
     * @var list<string>
     */
    private array $tables = [
        'shifts', 'version_subjects', 'books', 'exams',
        'academic_exam_marks', 'attendances', 'student_leave_applications',
        'class_routines', 'chapter_materials', 'student_profiles',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->renameColumn('class_version_id', 'version_id');
            });
        }

        Schema::table('student_class_promotions', function (Blueprint $blueprint) {
            $blueprint->renameColumn('to_class_version_id', 'to_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->renameColumn('version_id', 'class_version_id');
            });
        }

        Schema::table('student_class_promotions', function (Blueprint $blueprint) {
            $blueprint->renameColumn('to_version_id', 'to_class_version_id');
        });
    }
};
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=RenameClassVersionIdMigrationTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Manually verify against real MySQL**

This is the one thing SQLite cannot prove (SQLite's `ALTER TABLE` strategy rebuilds the whole table, which can silently absorb an FK/index problem MySQL would reject outright — the exact failure class already documented in this project's memory). Against a throwaway local MySQL database (not the real dev database):

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ttla_rename_verify"
```

Temporarily point `backend/phpunit.xml`'s `DB_CONNECTION`/`DB_DATABASE` at `mysql`/`ttla_rename_verify` (same values this project has used for prior real-MySQL verifications — `DB_HOST=127.0.0.1`, `DB_USERNAME=root`, `DB_PASSWORD=` empty), then:

```bash
php artisan test --filter=RenameClassVersionIdMigrationTest
```

Expected: PASS against real MySQL too. **Revert `backend/phpunit.xml` back to its original `sqlite`/`:memory:` values immediately after** — confirm with `git diff backend/phpunit.xml` showing no changes before moving on.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_24_080000_rename_class_version_id_to_version_id.php backend/tests/Feature/RenameClassVersionIdMigrationTest.php
git commit -m "feat: rename class_version_id to version_id across all 11 tables"
```

---

### Task 2: Models

**Files:**
- Modify: `backend/app/Models/Shift.php`, `Book.php`, `Exam.php`, `AcademicExamMark.php`, `Attendance.php`, `StudentLeaveApplication.php`, `ClassRoutine.php`, `ChapterMaterial.php`, `StudentProfile.php`, `StudentClassPromotion.php`, `ClassVersion.php`
- Test: `backend/tests/Feature/ClassVersionRelationRenameTest.php`

**Interfaces:**
- Produces: `version(): BelongsTo` (replacing `classVersion()`) on the first 9 models above; `toVersion(): BelongsTo` (replacing `toClassVersion()`) on `StudentClassPromotion`; `ClassVersion::subjects()` with explicit pivot FK arguments.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassVersionRelationRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_version_relation_resolves_the_linked_class_version(): void
    {
        $division = Division::create(['name' => 'D', 'bn_name' => 'ব']);
        $district = District::create(['division_id' => $division->id, 'name' => 'D', 'bn_name' => 'ব']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'U', 'bn_name' => 'ব']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'S']);
        $class = SchoolClass::create(['name' => 'C']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $district->id]);
        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $district->id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);

        $shift = Shift::create(['version_id' => $classVersion->id, 'school_id' => $school->id, 'district_id' => $district->id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);

        $this->assertTrue($shift->version->is($classVersion));
        $this->assertSame($classVersion->id, $shift->version_id);
    }

    public function test_class_version_subjects_pivot_still_works_after_the_pivot_column_rename(): void
    {
        $division = Division::create(['name' => 'D', 'bn_name' => 'ব']);
        $district = District::create(['division_id' => $division->id, 'name' => 'D', 'bn_name' => 'ব']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'U', 'bn_name' => 'ব']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'S']);
        $class = SchoolClass::create(['name' => 'C']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $district->id]);
        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $district->id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $subject = Subject::create(['name' => 'Math']);

        $classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => 5]);

        $this->assertTrue($classVersion->subjects()->where('subjects.id', $subject->id)->exists());
        $this->assertSame(5, $classVersion->subjects()->first()->pivot->required_weekly_periods);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ClassVersionRelationRenameTest`
Expected: FAIL — `version_id` isn't yet in `Shift::$fillable`, so mass-assignment drops it silently; the underlying `shifts.version_id` column is NOT NULL (Task 1 preserved the original `class_version_id`'s nullability when renaming it), so the `Shift::create()` call itself throws a `QueryException` before the assertions ever run. Either way, the test fails — that's the expected RED.

- [ ] **Step 3: Update each model**

For each of `Shift.php`, `Book.php`, `Exam.php`, `AcademicExamMark.php`, `Attendance.php`, `StudentLeaveApplication.php`, `ClassRoutine.php`, `ChapterMaterial.php`, `StudentProfile.php`:
- In `$fillable`, change `'class_version_id'` to `'version_id'`.
- Rename the relation:
  ```php
  public function classVersion(): BelongsTo
  {
      return $this->belongsTo(ClassVersion::class);
  }
  ```
  becomes
  ```php
  public function version(): BelongsTo
  {
      return $this->belongsTo(ClassVersion::class);
  }
  ```
  (No explicit FK argument needed — `belongsTo(ClassVersion::class)` without a second argument already infers the FK from the *relation method's own name* (`version` → `version_id`), which is exactly the column Task 1 produced. This is Eloquent's default convention working in this rename's favor, not against it.)

`Exam.php` additionally has a raw query inside `eligibleRandomQuestionIds()`:
```php
->whereHas('book', fn ($query) => $query->where('class_version_id', $this->class_version_id)->where('subject_id', $this->subject_id))
```
becomes
```php
->whereHas('book', fn ($query) => $query->where('version_id', $this->version_id)->where('subject_id', $this->subject_id))
```
Also update that method's docblock comments referencing `class_version_id` (three occurrences in the surrounding docblocks).

`StudentClassPromotion.php`:
- `$fillable`: `'to_class_version_id'` → `'to_version_id'`.
- Relation:
  ```php
  public function toClassVersion(): BelongsTo
  {
      return $this->belongsTo(ClassVersion::class, 'to_class_version_id');
  }
  ```
  becomes
  ```php
  public function toVersion(): BelongsTo
  {
      return $this->belongsTo(ClassVersion::class, 'to_version_id');
  }
  ```
  (This one DOES need the explicit second argument — `toVersion` doesn't match the convention-inferred `to_version_id` any more than `toClassVersion` matched `to_class_version_id` before; both always needed it explicit.)

`ClassVersion.php`:
```php
public function subjects(): BelongsToMany
{
    return $this->belongsToMany(Subject::class, 'version_subjects')
        ->withPivot('required_weekly_periods')
        ->withTimestamps();
}
```
becomes
```php
public function subjects(): BelongsToMany
{
    return $this->belongsToMany(Subject::class, 'version_subjects', 'version_id', 'subject_id')
        ->withPivot('required_weekly_periods')
        ->withTimestamps();
}
```
(Eloquent's default pivot-key convention is derived from the model's own class name — `ClassVersion` → `class_version_id` — regardless of what the pivot table's column is actually called, so this relation would silently break without the explicit FK arguments once Task 1 renames the pivot column.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=ClassVersionRelationRenameTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Models/Shift.php backend/app/Models/Book.php backend/app/Models/Exam.php backend/app/Models/AcademicExamMark.php backend/app/Models/Attendance.php backend/app/Models/StudentLeaveApplication.php backend/app/Models/ClassRoutine.php backend/app/Models/ChapterMaterial.php backend/app/Models/StudentProfile.php backend/app/Models/StudentClassPromotion.php backend/app/Models/ClassVersion.php backend/tests/Feature/ClassVersionRelationRenameTest.php
git commit -m "feat: rename classVersion()/toClassVersion() relations to version()/toVersion()"
```

---

### Task 3: Timetable support classes

**Files:**
- Modify: `backend/app/Support/TimetableGenerator.php`, `backend/app/Support/TimetableConflictChecker.php`, `backend/app/Support/MarksheetBuilder.php`

**Interfaces:**
- Consumes: `Shift::version()` (Task 2).

- [ ] **Step 1: Read each file's relevant section first**

These three files have no `class_version_id` column of their own — they reach the renamed relation only through `$shift->classVersion` or an eager-load path. Read the full surrounding method in each file before editing (the brief's line numbers below are from research during planning and may have shifted slightly).

- [ ] **Step 2: Apply the rename**

`TimetableGenerator.php` (around line 121): `$classVersion = $section->shift->classVersion;` → `$classVersion = $section->shift->version;` (the local variable `$classVersion` is NOT renamed — it's correctly typed as `ClassVersion`, per the Global Constraints' "never rename" rule — only the relation access on its right-hand side changes).

`TimetableConflictChecker.php` (around line 39): `$classVersion = $shift->classVersion;` → `$classVersion = $shift->version;` (same reasoning — local variable name unchanged).

`MarksheetBuilder.php` (around line 325): `->whereHas('shift.classVersion', fn ($query) => $query->where('class_id', $examClassId))` → `->whereHas('shift.version', fn ($query) => $query->where('class_id', $examClassId))`.

- [ ] **Step 3: Verify no syntax errors**

Run: `php -l backend/app/Support/TimetableGenerator.php backend/app/Support/TimetableConflictChecker.php backend/app/Support/MarksheetBuilder.php`
Expected: "No syntax errors detected" for all three.

(Full behavioral verification happens in Task 13's test sweep — the timetable generator/conflict checker/marksheet builder's own tests are among the files that sweep fixes, per the Global Constraints note that every domain task's tests are expected red until then.)

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Support/TimetableGenerator.php backend/app/Support/TimetableConflictChecker.php backend/app/Support/MarksheetBuilder.php
git commit -m "fix: rename classVersion relation access in timetable support classes"
```

---

### Task 4: Shift + Section domain

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreShiftRequest.php`, `UpdateShiftRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ShiftController.php`, `SectionController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ShiftResource.php`

**Interfaces:**
- Consumes: `Shift::version()` (Task 2), `shifts.version_id` (Task 1).

- [ ] **Step 1: Read each file's relevant sections first**, then apply the rename rule to every line below (exact lines found during planning — re-confirm against the current file before editing, since line numbers drift):

`StoreShiftRequest.php`:
- `$school = ClassVersion::find($this->integer('class_version_id'))?->school;` → `$this->integer('version_id')`.
- `'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],` → key becomes `'version_id'`.
- `Rule::unique('shifts', 'name')->withoutTrashed()->where('class_version_id', $this->integer('class_version_id')),` → `->where('version_id', $this->integer('version_id'))`.

`UpdateShiftRequest.php`:
- Docblock comment "`class_version_id` is immutable after creation" → "`version_id` is immutable after creation".
- `Rule::unique('shifts', 'name')->ignore($shift)->where('class_version_id', $shift->class_version_id),` → `->where('version_id', $shift->version_id)`.

`ShiftController.php`:
- `->with(['classVersion.schoolClass', 'academicYear'])` (index, and again where `'classVersion'` appears alone in a list) → `'version.schoolClass'` / `'version'`.
- `->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))` → `version_id` both places.
- Docblock "(`class_version_id`, name)" → "(`version_id`, name)".
- `$classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));` → `$request->integer('version_id')` (variable name `$classVersion` unchanged).
- `'class_version_id' => $classVersion->id,` → `'version_id' => $classVersion->id,`.
- `->where('class_version_id', $classVersion->id)` → `->where('version_id', $classVersion->id)`.
- `$shift->load(['classVersion.schoolClass', 'academicYear', 'sections'])` and the two bare `$shift->load('classVersion.schoolClass')` calls → `'version.schoolClass'`.

`ShiftResource.php`:
- `'class_version_id' => $this->class_version_id,` → `'version_id' => $this->version_id,`.
- `'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),` → `'version' => new ClassVersionResource($this->whenLoaded('version')),`.

`SectionController.php` — has no `class_version_id` column of its own, only eager-load paths:
- Every `'shift.classVersion.schoolClass'` / `'shift.classVersion'` (in `->with([...])` and `->load([...])` calls, confirmed at multiple call sites across `index`, `show`, `store`, `update`, `destroy`) → `'shift.version.schoolClass'` / `'shift.version'`.

- [ ] **Step 2: Verify no syntax errors**

Run: `php -l` on all 5 files above.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreShiftRequest.php backend/app/Http/Requests/Api/V1/UpdateShiftRequest.php backend/app/Http/Controllers/Api/V1/ShiftController.php backend/app/Http/Controllers/Api/V1/SectionController.php backend/app/Http/Resources/Api/V1/ShiftResource.php
git commit -m "fix: rename class_version_id/classVersion in the shift and section domain"
```

---

### Task 5: Book domain

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreBookRequest.php`, `UpdateBookRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Modify: `backend/app/Http/Resources/Api/V1/BookResource.php`

**Interfaces:**
- Consumes: `Book::version()` (Task 2), `books.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`StoreBookRequest.php`:
- `'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],` → `'version_id'`.
- `->where('class_version_id', $this->integer('class_version_id'))` → `version_id` both.

`UpdateBookRequest.php`:
- Docblock "`class_version_id` and subject_id are immutable" → `version_id`.
- `->where('class_version_id', $book->class_version_id)` → `version_id` both.

`BookController.php`:
- `->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))` → `version_id` both.
- `->orderBy('class_version_id')` → `->orderBy('version_id')`.
- Docblock "(`class_version_id`, subject_id," → `version_id`.
- `->with(['classVersion', 'subject', 'academicYear'])` (appears at `index()`, and `->load([...])` with the same list at `store()`/`update()`/`show()`) → `'version'`.
- `$classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));` → `version_id` (variable unchanged).
- `->where('class_version_id', $data['class_version_id'])` → `->where('version_id', $data['version_id'])`.

`BookResource.php`:
- `'class_version_id' => $this->class_version_id,` → `version_id`.
- `'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),` → `'version' => new ClassVersionResource($this->whenLoaded('version')),`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 4 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreBookRequest.php backend/app/Http/Requests/Api/V1/UpdateBookRequest.php backend/app/Http/Controllers/Api/V1/BookController.php backend/app/Http/Resources/Api/V1/BookResource.php
git commit -m "fix: rename class_version_id/classVersion in the book domain"
```

---

### Task 6: Exam + Practice Exam domain

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreExamRequest.php`, `UpdateExamRequest.php`, `SetExamQuestionsRequest.php`, `SetExamRandomChaptersRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ExamController.php`, `ExamAttemptController.php`, `PracticeExamController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ExamResource.php`

**Interfaces:**
- Consumes: `Exam::version()`, `Book::version()` (Task 2), `exams.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`StoreExamRequest.php`: `'class_version_id' => [...]` → `'version_id'`; `->where('class_version_id', $this->integer('class_version_id'))` → `version_id` both.

`UpdateExamRequest.php`: docblock mention → `version_id`; `->where('class_version_id', $exam->class_version_id)` → `version_id`; `->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))` → `version_id`.

`SetExamQuestionsRequest.php`: docblock mention → `version_id`; `->whereHas('chapter.book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))` → `version_id`.

`SetExamRandomChaptersRequest.php`: docblock mention → `version_id`; `->whereHas('book', fn ($query) => $query->where('class_version_id', $exam->class_version_id)->where('subject_id', $exam->subject_id))` → `version_id`.

`ExamController.php`:
- `private const WITH = ['classVersion', 'subject', 'academicYear', 'questions.options', 'randomChapters'];` → `'version'`.
- `private const INDEX_WITH = ['classVersion', 'subject', 'academicYear'];` → `'version'`.
- `->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))` → `version_id` both.
- `$classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));` → `version_id` (variable unchanged).

`ExamAttemptController.php`: `$classVersionId = $studentProfile?->section?->shift?->class_version_id;` → `$versionId = ...->version_id;`; `if ($classVersionId !== $exam->class_version_id || ...)` → `if ($versionId !== $exam->version_id || ...)`.

`PracticeExamController.php`:
- Three docblock mentions of `class_version_id` → `version_id`.
- `->where('class_version_id', $classVersionId)` → `$versionId` (rename the local var throughout this method, it's assigned via destructuring at `[$classVersionId, $academicYearId, $schoolId, $districtId] = $this->resolveOwnerContext(...)`  — rename that destructured name to `$versionId` too, and its parameter name in the closure that captures it via `use ($classVersionId, ...)`).
- `'class_version_id' => $classVersionId,` → `'version_id' => $versionId,`.
- `$firstChapter = Chapter::with('book.classVersion')->findOrFail($chapterIds[0]);` → `'book.version'`.
- `$classVersion = $firstChapter->book->classVersion;` → `$firstChapter->book->version;` (local variable `$classVersion` unchanged).
- `return [$classVersion->id, $academicYearId, $classVersion->school_id, $classVersion->district_id];` — unchanged (reads properties on the `$classVersion` variable, not the renamed relation/column).

`ExamResource.php`: `'class_version_id' => $this->class_version_id,` → `version_id`; `'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),` → `'version' => new ClassVersionResource($this->whenLoaded('version')),`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 8 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreExamRequest.php backend/app/Http/Requests/Api/V1/UpdateExamRequest.php backend/app/Http/Requests/Api/V1/SetExamQuestionsRequest.php backend/app/Http/Requests/Api/V1/SetExamRandomChaptersRequest.php backend/app/Http/Controllers/Api/V1/ExamController.php backend/app/Http/Controllers/Api/V1/ExamAttemptController.php backend/app/Http/Controllers/Api/V1/PracticeExamController.php backend/app/Http/Resources/Api/V1/ExamResource.php
git commit -m "fix: rename class_version_id/classVersion in the exam and practice-exam domain"
```

---

### Task 7: Class Routine domain

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreClassRoutineRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassRoutineController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ClassRoutineResource.php`

**Interfaces:**
- Consumes: `ClassRoutine::version()` (Task 2), `class_routines.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`StoreClassRoutineRequest.php`: comment "shift_id/class_version_id are never accepted" → `version_id` (comment only, no code change).

`ClassRoutineController.php`:
- Comment "section/shift/classVersion/academicYear eager-loaded" → `version`.
- `->with(['schoolClass', 'subject', 'teacher', 'classroom', 'section', 'shift', 'classVersion', 'academicYear'])` (appears 3 times across `index`/`show`/`store`-or-`update`, with slightly different orderings — rename `'classVersion'` → `'version'` in each) → `'version'`.
- `->when($request->integer('class_version_id'), fn ($query, $classVersionId) => $query->where('class_version_id', $classVersionId))` → `version_id`, and rename the closure param `$classVersionId` → `$versionId`.
- `->where('class_version_id', $profile->class_version_id)` → `version_id`.
- Comment "shift_id/class_version_id are never accepted directly" → `version_id`.
- `'class_version_id' => $section?->shift?->class_version_id,` → `version_id`.
- `$data['class_version_id'] = $section?->shift?->class_version_id;` → `version_id`.
- `$sectionClassId = $section->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.

`ClassRoutineResource.php`: `'class_version_id' => $this->class_version_id,` → `version_id`; `'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),` → `'version' => new ClassVersionResource($this->whenLoaded('version')),`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 3 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreClassRoutineRequest.php backend/app/Http/Controllers/Api/V1/ClassRoutineController.php backend/app/Http/Resources/Api/V1/ClassRoutineResource.php
git commit -m "fix: rename class_version_id/classVersion in the class routine domain"
```

---

### Task 8: Chapter Material domain

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ChapterMaterialResource.php`
- Modify: `backend/app/Policies/ChapterMaterialPolicy.php`

**Interfaces:**
- Consumes: `ChapterMaterial::version()`, `Shift::version()` (Task 2), `chapter_materials.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`ChapterMaterialController.php`:
- `->where('class_version_id', $profile->class_version_id);` → `version_id` both.
- `'class_version_id' => $section->shift?->class_version_id,` → `version_id`.
- `'class_version' => $section->shift?->classVersion?->name,` → `'version' => $section->shift?->version?->name,`.
- `'class_id' => $section->shift?->classVersion?->class_id,` → `->shift?->version?->class_id,`.
- `'class_name' => $section->shift?->classVersion?->schoolClass?->name,` → `->shift?->version?->schoolClass?->name,`.
- Docblock `[school_id, district_id, shift_id, class_version_id, class_id,` and the matching `@return array{..., class_version_id: ?int, ...}` → `version_id`.
- `$empty = ['school_id' => null, 'district_id' => null, 'shift_id' => null, 'class_version_id' => null, 'class_id' => null, 'section_id' => null];` → `'version_id' => null`.
- `'class_version_id' => $shift?->class_version_id,` → `version_id`.
- `Section::with('shift.classVersion')->findOrFail($sectionId);` → `'shift.version'`.
- `'class_id' => $shift?->classVersion?->class_id,` → `$shift?->version?->class_id,`.

`ChapterMaterialResource.php`: `'class_version_id' => $this->class_version_id,` → `version_id`.

`ChapterMaterialPolicy.php`:
- `&& $chapterMaterial->class_version_id === $profile->class_version_id;` → `version_id` both.
- `&& $section->shift?->class_version_id === $chapter->book->class_version_id` → `version_id` both.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 3 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php backend/app/Http/Resources/Api/V1/ChapterMaterialResource.php backend/app/Policies/ChapterMaterialPolicy.php
git commit -m "fix: rename class_version_id/classVersion in the chapter material domain"
```

---

### Task 9: Attendance + Academic Exam Mark/Publication domain

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/AttendanceController.php`, `AcademicExamMarkController.php`, `AcademicExamPublicationController.php`
- Modify: `backend/app/Http/Requests/Api/V1/PublishAcademicExamRequest.php`, `RecordAcademicExamMarkRequest.php`

**Interfaces:**
- Consumes: `Attendance::version()`, `AcademicExamMark::version()`, `Shift::version()` (Task 2), `attendances.version_id`, `academic_exam_marks.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`AttendanceController.php`:
- `->with(['shift.classVersion.schoolClass', 'shift.academicYear'])` (two call sites, lines confirmed near the `index`/listing method and `show`-equivalent) → `'shift.version.schoolClass'`.
- `'class_id' => $section->shift?->classVersion?->class_id,` → `->shift?->version?->class_id,`.
- `'class_name' => $section->shift?->classVersion?->schoolClass?->name,` (two call sites) → `->shift?->version?->schoolClass?->name,`.
- `'class_version_id' => $section->shift?->class_version_id,` → `version_id`.
- `'class_version' => $section->shift?->classVersion?->name,` (two call sites) → `'version' => $section->shift?->version?->name,`.
- Comment "(section_id/class_id/shift_id/class_version_id/status/marked_by_id" → `version_id`.
- `'class_version_id' => $shift?->class_version_id,` → `version_id`.
- `'class_id' => $shift?->classVersion?->class_id,` → `$shift?->version?->class_id,`.
- `Section::with(['shift.classVersion.schoolClass', 'shift.academicYear', 'school', 'classTeacher'])` → `'shift.version.schoolClass'`.

`AcademicExamMarkController.php`:
- `->with(['shift.classVersion.schoolClass', 'shift.academicYear'])` → `'shift.version.schoolClass'`.
- `$classId = $section->shift?->classVersion?->class_id;` (two call sites) → `->shift?->version?->class_id;`.
- `'class_id' => $shift?->classVersion?->class_id,` → `$shift?->version?->class_id,`.
- `'class_id' => $section->shift?->classVersion?->class_id,` → same.
- `'class_name' => $section->shift?->classVersion?->schoolClass?->name,` → `->shift?->version?->schoolClass?->name,`.
- `'class_version_id' => $shift?->class_version_id,` and `'class_version_id' => $section->shift?->class_version_id,` → `version_id`.
- `'class_version' => $section->shift?->classVersion?->name,` → `'version' => $section->shift?->version?->name,`.

`AcademicExamPublicationController.php`: `$classId = $section->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.

`PublishAcademicExamRequest.php`: `$classId = $section?->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.

`RecordAcademicExamMarkRequest.php`: `$classId = $section?->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 5 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Controllers/Api/V1/AttendanceController.php backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php backend/app/Http/Controllers/Api/V1/AcademicExamPublicationController.php backend/app/Http/Requests/Api/V1/PublishAcademicExamRequest.php backend/app/Http/Requests/Api/V1/RecordAcademicExamMarkRequest.php
git commit -m "fix: rename class_version_id/classVersion in the attendance and exam-mark domain"
```

---

### Task 10: Student Profile + Promotion + Leave Application + Dashboard domain

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreStudentProfileRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`, `StudentPromotionController.php`, `StudentLeaveApplicationController.php`
- Modify: `backend/app/Http/Resources/Api/V1/StudentProfileResource.php`, `StudentLeaveApplicationResource.php`
- Modify: `backend/app/Support/StudentDashboardBuilder.php`

**Interfaces:**
- Consumes: `StudentProfile::version()`, `StudentClassPromotion::toVersion()`, `StudentLeaveApplication::version()` (Task 2), `student_profiles.version_id`, `student_class_promotions.to_version_id`, `student_leave_applications.version_id` (Task 1).

- [ ] **Step 1: Read each file first**, then apply:

`StoreStudentProfileRequest.php`: comment "shift_id/class_version_id are deliberately not accepted" → `version_id` (comment only).

`StudentProfileController.php`:
- Comment "class_id/shift_id/class_version_id are all derived" → `version_id`.
- `? $section->shift?->classVersion?->class_id` → `->shift?->version?->class_id`.
- `'class_version_id' => $section?->shift?->class_version_id,` → `version_id` (inside `store()`'s section-derivation, added by an earlier feature — rename alongside, not remove).
- Comment "shift_id/class_version_id/class_id are all denormalized" → `version_id`.
- `$toClassId = $toSection->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.
- `'class_version_id' => $toSection->shift?->class_version_id,` → `version_id`.
- Comment "Section (with its shift/classVersion chain)" → `version`.
- `$section = Section::withoutGlobalScope(TenantScope::class)->with('shift.classVersion')->findOrFail($sectionId);` → `'shift.version'`.
- Comment "derive class_id/shift_id/class_version_id from it" → `version_id`.

`StudentPromotionController.php`:
- Comment "shift_id/class_version_id); retained students..." → `version_id`.
- `Section::with('shift.classVersion')->findOrFail($request->integer('section_id'));` (two call sites: the "from" section and the "to" section) → `'shift.version'`.
- `$fromClass = $section->shift?->classVersion?->schoolClass;` → `->shift?->version?->schoolClass;`.
- `$toClassId = $toShift?->classVersion?->class_id;` → `->classVersion` → `$toShift?->version?->class_id;`.
- `$fromClassId = $section->shift?->classVersion?->class_id;` → `->shift?->version?->class_id;`.
- `'to_class_version_id' => $promote ? $toShift?->class_version_id : null,` → `'to_version_id' => $promote ? $toShift?->version_id : null,`.
- `'class_version_id' => $toShift?->class_version_id,` → `version_id`.

`StudentLeaveApplicationController.php`:
- `private const WITH = [..., 'classVersion', ...];` → `'version'`.
- `|| $profile->class_version_id === null` → `version_id`.
- `'class_version_id' => $profile->class_version_id,` → `version_id`.

`StudentProfileResource.php`: `'class_version_id' => $this->class_version_id,` → `version_id`.

`StudentLeaveApplicationResource.php`: `'class_version_id' => $this->class_version_id,` → `version_id`; `'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),` → `'version' => new ClassVersionResource($this->whenLoaded('version')),`.

`StudentDashboardBuilder.php`:
- `$classVersionId = $shift?->class_version_id;` → `$versionId = $shift?->version_id;` (rename the variable throughout the rest of this file — it's used as a method parameter too, see below).
- `'class_version_id' => null,` → `'version_id' => null,`.
- `$classVersion = ClassVersion::findOrFail($classVersionId);` → `ClassVersion::findOrFail($versionId);` (local variable `$classVersion` unchanged).
- `fn (Subject $subject) => $this->buildSubjectRollup($profile, $classVersionId, $academicYearId, $subject)` → `$versionId`.
- `'class_version_id' => $classVersionId,` → `'version_id' => $versionId,`.
- `private function buildSubjectRollup(StudentProfile $profile, int $classVersionId, int $academicYearId, Subject $subject): array` → rename the parameter `int $classVersionId` to `int $versionId`, and every use of it inside that method's body (two `->where('class_version_id', $classVersionId)` calls → `->where('version_id', $versionId)`).
- Comment "Practice exams carry the student's own class_version_id/" → `version_id`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on all 7 files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreStudentProfileRequest.php backend/app/Http/Controllers/Api/V1/StudentProfileController.php backend/app/Http/Controllers/Api/V1/StudentPromotionController.php backend/app/Http/Controllers/Api/V1/StudentLeaveApplicationController.php backend/app/Http/Resources/Api/V1/StudentProfileResource.php backend/app/Http/Resources/Api/V1/StudentLeaveApplicationResource.php backend/app/Support/StudentDashboardBuilder.php
git commit -m "fix: rename class_version_id/classVersion in the student profile, promotion, and leave domain"
```

---

### Task 11: Subject Teacher Assignment domain

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/SubjectTeacherAssignmentController.php`
- Modify: `backend/app/Models/SubjectTeacherAssignment.php`

**Interfaces:**
- Consumes: `Shift::version()` (Task 2). `SubjectTeacherAssignment` has no `class_version_id` column of its own — it's reached only through `section.shift.classVersion`.

- [ ] **Step 1: Read each file first**, then apply:

`SubjectTeacherAssignmentController.php`:
- `->with(['section.shift.classVersion.schoolClass', 'subject', 'teacher'])` and the three `->load([...])` calls with the same list (at `store()`, `update()`, `show()`-equivalent) → `'section.shift.version.schoolClass'`.
- `'section.shift.classVersion',` (inside a nested array, likely a filter/validation context list) → `'section.shift.version'`.
- `$classVersion = $section->shift->classVersion;` → `$section->shift->version;` (local variable `$classVersion` unchanged).
- `if (! $classVersion->subjects()->where('subjects.id', $subjectId)->exists()) {` — unchanged (reads a method on the `$classVersion` variable, not the renamed relation/column itself).

`SubjectTeacherAssignment.php` (model): `->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])` (a default eager-load scope, not a relation of its own) → `'section.shift.version.schoolClass'`.

- [ ] **Step 2: Verify no syntax errors** — `php -l` on both files.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Controllers/Api/V1/SubjectTeacherAssignmentController.php backend/app/Models/SubjectTeacherAssignment.php
git commit -m "fix: rename classVersion eager-load path in the subject teacher assignment domain"
```

---

### Task 12: Seeders

**Files:**
- Modify: `backend/database/seeders/LmsContentDemoSeeder.php`, `ClassRoutineSeeder.php`, `StudentDemoSeeder.php`, `SectionBackfillSeeder.php`, `TimetableGenerationDemoSeeder.php`, `AcademicExamResultsDemoSeeder.php`

**Interfaces:**
- Consumes: Task 1 (schema), Task 2 (models).

- [ ] **Step 1: Read each file first**, then apply:

`LmsContentDemoSeeder.php`: four occurrences of `['class_version_id' => $version->id, ...]` (building `Book`/`Exam` rows) → `'version_id'`.

`ClassRoutineSeeder.php`: `['class_version_id' => $version->id, 'name' => 'Day Shift'],` (creating a `Shift`) → `'version_id'`; `'class_version_id' => $version->id,` (inside a `ClassRoutine` creation) → `'version_id'`; comment "section_id/shift_id/class_version_id" → `version_id`.

`StudentDemoSeeder.php`: `Shift::where('class_version_id', $version->id)->where('name', 'Day Shift')->first()` → `'version_id'`; `'class_version_id' => $version?->id,` → `'version_id'`; comment → `version_id`.

`SectionBackfillSeeder.php`: `['class_version_id' => $version->id, 'name' => 'Day Shift'],` (creating a `Shift`) → `'version_id'`.

`TimetableGenerationDemoSeeder.php`: `Shift::where('class_version_id', $version->id)->where('name', 'Day Shift')->first()` → `'version_id'`.

`AcademicExamResultsDemoSeeder.php`: comment "shift_id/class_version_id are derived" → `version_id`; `$classVersionId = $section->shift?->class_version_id;` → `$versionId = $section->shift?->version_id;`; `'class_version_id' => $classVersionId,` → `'version_id' => $versionId,`.

- [ ] **Step 2: Verify the full seeder chain still runs**

Run: `php artisan migrate:fresh --seed`
Expected: no errors, exit code 0.

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/seeders/LmsContentDemoSeeder.php backend/database/seeders/ClassRoutineSeeder.php backend/database/seeders/StudentDemoSeeder.php backend/database/seeders/SectionBackfillSeeder.php backend/database/seeders/TimetableGenerationDemoSeeder.php backend/database/seeders/AcademicExamResultsDemoSeeder.php
git commit -m "fix: rename class_version_id to version_id in demo/backfill seeders"
```

---

### Task 13: Backend test suite sweep

**Files:**
- Modify: every backend test file still referencing `class_version_id`, `classVersionId`, or the `classVersion`/`toClassVersion` relation (re-derive the list fresh — do not trust any list from planning, which may be stale by the time this task runs).

**Interfaces:**
- Consumes: Tasks 1–12 (all production code is now renamed; every test exercising it is currently red).

- [ ] **Step 1: Find every affected test file fresh**

```bash
grep -rl "class_version_id\|classVersionId\|classVersion\|toClassVersion" backend/tests
```

- [ ] **Step 2: Fix each file**

Apply the Global Constraints' rename rule to every match. The same two nuances from the national-version-catalog program's test sweep apply again here:
- A fixture building `Shift::create(['class_version_id' => ...])`, an assertion `assertJsonPath('data.class_version_id', ...)`, or a query-string filter `?class_version_id=` all rename straightforwardly.
- Watch for `$classVersion`-named local variables that are typed as `ClassVersion` (not renamed) versus `$classVersionId`-named variables holding an id (renamed to `$versionId`) — the same distinction as every production-code task above.

Work through the files in batches (e.g. by directory, or by domain matching Tasks 3–11's groupings), running the suite after each batch rather than saving every fix for one giant run at the end.

- [ ] **Step 3: Run the full backend suite**

Run: `php artisan test`
Expected: PASS, zero failures. Any remaining failure is either a missed rename (fix it) or a genuine regression introduced by an earlier task (root-cause it, don't paper over it with another fixture tweak — judge which applies by reading the actual failure message).

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/tests
git commit -m "fix: rename class_version_id/classVersion across the backend test suite"
```

---

### Task 14: Frontend `api-client`

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`, `frontend/packages/api-client/src/index.test.ts`

**Interfaces:**
- Produces: every affected type's `version_id` field (replacing `class_version_id`), every nested `version`/`version?: ClassVersion | null` field (replacing `class_version`), every `listX()` function's `versionId` parameter (replacing `classVersionId`) and its `version_id: params.versionId` query mapping, every payload type's `version_id` field.

- [ ] **Step 1: Find every occurrence fresh**

```bash
grep -n "class_version_id\|classVersionId\|class_version\b" frontend/packages/api-client/src/index.ts
```

(Confirmed during planning: this spans `BookPayload`, `Book`, `ShiftPayload`-adjacent types, `ChapterMaterialAssignment` — including its flat `class_version: string | null` display-label field, mirroring the backend's hand-built flat-array convention — `AttendanceSection` — same flat-string pattern — `Exam`, `ExamPayload`, `ClassRoutine`, `StudentProfile`, `StudentProfilePayload`, `StudentLeaveApplication`, `AcademicExamMark`-adjacent types, and every `listX()` function taking a `classVersionId` filter param. Re-derive the exact list fresh rather than trusting this parenthetical, which may drift.)

- [ ] **Step 2: Apply the rename rule**

Same rule as the backend (Global Constraints), translated to TypeScript naming: `class_version_id` (snake_case, JSON-facing) → `version_id`; `classVersionId` (camelCase, JS-facing parameter/property name) → `versionId`; `class_version` (nested object or flat string) → `version`.

- [ ] **Step 3: Fix `index.test.ts`**

This file's existing tests construct payloads using the old field names — update them to `version_id` to match the renamed `*Payload` types.

- [ ] **Step 4: Run the api-client test suite and typecheck**

Run: `npx vitest run packages/api-client/src/index.test.ts` — expect all passing.
Run: `cd frontend && npm run build --workspace=apps/management` (and `apps/student`, `apps/teacher`) — expect clean, since `api-client`'s types are consumed by all three; a type error in any consuming page will surface here even before Task 15 touches the pages themselves. **Do not fix page-level errors in this task** — just confirm you can see them; Task 15 fixes them.

- [ ] **Step 5: Commit**

```bash
git add frontend/packages/api-client/src/index.ts frontend/packages/api-client/src/index.test.ts
git commit -m "fix: rename class_version_id/classVersion to version_id/version in api-client"
```

---

### Task 15: Frontend pages sweep

**Files:**
- Modify: every frontend page/lib file still referencing the old names (re-derive fresh).

**Interfaces:**
- Consumes: Task 14 (the renamed `api-client` types/functions).

- [ ] **Step 1: Find every affected file fresh**

```bash
grep -rl "class_version_id\|classVersionId\|class_version\b" frontend/apps
```

(Confirmed during planning: 25 files across `apps/management`, `apps/teacher`, `apps/student` beyond `api-client` itself — `StudentDetailPage.tsx`, `SchoolDetailPage.tsx`, `BooksPage.tsx` ×2 variants, `SubjectTeacherAssignmentsPage.tsx`, `SectionsPage.tsx`, `ShiftsPage.tsx`, `ClassMaterialsPage.tsx` (teacher + student dashboard), `ManagementClassRoutinePage.tsx`, `PublishResultsPage.tsx`, `PromoteStudentsPage.tsx`, `MarksheetsPage.tsx`, `MarksEntryPage.tsx`, `LeaveApplicationsPage.tsx`, `AttendanceReportPage.tsx`, `AttendancePage.tsx` (teacher), `ClassRoutinePage.tsx` (student dashboard), `ClassExamsPage.tsx`, `DashboardHomePage.tsx`, `resolveContent.ts`, `BookDetailPage.tsx`, `VideoPlaylistPage.tsx`. Re-derive fresh rather than trusting this list.)

- [ ] **Step 2: Fix each file**

Apply the same rule as Task 14. Most occurrences are read-only display (`row.class_version_id`, `row.class_version`) or filter params passed through to a `listX()` call — mechanical renames with no logic change. Work through in batches by app (`management` first, then `teacher`, then `student`), rebuilding after each batch.

- [ ] **Step 3: Build and lint all three apps**

Run `cd frontend && npm run build --workspace=apps/management && npm run build --workspace=apps/teacher && npm run build --workspace=apps/student` — expect all three clean, zero TypeScript errors.
Run `npx oxlint apps/management/src apps/teacher/src apps/student/src` — expect no new warnings introduced by this task's edits (pre-existing, unrelated warnings in untouched files are fine).

- [ ] **Step 4: Commit**

```bash
git add frontend/apps
git commit -m "fix: rename class_version_id/classVersion to version_id/version across frontend pages"
```

---

### Task 16: Final audit + full verification

**Files:**
- Potentially any file not already touched by Tasks 1–15 — this task's job is to find out which, if any.

**Interfaces:**
- None new. This task only verifies.

- [ ] **Step 1: Fresh, repo-wide grep for every old name**

```bash
grep -rli "class_version_id\|classVersionId\|toClassVersion" backend frontend --include=*.php --include=*.ts --include=*.tsx
grep -rln "classVersion" backend/app backend/database --include=*.php
```

For the second command, every result must be one of: `ClassVersion`/`ClassVersionController`/`ClassVersionPolicy`/`ClassVersionResource`/`Store`/`UpdateClassVersionRequest` (the class/concept itself, untouched by design), or a local variable merely typed `$classVersion` (untouched by design, per the Global Constraints). Anything else is a miss — fix it now, following the same rename rule.

- [ ] **Step 2: Run the full backend suite against real MySQL**

Per this project's documented SQLite/MySQL migration-behavior gap (and this plan's own Task 1, which already required one real-MySQL check for the migration specifically — this is the full-suite version of that same discipline): temporarily point `backend/phpunit.xml` at a real local MySQL database (same procedure as Task 1 Step 6 and as this project's prior rename/catalog programs), run `php artisan test`, then **revert `phpunit.xml`** before committing anything.

Expected: PASS. If any failure is unrelated to this plan's rename (confirm by checking whether the failing test or the file it covers was touched by any of Tasks 1–15), document it as a pre-existing issue rather than attempting to fix it — this plan's scope is the rename, not unrelated pre-existing gaps.

- [ ] **Step 3: Run the full backend suite against SQLite one more time**

Run: `php artisan test` — expect PASS, zero failures, `phpunit.xml` confirmed back to its original `sqlite`/`:memory:` state (`git diff backend/phpunit.xml` empty).

- [ ] **Step 4: Full frontend build**

Run `cd frontend && npm run build --workspace=apps/management && npm run build --workspace=apps/teacher && npm run build --workspace=apps/student` — expect all clean.

- [ ] **Step 5: Commit any stragglers found in Step 1, and report**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "fix: close remaining class_version_id/classVersion stragglers found in final audit"
```

(Skip the commit if Step 1 found nothing to fix — don't manufacture a change.)

Report every straggler found and fixed in this task, by name — this is the artifact that proves the audit was actually done, not assumed.
