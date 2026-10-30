# Tenant Global Scope & Policy Simplification Implementation Plan (Plan A of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace manual per-Policy/per-controller tenant scoping with a single Eloquent global scope applied to the 13 tenant-owned models, and simplify all 20 Policies accordingly.

**Architecture:** A `TenantScope` class (implements `Illuminate\Database\Eloquent\Scope`) reads the authenticated actor's `school_id`/`district_id` and filters every query on a `TenantScoped` model automatically — including implicit route-model-binding, so a foreign-tenant row 404s before any controller code runs. Policies that currently re-check scope manually (`ChecksScope`/`inScope`) become redundant and are simplified to permission-only checks.

**Tech Stack:** Laravel (PHP 8.5), PHPUnit, SQLite in-memory for tests (existing project convention).

**Spec:** `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`

## Global Constraints

- `TenantScope` applies to exactly these 13 models: `School` (special case — scoped by its own `id`, not a `school_id` column), `User`, `TeacherProfile`, `StudentProfile`, `SchoolClass`, `Classroom`, `ClassRoutine`, `ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment`, `TimetableEntry`, `AcademicYear`.
- `Guardian` and `StudentSectionTransfer` do NOT get `TenantScoped` (no direct `school_id`/`district_id` column — scoped indirectly through relationships via their own existing bespoke methods, left untouched). Shared reference models (`Subject`, `Chapter`, `Division`, `District`, `Upazila`, `Role`, `Permission`) must never get it.
- An unauthenticated request is unrestricted by `TenantScope` (public index/show routes keep working exactly as today).
- An unscoped actor (`school_id` and `district_id` both null, e.g. `national_admin`) is unrestricted.
- This plan does NOT add any missing `authorize()` calls to controllers (that is Plan B, `docs/superpowers/plans/2026-10-30-auth-plan-b-authorize-fixes.md`) — only the 3 `->visibleTo()` removals explicitly listed below.
- This plan does NOT touch login endpoints or docs (that is Plan C).
- After every PHP file change, run `vendor/bin/pint --dirty --format agent` before considering a task done (project convention from `backend/CLAUDE.md`).

---

### Task 1: `TenantScope` + `TenantScoped` trait, with unit tests

**Files:**
- Create: `backend/app/Models/Scopes/TenantScope.php`
- Create: `backend/app/Models/Concerns/TenantScoped.php`
- Test: `backend/tests/Unit/TenantScopeTest.php`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: `App\Models\Scopes\TenantScope` (implements `Illuminate\Database\Eloquent\Scope`), `App\Models\Concerns\TenantScoped` (trait, registers the scope via `bootTenantScoped()`). Every later task applies `TenantScoped` to its models by adding `use TenantScoped;` to the model's trait-use statement.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/TenantScopeTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Division;
use App\Models\District;
use App\Models\Role;
use App\Models\School;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

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

        $this->schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $districtA->id,
            'upazila_id' => $upazilaA->id,
            'name' => 'School A',
        ]);

        $this->schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $districtB->id,
            'upazila_id' => $upazilaB->id,
            'name' => 'School B',
        ]);
    }

    public function test_unscoped_actor_sees_both_schools(): void
    {
        $role = Role::create(['name' => 'national_admin', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, School::all());
    }

    public function test_school_scoped_actor_sees_only_their_own_school(): void
    {
        $role = Role::create(['name' => 'school_admin', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = School::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->schoolA->id, $visible->first()->id);
    }

    public function test_district_scoped_actor_sees_only_their_own_district(): void
    {
        $role = Role::create(['name' => 'district_officer', 'label' => 'District Officer']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = School::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->schoolA->id, $visible->first()->id);
    }

    public function test_unauthenticated_request_is_unrestricted(): void
    {
        $this->assertCount(2, School::all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/TenantScopeTest.php`
Expected: FAIL — `School` does not yet filter by actor, so `test_school_scoped_actor_sees_only_their_own_school` and `test_district_scoped_actor_sees_only_their_own_district` fail (both schools visible instead of one). (The class itself compiles fine since `School::create()` already works today.)

- [ ] **Step 3: Create `backend/app/Models/Scopes/TenantScope.php`**

```php
<?php

namespace App\Models\Scopes;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * Restrict the query to the authenticated actor's tenant: a
     * school-scoped actor sees only their own school's rows (or, for
     * School itself, only their own school row); a district-scoped actor
     * (no school_id) sees only their own district's rows; an unscoped
     * actor (e.g. national_admin) is unrestricted. An unauthenticated
     * request is also unrestricted, so the existing public index/show
     * routes keep working unchanged.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        if ($user->school_id !== null) {
            $column = $model instanceof School ? 'id' : 'school_id';
            $builder->where($model->getTable().'.'.$column, $user->school_id);

            return;
        }

        if ($user->district_id !== null) {
            $builder->where($model->getTable().'.district_id', $user->district_id);
        }
    }
}
```

- [ ] **Step 4: Create `backend/app/Models/Concerns/TenantScoped.php`**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;

trait TenantScoped
{
    /**
     * Boot the trait, registering the tenant global scope. Laravel calls
     * every used trait's boot{TraitName}() method automatically.
     */
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
```

- [ ] **Step 5: Apply the trait to `School` only (this task's test only covers `School`)** — in `backend/app/Models/School.php`, add the import and trait use:

Change:
```php
use Illuminate\Database\Eloquent\Model;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
```

Change:
```php
class School extends Model
{
    use SoftDeletes;
```
to:
```php
class School extends Model
{
    use SoftDeletes, TenantScoped;
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/TenantScopeTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Models/Scopes/TenantScope.php app/Models/Concerns/TenantScoped.php app/Models/School.php tests/Unit/TenantScopeTest.php
git commit -m "Add TenantScope global scope and apply it to School"
```

---

### Task 2: Apply `TenantScoped` to the remaining 12 models, remove dead `scopeVisibleTo` methods

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/app/Models/TeacherProfile.php`
- Modify: `backend/app/Models/StudentProfile.php`
- Modify: `backend/app/Models/SchoolClass.php`
- Modify: `backend/app/Models/Classroom.php`
- Modify: `backend/app/Models/ClassRoutine.php`
- Modify: `backend/app/Models/ClassVersion.php`
- Modify: `backend/app/Models/Shift.php`
- Modify: `backend/app/Models/Section.php`
- Modify: `backend/app/Models/SubjectTeacherAssignment.php`
- Modify: `backend/app/Models/TimetableEntry.php`
- Modify: `backend/app/Models/AcademicYear.php`
- Test: `backend/tests/Unit/TenantScopeTest.php` (extend with an `AcademicYear`-based assertion)

**Interfaces:**
- Consumes: `App\Models\Concerns\TenantScoped` (Task 1)
- Produces: all 13 `TenantScoped` models now behave identically for tenant filtering — later tasks (Policy simplification, controller cleanup, Plan B, Plan C) rely on every one of these 13 models being scoped.

- [ ] **Step 1: Write the failing test** — add this method to `backend/tests/Unit/TenantScopeTest.php` (inside the existing class, after `test_unauthenticated_request_is_unrestricted`):

```php
    public function test_school_scoped_actor_sees_only_their_own_school_academic_years(): void
    {
        \App\Models\AcademicYear::create([
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'name' => 'Year A',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        \App\Models\AcademicYear::create([
            'school_id' => $this->schoolB->id,
            'district_id' => $this->schoolB->district_id,
            'name' => 'Year B',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $role = Role::create(['name' => 'school_admin_ay', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = \App\Models\AcademicYear::all();

        $this->assertCount(1, $visible);
        $this->assertSame('Year A', $visible->first()->name);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/TenantScopeTest.php`
Expected: FAIL on the new test — `AcademicYear` isn't scoped yet, so both years are visible.

- [ ] **Step 3: Apply `TenantScoped` to each of the 12 models**

`backend/app/Models/User.php` — remove the now-dead `scopeVisibleTo` method and its now-unused `Builder` import, add the trait:

Change:
```php
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
```

Change:
```php
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
```
to:
```php
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;
```

Remove this method entirely (it's now dead — the global scope does the same filtering automatically):
```php
    /**
     * Restrict a query to users within the given actor's scope: a single
     * school if the actor is school-scoped, a whole district if the actor
     * is district-scoped, or unrestricted if the actor is unscoped.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->school_id !== null) {
            return $query->where('school_id', $actor->school_id);
        }

        if ($actor->district_id !== null) {
            return $query->where('district_id', $actor->district_id);
        }

        return $query;
    }
```

`backend/app/Models/TeacherProfile.php` — same pattern: remove `scopeVisibleTo` and its `Builder` import, add the trait:

Change:
```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
```

Change:
```php
class TeacherProfile extends Model
{
    use SoftDeletes;
```
to:
```php
class TeacherProfile extends Model
{
    use SoftDeletes, TenantScoped;
```

Remove this method entirely:
```php
    /**
     * Restrict a query to profiles within the given actor's scope: a
     * single school if school-scoped, a whole district if
     * district-scoped, or unrestricted if unscoped.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->school_id !== null) {
            return $query->where('school_id', $actor->school_id);
        }

        if ($actor->district_id !== null) {
            return $query->where('district_id', $actor->district_id);
        }

        return $query;
    }
```

`backend/app/Models/StudentProfile.php` — same pattern:

Change:
```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
```

Change:
```php
class StudentProfile extends Model
{
    use SoftDeletes;
```
to:
```php
class StudentProfile extends Model
{
    use SoftDeletes, TenantScoped;
```

Remove this method entirely:
```php
    /**
     * Restrict a query to profiles within the given actor's scope: a
     * single school if school-scoped, a whole district if
     * district-scoped, or unrestricted if unscoped.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->school_id !== null) {
            return $query->where('school_id', $actor->school_id);
        }

        if ($actor->district_id !== null) {
            return $query->where('district_id', $actor->district_id);
        }

        return $query;
    }
```

For each of the following 9 models, there is no `scopeVisibleTo` to remove — only add the import and the trait. The edit is identical in shape for all 9; apply it to each file:

`backend/app/Models/SchoolClass.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use SoftDeletes;
```
to:
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

`backend/app/Models/Classroom.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use SoftDeletes;
```
to:
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

`backend/app/Models/ClassRoutine.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassRoutine extends Model
{
    use SoftDeletes;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassRoutine extends Model
{
    use SoftDeletes, TenantScoped;
```

`backend/app/Models/ClassVersion.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassVersion extends Model
{
    use SoftDeletes;
```
to:
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

`backend/app/Models/Shift.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use SoftDeletes;
```
to:
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

`backend/app/Models/Section.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use SoftDeletes;
```
to:
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

`backend/app/Models/SubjectTeacherAssignment.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectTeacherAssignment extends Model
{
    use SoftDeletes;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubjectTeacherAssignment extends Model
{
    use SoftDeletes, TenantScoped;
```

`backend/app/Models/TimetableEntry.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableEntry extends Model
{
    use SoftDeletes;
```
to:
```php
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableEntry extends Model
{
    use SoftDeletes, TenantScoped;
```

`backend/app/Models/AcademicYear.php` —

Change:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use SoftDeletes;
```
to:
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

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/TenantScopeTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full test suite to catch any regression from removing the three `scopeVisibleTo` methods**

Run: `cd backend && php artisan test`
Expected: the three controllers that still call `->visibleTo(...)` on `User`/`TeacherProfile`/`StudentProfile` (removed in Task 4 of this plan, not yet) will now fail with "Call to undefined method" if any existing test exercises them — if so, this is expected and resolved by Task 4; note the failing test names in your report and do not attempt to fix them in this task.

- [ ] **Step 6: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Models/User.php app/Models/TeacherProfile.php app/Models/StudentProfile.php app/Models/SchoolClass.php app/Models/Classroom.php app/Models/ClassRoutine.php app/Models/ClassVersion.php app/Models/Shift.php app/Models/Section.php app/Models/SubjectTeacherAssignment.php app/Models/TimetableEntry.php app/Models/AcademicYear.php tests/Unit/TenantScopeTest.php
git commit -m "Apply TenantScoped to the remaining 12 tenant-owned models"
```

---

### Task 3: Simplify the 13 `ChecksScope`-using Policies

**Files:**
- Modify: `backend/app/Policies/UserPolicy.php`
- Modify: `backend/app/Policies/SchoolPolicy.php`
- Modify: `backend/app/Policies/TeacherProfilePolicy.php`
- Modify: `backend/app/Policies/StudentProfilePolicy.php`
- Modify: `backend/app/Policies/ClassPolicy.php`
- Modify: `backend/app/Policies/AcademicYearPolicy.php`
- Modify: `backend/app/Policies/ClassroomPolicy.php`
- Modify: `backend/app/Policies/ClassRoutinePolicy.php`
- Modify: `backend/app/Policies/ClassVersionPolicy.php`
- Modify: `backend/app/Policies/ShiftPolicy.php`
- Modify: `backend/app/Policies/SectionPolicy.php`
- Modify: `backend/app/Policies/SubjectTeacherAssignmentPolicy.php`
- Modify: `backend/app/Policies/TimetableEntryPolicy.php`

**Interfaces:**
- Consumes: nothing new (Policies are called via Laravel's `Gate`/`authorize()`, already wired)
- Produces: every one of these 13 Policies' `create()` method now has the single-parameter shape `create(User $user): bool`, matching the 7 Policies that already had that shape (`DivisionPolicy`, `DistrictPolicy`, `UpazilaPolicy`, `SubjectPolicy`, `ChapterPolicy`, `RolePolicy`, `GuardianPolicy` — untouched by this task). **Plan B's controllers must call `$this->authorize('create', ModelClass::class)` (one argument) for all 20 models, not `$this->authorize('create', [ModelClass::class, $school])` — this is the interface Plan B depends on.**

- [ ] **Step 1: Simplify `backend/app/Policies/UserPolicy.php`** — remove the `inScope` checks (no `School`-parameter `create()` exists here already, so only `view`/`update`/`delete` change):

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('users.delete');
    }

    /**
     * Determine whether the user can change the target user's role/scope.
     * Same ability as a general update.
     */
    public function assignRole(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
```

- [ ] **Step 2: Simplify `backend/app/Policies/SchoolPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schools.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, School $school): bool
    {
        return $user->hasPermission('schools.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('schools.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, School $school): bool
    {
        return $user->hasPermission('schools.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, School $school): bool
    {
        return $user->hasPermission('schools.delete');
    }
}
```

- [ ] **Step 3: Simplify `backend/app/Policies/TeacherProfilePolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use App\Models\User;

class TeacherProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function view(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.create');
    }

    public function update(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.update');
    }

    public function delete(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.delete');
    }
}
```

- [ ] **Step 4: Simplify `backend/app/Policies/StudentProfilePolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function view(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_profiles.create');
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.update');
    }

    public function delete(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.delete');
    }
}
```

- [ ] **Step 5: Simplify `backend/app/Policies/ClassPolicy.php`** — this one's `create()` currently takes a `School $school` parameter; drop it:

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class ClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.update');
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.delete');
    }

    /**
     * Configuring a class's subject list is as sensitive as updating the
     * class itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(User $user, SchoolClass $class): bool
    {
        return $this->update($user, $class);
    }
}
```

- [ ] **Step 6: Simplify `backend/app/Policies/AcademicYearPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('academic_years.create');
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.update');
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.delete');
    }

    /**
     * Marking a year as the current one is as sensitive as updating it,
     * so it uses the same ability as `update`.
     */
    public function setCurrent(User $user, AcademicYear $academicYear): bool
    {
        return $this->update($user, $academicYear);
    }
}
```

- [ ] **Step 7: Simplify `backend/app/Policies/ClassroomPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classrooms.view');
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classrooms.create');
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.update');
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.delete');
    }
}
```

- [ ] **Step 8: Simplify `backend/app/Policies/ClassRoutinePolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\ClassRoutine;
use App\Models\User;

class ClassRoutinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function view(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('class_routines.create');
    }

    public function update(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.update');
    }

    public function delete(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.delete');
    }
}
```

- [ ] **Step 9: Simplify `backend/app/Policies/ClassVersionPolicy.php`** — also add an `updateSubjects` ability, mirroring `ClassPolicy`, since `ClassVersionController::updateSubjects()` needs one in Plan B:

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\ClassVersion;
use App\Models\User;

class ClassVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function view(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('class_versions.create');
    }

    public function update(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.update');
    }

    public function delete(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.delete');
    }

    /**
     * Configuring a version's subject list is as sensitive as updating
     * the version itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(User $user, ClassVersion $classVersion): bool
    {
        return $this->update($user, $classVersion);
    }
}
```

- [ ] **Step 10: Simplify `backend/app/Policies/ShiftPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shifts.view');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('shifts.create');
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.update');
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.delete');
    }
}
```

- [ ] **Step 11: Simplify `backend/app/Policies/SectionPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;

class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function view(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('sections.create');
    }

    public function update(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.update');
    }

    public function delete(User $user, Section $section): bool
    {
        return $user->hasPermission('sections.delete');
    }
}
```

- [ ] **Step 12: Simplify `backend/app/Policies/SubjectTeacherAssignmentPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\SubjectTeacherAssignment;
use App\Models\User;

class SubjectTeacherAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function view(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.create');
    }

    public function update(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.update');
    }

    public function delete(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.delete');
    }
}
```

- [ ] **Step 13: Simplify `backend/app/Policies/TimetableEntryPolicy.php`**

Replace the whole file with:
```php
<?php

namespace App\Policies;

use App\Models\TimetableEntry;
use App\Models\User;

class TimetableEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function view(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('timetable_entries.create');
    }

    public function update(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.update');
    }

    public function delete(User $user, TimetableEntry $entry): bool
    {
        return $user->hasPermission('timetable_entries.delete');
    }

    /**
     * Publishing/unpublishing a routine is as sensitive as updating it.
     */
    public function publish(User $user, TimetableEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
```

- [ ] **Step 14: Run the full test suite**

Run: `cd backend && php artisan test`
Expected: the `TenantScopeTest` tests from Tasks 1-2 still pass. Any pre-existing test that exercised a now-removed `inScope` rejection (e.g. asserting a 403 for a cross-tenant `create`/`view`/`update`/`delete` call) may now fail differently (403 vs 404, or passing where it previously failed) because the global scope changes the failure mode from "Policy denies" to "record not found" — note any such differences in your report; do not attempt to fix them in this task, they are expected consequences of this plan, not regressions.

- [ ] **Step 15: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Policies/UserPolicy.php app/Policies/SchoolPolicy.php app/Policies/TeacherProfilePolicy.php app/Policies/StudentProfilePolicy.php app/Policies/ClassPolicy.php app/Policies/AcademicYearPolicy.php app/Policies/ClassroomPolicy.php app/Policies/ClassRoutinePolicy.php app/Policies/ClassVersionPolicy.php app/Policies/ShiftPolicy.php app/Policies/SectionPolicy.php app/Policies/SubjectTeacherAssignmentPolicy.php app/Policies/TimetableEntryPolicy.php
git commit -m "Simplify 13 Policies: drop ChecksScope, collapse create() to one parameter"
```

---

### Task 4: Remove the 3 controllers' redundant `->visibleTo()` calls

**`ChecksScope` is NOT dead code and must NOT be deleted in this plan.**
`UserController` (`assertAssignable()`), `StudentProfileController`
(`store()`), and `TeacherProfileController` (`store()`) each use
`App\Policies\Concerns\ChecksScope`'s `inScope()` directly, independent of
the Policies — they validate a *request's input* `school_id`/`district_id`
against the actor's own scope before creating/assigning a row (e.g. "can
this school-scoped actor place a new user in school X?"). That is a
write-side input check the global scope cannot perform (the global scope
only filters what you can *fetch*, never what a client *submits*), so it
stays exactly as-is. Only the `->visibleTo()` call in each of these three
controllers' `index()` method — which filtered a *query result*, now done
automatically by the global scope — is removed.

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/UserController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/TeacherProfileController.php`

**Interfaces:**
- Consumes: Task 2's global scope being live on `User`/`StudentProfile`/`TeacherProfile` (the query filtering these controllers' `index()` methods relied on `->visibleTo()` for is now automatic)
- Produces: nothing new — this is the terminal cleanup task for this plan's controller/Policy changes.

- [ ] **Step 1: Remove the redundant `->visibleTo()` call from `backend/app/Http/Controllers/Api/V1/UserController.php`** — change ONLY this line in `index()`; leave every other use of `ChecksScope`/`inScope()` in this file (inside `assertAssignable()`) untouched:

Change:
```php
        $users = User::query()
            ->visibleTo(request()->user())
            ->with(['role.permissions', 'school', 'district'])
```
to:
```php
        $users = User::query()
            ->with(['role.permissions', 'school', 'district'])
```

- [ ] **Step 2: Remove the redundant `->visibleTo()` call from `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`** — change ONLY this line in `index()`; leave `store()`'s `inScope()` call untouched:

Change:
```php
        $profiles = StudentProfile::query()
            ->visibleTo($request->user())
            ->with(['user', 'school', 'district', 'schoolClass'])
```
to:
```php
        $profiles = StudentProfile::query()
            ->with(['user', 'school', 'district', 'schoolClass'])
```

- [ ] **Step 3: Remove the redundant `->visibleTo()` call from `backend/app/Http/Controllers/Api/V1/TeacherProfileController.php`** — change ONLY this line in `index()`; leave `store()`'s `inScope()` call untouched:

Change:
```php
        $profiles = TeacherProfile::query()
            ->visibleTo($request->user())
            ->with(['user', 'school', 'district'])
```
to:
```php
        $profiles = TeacherProfile::query()
            ->with(['user', 'school', 'district'])
```

- [ ] **Step 4: Run the full test suite**

Run: `cd backend && php artisan test`
Expected: no test fails due to a missing `visibleTo` method (Tasks 1-2 already removed the local scope methods it called; this step confirms no controller still references it). Report the full pass/fail summary — some pre-existing tests may already have been failing before this plan for unrelated reasons; distinguish those from anything newly broken by this specific change.

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/UserController.php app/Http/Controllers/Api/V1/StudentProfileController.php app/Http/Controllers/Api/V1/TeacherProfileController.php
git commit -m "Remove redundant ->visibleTo() calls now that the global scope applies automatically"
```

---

### Task 5: Cross-tenant leakage feature test (HTTP level)

**Files:**
- Test: `backend/tests/Feature/TenantScopeLeakageTest.php`

**Interfaces:**
- Consumes: `TenantScope` applied to `AcademicYear` (Tasks 1-2), `AcademicYearPolicy::delete()` (Task 3, unchanged ability name, simplified body)
- Produces: nothing consumed by a later task — this is a leaf test demonstrating the mechanism end-to-end over HTTP. Plan B adds its own per-controller leakage assertions as part of adding each controller's missing `authorize()` calls; this task does not attempt to cover all 13 models' HTTP endpoints exhaustively.

- [ ] **Step 1: Write the test** — create `backend/tests/Feature/TenantScopeLeakageTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Division;
use App\Models\District;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeLeakageTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $yearA;

    private AcademicYear $yearB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $this->yearA = AcademicYear::create([
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'name' => 'Year A',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->yearB = AcademicYear::create([
            'school_id' => $schoolB->id,
            'district_id' => $schoolB->district_id,
            'name' => 'Year B',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    public function test_actor_with_permission_can_delete_their_own_school_academic_year(): void
    {
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'school_admin_delete', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearA->id}");

        $response->assertNoContent();
    }

    public function test_actor_with_permission_gets_404_deleting_another_schools_academic_year(): void
    {
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'school_admin_delete_2', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearB->id}");

        $response->assertNotFound();
    }

    public function test_actor_without_permission_gets_403_deleting_their_own_school_academic_year(): void
    {
        $role = Role::create(['name' => 'school_admin_no_perm', 'label' => 'School Admin']);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearA->id}");

        $response->assertForbidden();
    }

    public function test_unscoped_actor_with_permission_can_delete_either_schools_academic_year(): void
    {
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'national_admin_delete', 'label' => 'National Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearB->id}");

        $response->assertNoContent();
    }
}
```

- [ ] **Step 2: Run the test**

Run: `cd backend && php artisan test tests/Feature/TenantScopeLeakageTest.php`
Expected: PASS (4 tests). This confirms the global scope's exact behavior at the HTTP layer: wrong tenant → 404 (before the controller's `authorize()` call ever runs, since route-model-binding itself fails), right tenant but no permission → 403, right tenant with permission → success, unscoped actor → success against any tenant.

- [ ] **Step 3: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add tests/Feature/TenantScopeLeakageTest.php
git commit -m "Add HTTP-level cross-tenant leakage test"
```
