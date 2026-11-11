# Add created_by / updated_by Audit Columns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `created_by`/`updated_by` audit columns to the 25 database tables that back a dedicated `Store*Request`/`Update*Request` resource, populated automatically from the authenticated actor and exposed on each resource's JSON response.

**Architecture:** One combined migration adds nullable `created_by`/`updated_by` (FK to `users.id`, `ON DELETE SET NULL`) to all 25 tables. A new `App\Models\Concerns\Auditable` trait — mirroring the existing `TenantScoped` trait's `boot{Trait}()` pattern — hooks Eloquent's `creating`/`updating` events to set these columns from `Auth::id()`. The trait is added to all 25 models; each corresponding `*Resource` class exposes the two columns as plain IDs. Zero controller changes.

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit, sqlite in-memory for tests (`foreign_key_constraints` enabled), MySQL in production.

**Spec:** `docs/superpowers/specs/2026-11-11-audit-created-by-updated-by-design.md`

## Global Constraints

- Exactly these 25 tables get the columns: `divisions`, `districts`, `upazilas`, `schools`, `subjects`, `school_classes`, `academic_years`, `classrooms`, `class_routines`, `class_versions`, `shifts`, `sections`, `subject_teacher_assignments`, `timetable_entries`, `books`, `chapters`, `chapter_materials`, `chapter_videos`, `questions`, `exams`, `roles`, `users`, `teacher_profiles`, `student_profiles`, `guardians`. No other table.
- `created_by`/`updated_by` are nullable `unsignedBigInteger`, FK to `users.id`, `ON DELETE SET NULL`.
- Populated ONLY via the `App\Models\Concerns\Auditable` trait's `creating`/`updating` hooks — never added to any `Store*Request`/`Update*Request` validation rules or any model's `$fillable`.
- Exposed on each corresponding `*Resource::toArray()` as `'created_by' => $this->created_by` and `'updated_by' => $this->updated_by`, inserted immediately after `'id' => $this->id,` — plain IDs, no eager-loaded relation.
- Every task that touches a `.php` file ends with `vendor/bin/pint --dirty --format agent` before the commit step.
- Every existing test must keep passing. Each resource's existing store/update authorization test is extended in place (not replaced) with `created_by`/`updated_by` assertions.

---

### Task 1: Migration — add the columns to all 25 tables

**Files:**
- Create: `database/migrations/2026_11_11_120000_add_created_by_and_updated_by_to_audited_tables.php`

**Interfaces:**
- Produces: `created_by`, `updated_by` nullable FK columns on all 25 tables listed in Global Constraints. Every later task's `Auditable` trait and `*Resource` edits depend on these columns existing.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table backed by its own Store*Request — an authenticated actor
     * always exists at creation/update time for these. See
     * docs/superpowers/specs/2026-11-11-audit-created-by-updated-by-design.md.
     *
     * @var list<string>
     */
    private array $tables = [
        'divisions', 'districts', 'upazilas', 'schools', 'subjects',
        'school_classes', 'academic_years', 'classrooms', 'class_routines',
        'class_versions', 'shifts', 'sections', 'subject_teacher_assignments',
        'timetable_entries', 'books', 'chapters', 'chapter_materials',
        'chapter_videos', 'questions', 'exams', 'roles', 'users',
        'teacher_profiles', 'student_profiles', 'guardians',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
                $blueprint->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('updated_by');
                $blueprint->dropConstrainedForeignId('created_by');
            });
        }
    }
};
```

- [ ] **Step 2: Verify it applies cleanly against the test DB**

Run: `php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (5 tests) — confirms the new migration coexists with every existing table/FK without breaking `RefreshDatabase`'s migrate-from-scratch for every test run, including the self-referential FK on `users`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_11_11_120000_add_created_by_and_updated_by_to_audited_tables.php
git commit -m "Add created_by/updated_by columns to all audited tables"
```

---

### Task 2: `Auditable` trait + Division (proves the mechanism end-to-end)

**Files:**
- Create: `app/Models/Concerns/Auditable.php`
- Create: `tests/Unit/AuditableTraitTest.php`
- Modify: `app/Models/Division.php`
- Modify: `app/Http/Resources/Api/V1/DivisionResource.php`
- Modify: `tests/Feature/ReferenceDataAuthorizationTest.php` (division test only)

**Interfaces:**
- Consumes: the `created_by`/`updated_by` columns from Task 1.
- Produces: `App\Models\Concerns\Auditable` trait, reused by every subsequent task. Its contract: using it on a model auto-sets `created_by` on `creating` (only if not already set) and `updated_by` on every `updating`, both from `Auth::id()` (null outside an authenticated request).

- [ ] **Step 1: Write the trait**

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the trait, registering the creating/updating hooks. Laravel
     * calls every used trait's boot{TraitName}() method automatically.
     */
    protected static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if ($model->created_by === null) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            $model->updated_by = Auth::id();
        });
    }
}
```

- [ ] **Step 2: Write the failing unit test**

Create `tests/Unit/AuditableTraitTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditableTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_sets_created_by_to_the_authenticated_actor(): void
    {
        $role = Role::create(['name' => 'actor_role', 'label' => 'Actor']);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);

        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $this->assertSame($actor->id, $division->created_by);
        $this->assertNull($division->updated_by);
    }

    public function test_updating_sets_updated_by_to_the_authenticated_actor(): void
    {
        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $role = Role::create(['name' => 'actor_role_2', 'label' => 'Actor 2']);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);

        $division->update(['name' => 'Dhaka Division']);

        $this->assertSame($actor->id, $division->updated_by);
    }

    public function test_creating_and_updating_with_no_authenticated_actor_leaves_columns_null(): void
    {
        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $this->assertNull($division->created_by);

        $division->update(['name' => 'Dhaka Division']);

        $this->assertNull($division->updated_by);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test tests/Unit/AuditableTraitTest.php`
Expected: FAIL — `Division` doesn't use the trait yet, so `created_by`/`updated_by` stay `null` in every case, failing the first two tests' `assertSame`.

- [ ] **Step 4: Apply the trait to Division**

In `app/Models/Division.php`, change:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Division extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Division extends Model
{
    use Auditable, SoftDeletes;
```

- [ ] **Step 5: Run the unit test again to verify it passes**

Run: `php artisan test tests/Unit/AuditableTraitTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Expose the columns on DivisionResource**

In `app/Http/Resources/Api/V1/DivisionResource.php`, change:

```php
        return [
            'id' => $this->id,
            'name' => $this->name,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'name' => $this->name,
```

- [ ] **Step 7: Extend the existing division authorization test**

In `tests/Feature/ReferenceDataAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['divisions.create', 'divisions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/divisions', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['divisions.create', 'divisions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/divisions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 8: Run the full relevant test files**

Run: `php artisan test tests/Unit/AuditableTraitTest.php tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (8 tests total)

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Concerns/Auditable.php app/Models/Division.php app/Http/Resources/Api/V1/DivisionResource.php tests/Unit/AuditableTraitTest.php tests/Feature/ReferenceDataAuthorizationTest.php
git commit -m "Add Auditable trait, apply to Division, expose created_by/updated_by"
```

---

### Task 3: District + Upazila

**Files:**
- Modify: `app/Models/District.php`, `app/Models/Upazila.php`
- Modify: `app/Http/Resources/Api/V1/DistrictResource.php`, `app/Http/Resources/Api/V1/UpazilaResource.php`
- Modify: `tests/Feature/ReferenceDataAuthorizationTest.php` (district + upazila tests)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to District and Upazila**

In `app/Models/District.php`, change:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class District extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class District extends Model
{
    use Auditable, SoftDeletes;
```

In `app/Models/Upazila.php`, change:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Upazila extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Upazila extends Model
{
    use Auditable, SoftDeletes;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/DistrictResource.php`, change:

```php
        return [
            'id' => $this->id,
            'division_id' => $this->division_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'division_id' => $this->division_id,
```

In `app/Http/Resources/Api/V1/UpazilaResource.php`, change:

```php
        return [
            'id' => $this->id,
            'district_id' => $this->district_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'district_id' => $this->district_id,
```

- [ ] **Step 3: Extend the district and upazila authorization tests**

In `tests/Feature/ReferenceDataAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['districts.create', 'districts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/districts', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['districts.create', 'districts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/districts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['upazilas.create', 'upazilas.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/upazilas', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['upazilas.create', 'upazilas.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/upazilas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/District.php app/Models/Upazila.php app/Http/Resources/Api/V1/DistrictResource.php app/Http/Resources/Api/V1/UpazilaResource.php tests/Feature/ReferenceDataAuthorizationTest.php
git commit -m "Apply Auditable to District and Upazila, expose created_by/updated_by"
```

---

### Task 4: Subject + Guardian

**Files:**
- Modify: `app/Models/Subject.php`, `app/Models/Guardian.php`
- Modify: `app/Http/Resources/Api/V1/SubjectResource.php`, `app/Http/Resources/Api/V1/GuardianResource.php`
- Modify: `tests/Feature/ReferenceDataAuthorizationTest.php` (subject + guardian tests)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Subject and Guardian**

In `app/Models/Subject.php`, change:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use Auditable, SoftDeletes;
```

In `app/Models/Guardian.php`, change:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use Auditable, SoftDeletes;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/SubjectResource.php`, change:

```php
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'code' => $this->code,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'code' => $this->code,
```

In `app/Http/Resources/Api/V1/GuardianResource.php`, change:

```php
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'user_id' => $this->user_id,
```

- [ ] **Step 3: Extend the subject and guardian authorization tests**

In `tests/Feature/ReferenceDataAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['subjects.create', 'subjects.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subjects', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['subjects.create', 'subjects.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subjects', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['guardians.create', 'guardians.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardians', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['guardians.create', 'guardians.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardians', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Subject.php app/Models/Guardian.php app/Http/Resources/Api/V1/SubjectResource.php app/Http/Resources/Api/V1/GuardianResource.php tests/Feature/ReferenceDataAuthorizationTest.php
git commit -m "Apply Auditable to Subject and Guardian, expose created_by/updated_by"
```

---

### Task 5: School + Classroom

**Files:**
- Modify: `app/Models/School.php`, `app/Models/Classroom.php`
- Modify: `app/Http/Resources/Api/V1/SchoolResource.php`, `app/Http/Resources/Api/V1/ClassroomResource.php`
- Modify: `tests/Feature/SchoolDirectAuthorizationTest.php` (school + classroom tests)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to School and Classroom**

In `app/Models/School.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/Classroom.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/SchoolResource.php`, change:

```php
        return [
            'id' => $this->id,
            'division_id' => $this->division_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'division_id' => $this->division_id,
```

In `app/Http/Resources/Api/V1/ClassroomResource.php`, change:

```php
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'building' => $this->building,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'building' => $this->building,
```

- [ ] **Step 3: Extend the school and classroom authorization tests**

In `tests/Feature/SchoolDirectAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['schools.create', 'schools.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/schools', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['schools.create', 'schools.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/schools', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['classrooms.create', 'classrooms.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classrooms', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['classrooms.create', 'classrooms.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classrooms', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/SchoolDirectAuthorizationTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/School.php app/Models/Classroom.php app/Http/Resources/Api/V1/SchoolResource.php app/Http/Resources/Api/V1/ClassroomResource.php tests/Feature/SchoolDirectAuthorizationTest.php
git commit -m "Apply Auditable to School and Classroom, expose created_by/updated_by"
```

---

### Task 6: AcademicYear + TeacherProfile

**Files:**
- Modify: `app/Models/AcademicYear.php`, `app/Models/TeacherProfile.php`
- Modify: `app/Http/Resources/Api/V1/AcademicYearResource.php`, `app/Http/Resources/Api/V1/TeacherProfileResource.php`
- Modify: `tests/Feature/SchoolDirectAuthorizationTest.php` (academic year + teacher profile tests)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to AcademicYear and TeacherProfile**

In `app/Models/AcademicYear.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/TeacherProfile.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherProfile extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherProfile extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/AcademicYearResource.php`, change:

```php
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
```

In `app/Http/Resources/Api/V1/TeacherProfileResource.php`, change:

```php
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'employee_id' => $this->employee_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'employee_id' => $this->employee_id,
```

- [ ] **Step 3: Extend the academic year and teacher profile authorization tests**

In `tests/Feature/SchoolDirectAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['academic_years.create', 'academic_years.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/academic-years', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['academic_years.create', 'academic_years.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/academic-years', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['teacher_profiles.create', 'teacher_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['teacher_profiles.create', 'teacher_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/SchoolDirectAuthorizationTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/AcademicYear.php app/Models/TeacherProfile.php app/Http/Resources/Api/V1/AcademicYearResource.php app/Http/Resources/Api/V1/TeacherProfileResource.php tests/Feature/SchoolDirectAuthorizationTest.php
git commit -m "Apply Auditable to AcademicYear and TeacherProfile, expose created_by/updated_by"
```

---

### Task 7: SchoolClass + ClassVersion

**Files:**
- Modify: `app/Models/SchoolClass.php`, `app/Models/ClassVersion.php`
- Modify: `app/Http/Resources/Api/V1/ClassResource.php`, `app/Http/Resources/Api/V1/ClassVersionResource.php`
- Modify: `tests/Feature/ClassAndVersionAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to SchoolClass and ClassVersion**

In `app/Models/SchoolClass.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/ClassVersion.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassVersion extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassVersion extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/ClassResource.php`, change:

```php
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'numeric_level' => $this->numeric_level,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'numeric_level' => $this->numeric_level,
```

In `app/Http/Resources/Api/V1/ClassVersionResource.php`, change:

```php
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'class_id' => $this->class_id,
```

- [ ] **Step 3: Extend the class and class-version authorization tests**

In `tests/Feature/ClassAndVersionAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertOk();
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['class_versions.create', 'class_versions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['class_versions.create', 'class_versions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertOk();
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/ClassAndVersionAuthorizationTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/SchoolClass.php app/Models/ClassVersion.php app/Http/Resources/Api/V1/ClassResource.php app/Http/Resources/Api/V1/ClassVersionResource.php tests/Feature/ClassAndVersionAuthorizationTest.php
git commit -m "Apply Auditable to SchoolClass and ClassVersion, expose created_by/updated_by"
```

---

### Task 8: Shift + Section

**Files:**
- Modify: `app/Models/Shift.php`, `app/Models/Section.php`
- Modify: `app/Http/Resources/Api/V1/ShiftResource.php`, `app/Http/Resources/Api/V1/SectionResource.php`
- Modify: `tests/Feature/ShiftAndSectionAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Shift and Section**

In `app/Models/Shift.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/Section.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/ShiftResource.php`, change:

```php
        return [
            'id' => $this->id,
            'class_version_id' => $this->class_version_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'class_version_id' => $this->class_version_id,
```

In `app/Http/Resources/Api/V1/SectionResource.php`, change:

```php
        return [
            'id' => $this->id,
            'shift_id' => $this->shift_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'shift_id' => $this->shift_id,
```

- [ ] **Step 3: Extend the shift and section authorization tests**

In `tests/Feature/ShiftAndSectionAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['shifts.create', 'shifts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/shifts', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['shifts.create', 'shifts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/shifts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['sections.create', 'sections.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/sections', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['sections.create', 'sections.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/sections', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/ShiftAndSectionAuthorizationTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Shift.php app/Models/Section.php app/Http/Resources/Api/V1/ShiftResource.php app/Http/Resources/Api/V1/SectionResource.php tests/Feature/ShiftAndSectionAuthorizationTest.php
git commit -m "Apply Auditable to Shift and Section, expose created_by/updated_by"
```

---

### Task 9: Role

**Files:**
- Modify: `app/Models/Role.php`
- Modify: `app/Http/Resources/Api/V1/RoleResource.php`
- Modify: `tests/Feature/RoleAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Role**

In `app/Models/Role.php`, change:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes;
```

to:

```php
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use Auditable, SoftDeletes;
```

- [ ] **Step 2: Expose the columns on RoleResource**

In `app/Http/Resources/Api/V1/RoleResource.php`, change:

```php
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'name' => $this->name,
            'label' => $this->label,
```

- [ ] **Step 3: Extend the role authorization test**

In `tests/Feature/RoleAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['roles.create', 'roles.update', 'classes.view']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/roles', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => [$grantablePermission->name]])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['roles.create', 'roles.update', 'classes.view']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/roles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => [$grantablePermission->name]])->assertOk();
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/RoleAuthorizationTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Role.php app/Http/Resources/Api/V1/RoleResource.php tests/Feature/RoleAuthorizationTest.php
git commit -m "Apply Auditable to Role, expose created_by/updated_by"
```

---

### Task 10: User

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Http/Resources/Api/V1/UserResource.php`
- Modify: `tests/Feature/UserAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to User**

In `app/Models/User.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Database\Factories\UserFactory;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Database\Factories\UserFactory;
```

and change:

```php
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;
```

to:

```php
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on UserResource**

In `app/Http/Resources/Api/V1/UserResource.php`, change:

```php
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'name' => $this->name,
            'email' => $this->email,
```

- [ ] **Step 3: Extend the user authorization test**

In `tests/Feature/UserAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/users', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertOk();
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/UserAuthorizationTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php app/Http/Resources/Api/V1/UserResource.php tests/Feature/UserAuthorizationTest.php
git commit -m "Apply Auditable to User, expose created_by/updated_by"
```

---

### Task 11: StudentProfile

**Files:**
- Modify: `app/Models/StudentProfile.php`
- Modify: `app/Http/Resources/Api/V1/StudentProfileResource.php`
- Modify: `tests/Feature/StudentProfileAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to StudentProfile**

In `app/Models/StudentProfile.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentProfile extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentProfile extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on StudentProfileResource**

In `app/Http/Resources/Api/V1/StudentProfileResource.php`, change:

```php
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class_id' => $this->class_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class_id' => $this->class_id,
```

- [ ] **Step 3: Extend the student profile authorization test**

In `tests/Feature/StudentProfileAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['student_profiles.create', 'student_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['student_profiles.create', 'student_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertOk();
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/StudentProfileAuthorizationTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/StudentProfile.php app/Http/Resources/Api/V1/StudentProfileResource.php tests/Feature/StudentProfileAuthorizationTest.php
git commit -m "Apply Auditable to StudentProfile, expose created_by/updated_by"
```

---

### Task 12: ClassRoutine + SubjectTeacherAssignment

**Files:**
- Modify: `app/Models/ClassRoutine.php`, `app/Models/SubjectTeacherAssignment.php`
- Modify: `app/Http/Resources/Api/V1/ClassRoutineResource.php`, `app/Http/Resources/Api/V1/SubjectTeacherAssignmentResource.php`
- Modify: `tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php` (routine + assignment tests only)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to ClassRoutine and SubjectTeacherAssignment**

In `app/Models/ClassRoutine.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassRoutine extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassRoutine extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/SubjectTeacherAssignment.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectTeacherAssignment extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectTeacherAssignment extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/ClassRoutineResource.php`, change:

```php
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class_id' => $this->class_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class_id' => $this->class_id,
```

In `app/Http/Resources/Api/V1/SubjectTeacherAssignmentResource.php`, change:

```php
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'section_id' => $this->section_id,
```

- [ ] **Step 3: Extend the class routine and subject-teacher-assignment authorization tests**

In `tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

and change:

```php
        $allowed = $this->actorWithPermissions(['subject_teacher_assignments.create', 'subject_teacher_assignments.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", ['teacher_id' => $this->teacher->id])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['subject_teacher_assignments.create', 'subject_teacher_assignments.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", ['teacher_id' => $this->teacher->id])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/ClassRoutine.php app/Models/SubjectTeacherAssignment.php app/Http/Resources/Api/V1/ClassRoutineResource.php app/Http/Resources/Api/V1/SubjectTeacherAssignmentResource.php tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php
git commit -m "Apply Auditable to ClassRoutine and SubjectTeacherAssignment, expose created_by/updated_by"
```

---

### Task 13: TimetableEntry

**Files:**
- Modify: `app/Models/TimetableEntry.php`
- Modify: `app/Http/Resources/Api/V1/TimetableEntryResource.php`
- Modify: `tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php` (timetable entry test — renamed to cover update too)

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to TimetableEntry**

In `app/Models/TimetableEntry.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableEntry extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableEntry extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on TimetableEntryResource**

In `app/Http/Resources/Api/V1/TimetableEntryResource.php`, change:

```php
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'section_id' => $this->section_id,
```

- [ ] **Step 3: Extend the timetable entry test to also cover update**

In `tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`, change:

```php
    public function test_timetable_entry_store_is_authorized(): void
    {
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $payload = [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['timetable_entries.create']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)->assertCreated();
    }
```

to:

```php
    public function test_timetable_entry_store_and_update_are_authorized(): void
    {
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $payload = [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['timetable_entries.create', 'timetable_entries.update']);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/timetable-entries/{$id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/TimetableEntry.php app/Http/Resources/Api/V1/TimetableEntryResource.php tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php
git commit -m "Apply Auditable to TimetableEntry, expose created_by/updated_by"
```

---

### Task 14: Book + Chapter

**Files:**
- Modify: `app/Models/Book.php`, `app/Models/Chapter.php`
- Modify: `app/Http/Resources/Api/V1/BookResource.php`, `app/Http/Resources/Api/V1/ChapterResource.php`
- Modify: `tests/Feature/BookControllerTest.php`, `tests/Feature/ChapterControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Book and Chapter**

In `app/Models/Book.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/Chapter.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/BookResource.php`, change:

```php
        return [
            'id' => $this->id,
            'class_version_id' => $this->class_version_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'class_version_id' => $this->class_version_id,
```

In `app/Http/Resources/Api/V1/ChapterResource.php`, change:

```php
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'book_id' => $this->book_id,
```

- [ ] **Step 3: Extend the book and chapter authorization tests**

In `tests/Feature/BookControllerTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id)
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

In `tests/Feature/ChapterControllerTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id)
            ->assertJsonPath('data.created_by', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/BookControllerTest.php tests/Feature/ChapterControllerTest.php`
Expected: PASS (all tests in both files)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Book.php app/Models/Chapter.php app/Http/Resources/Api/V1/BookResource.php app/Http/Resources/Api/V1/ChapterResource.php tests/Feature/BookControllerTest.php tests/Feature/ChapterControllerTest.php
git commit -m "Apply Auditable to Book and Chapter, expose created_by/updated_by"
```

---

### Task 15: ChapterMaterial + ChapterVideo

**Files:**
- Modify: `app/Models/ChapterMaterial.php`, `app/Models/ChapterVideo.php`
- Modify: `app/Http/Resources/Api/V1/ChapterMaterialResource.php`, `app/Http/Resources/Api/V1/ChapterVideoResource.php`
- Modify: `tests/Feature/ChapterMaterialControllerTest.php`, `tests/Feature/ChapterVideoControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to ChapterMaterial and ChapterVideo**

In `app/Models/ChapterMaterial.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterMaterial extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterMaterial extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

In `app/Models/ChapterVideo.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterVideo extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterVideo extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on both Resources**

In `app/Http/Resources/Api/V1/ChapterMaterialResource.php`, change:

```php
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'type' => $this->type,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'chapter_id' => $this->chapter_id,
            'type' => $this->type,
```

In `app/Http/Resources/Api/V1/ChapterVideoResource.php`, change:

```php
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'youtube_video_id' => $this->youtube_video_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'chapter_id' => $this->chapter_id,
            'youtube_video_id' => $this->youtube_video_id,
```

- [ ] **Step 3: Extend the chapter-material and chapter-video authorization tests**

In `tests/Feature/ChapterMaterialControllerTest.php`, change:

```php
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $created = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$created}", ['order_index' => 2])
            ->assertOk();
    }
```

to:

```php
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $createResponse = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->assertJsonPath('data.created_by', $allowed->id);
        $created = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$created}", ['order_index' => 2])
            ->assertOk()
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

In `tests/Feature/ChapterVideoControllerTest.php`, change:

```php
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
```

to:

```php
        $allowed = $this->actorWithPermissions(['chapter_videos.create', 'chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapter-videos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.youtube_video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.created_by', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$id}", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/ChapterMaterialControllerTest.php tests/Feature/ChapterVideoControllerTest.php`
Expected: PASS (all tests in both files)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/ChapterMaterial.php app/Models/ChapterVideo.php app/Http/Resources/Api/V1/ChapterMaterialResource.php app/Http/Resources/Api/V1/ChapterVideoResource.php tests/Feature/ChapterMaterialControllerTest.php tests/Feature/ChapterVideoControllerTest.php
git commit -m "Apply Auditable to ChapterMaterial and ChapterVideo, expose created_by/updated_by"
```

---

### Task 16: Question

**Files:**
- Modify: `app/Models/Question.php`
- Modify: `app/Http/Resources/Api/V1/QuestionResource.php`
- Modify: `tests/Feature/QuestionControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Question**

In `app/Models/Question.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on QuestionResource**

In `app/Http/Resources/Api/V1/QuestionResource.php`, change:

```php
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'question_type' => $this->question_type,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'chapter_id' => $this->chapter_id,
            'question_type' => $this->question_type,
```

- [ ] **Step 3: Extend the question authorization test**

In `tests/Feature/QuestionControllerTest.php`, change:

```php
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
```

to:

```php
        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_text', 'What is 2+2?')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.created_by', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/questions/{$id}", ['question_text' => 'What is 2+2, really?'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'What is 2+2, really?')
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/QuestionControllerTest.php`
Expected: PASS (all tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Question.php app/Http/Resources/Api/V1/QuestionResource.php tests/Feature/QuestionControllerTest.php
git commit -m "Apply Auditable to Question, expose created_by/updated_by"
```

---

### Task 17: Exam

**Files:**
- Modify: `app/Models/Exam.php`
- Modify: `app/Http/Resources/Api/V1/ExamResource.php`
- Modify: `tests/Feature/ExamControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` (Task 2).

- [ ] **Step 1: Apply the trait to Exam**

In `app/Models/Exam.php`, change:

```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Exam extends Model
{
    use SoftDeletes, TenantScoped;
```

to:

```php
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Exam extends Model
{
    use Auditable, SoftDeletes, TenantScoped;
```

- [ ] **Step 2: Expose the columns on ExamResource**

In `app/Http/Resources/Api/V1/ExamResource.php`, change:

```php
        return [
            'id' => $this->id,
            'class_version_id' => $this->class_version_id,
```

to:

```php
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'class_version_id' => $this->class_version_id,
```

- [ ] **Step 3: Extend the exam authorization test**

In `tests/Feature/ExamControllerTest.php`, change:

```php
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
```

to:

```php
        $allowed = $this->actorWithPermissions(['exams.create', 'exams.update', 'exams.view'], $this->schoolA->id, $this->schoolA->district_id);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/exams', $this->examPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.created_by', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/exams/{$id}", ['passing_score_percent' => 40])
            ->assertOk()
            ->assertJsonPath('data.passing_score_percent', 40)
            ->assertJsonPath('data.updated_by', $allowed->id);
    }
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/ExamControllerTest.php`
Expected: PASS (all tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Exam.php app/Http/Resources/Api/V1/ExamResource.php tests/Feature/ExamControllerTest.php
git commit -m "Apply Auditable to Exam, expose created_by/updated_by"
```

---

### Task 18: Final whole-branch verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1–17.

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: every test passes (the pre-existing count plus the new `AuditableTraitTest` and all extended assertions).

- [ ] **Step 2: Run Pint across the whole backend (not just dirty files) to catch anything missed**

Run: `vendor/bin/pint --format agent`
Expected: `{"tool":"pint","result":"passed"}` with no further changes needed (every prior task already ran `--dirty`, so this should be a no-op confirmation).

- [ ] **Step 3: Grep for any remaining audited model missing the trait**

Run (from `backend/`):
```bash
for m in Division District Upazila School Subject SchoolClass AcademicYear Classroom ClassRoutine ClassVersion Shift Section SubjectTeacherAssignment TimetableEntry Book Chapter ChapterMaterial ChapterVideo Question Exam Role User TeacherProfile StudentProfile Guardian; do
  grep -q "Auditable" "app/Models/$m.php" || echo "MISSING: $m"
done
```
Expected: no output (every one of the 25 models uses the trait).

- [ ] **Step 4: Confirm nothing is left uncommitted**

Run: `git status`
Expected: clean working tree (every task already committed its own changes).
