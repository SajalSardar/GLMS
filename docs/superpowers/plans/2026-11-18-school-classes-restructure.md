# Restructure school_classes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `school_classes` a national class-name list owned by `national_admin` (id, name, bn_name, numeric_level only), introduce a new `class_schools` pivot recording which schools offer which classes, and move the `class_subjects` pivot (real validation logic, not decoration) from a global fact to a per-(school, class) fact.

**Architecture:** Build the new `class_schools`/`class_subjects` mechanism first, fully additive, while `school_classes` still has its own `school_id`/`district_id` (Tasks 1–2). Only once that mechanism exists do we cut `school_classes` over to global and fix every consumer that currently reads `school_classes.school_id`/`.district_id` — `ClassVersionController`, `ClassRoutineController`, `StudentProfileController`, and three seeders — in the same task, because leaving any of them broken fails the full suite immediately (Task 3). A final task verifies the whole thing against real MySQL (Task 4).

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit, MySQL (production parity — see sub-project 1's Task 10 for why).

**Spec:** `docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md`

## Global Constraints

- No production data exists for `school_classes`/`class_schools`/`class_subjects` — confirmed during brainstorming (same codebase state as sub-project 1). Migrations are simple structural changes, not data migrations.
- `ClassVersion`, `Shift`, `Section` keep their own schema and tenant columns unchanged — only their *validation logic* against the new `class_schools` gate changes (Task 3), not their own columns.
- `version_subjects` (`ClassVersion` ↔ `Subject`) is untouched — unrelated to this restructuring beyond both being "subject assignment" pivots.
- This app has no `/schools/{school}/...` nested-route convention anywhere (confirmed in `routes/api.php`) — the new `class_schools` resource is a flat top-level resource (`/class-schools`), matching `/class-versions`, `/shifts`, `/sections`.
- Follow this repo's migration convention: explicit `dropForeign(['col'])` + `dropColumn('col')` for drops; `$table->foreignId('col')->constrained()->cascadeOnDelete()` for adds.
- Run `vendor/bin/pint --dirty --format agent` after modifying any PHP file in each task, before committing.
- No frontend work in this plan (deferred to sub-project 4, which must explicitly pick up `ClassesPage.tsx`, `ClassVersionsPage.tsx` — now a hard break: it posts only `{class_id, name}` with no `school_id`, so every class-version creation will 403 once this branch merges, since `StoreClassVersionRequest::authorize()` requires `school_id` — and `StudentDetailPage.tsx`, whose class picker is now fed by the unfiltered national class list, so a user could pick a class their school hasn't adopted, which now 422s. More broadly, `GET /api/v1/classes` dropped its `school_id`/`district_id` query-param filters entirely, so any frontend code relying on implicit per-school filtering there needs to go through `/class-schools?school_id=` instead — this affects more of the frontend surface than just these three pages).

---

## Task 1: Build the `class_schools` assignment resource

**Files:**
- Create: `backend/database/migrations/2026_11_19_100000_create_class_schools_table.php`
- Create: `backend/app/Models/ClassSchool.php`
- Create: `backend/app/Policies/ClassSchoolPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreClassSchoolRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/ClassSchoolResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/ClassSchoolController.php`
- Modify: `backend/app/Models/School.php`
- Modify: `backend/app/Models/SchoolClass.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/ClassSchoolControllerTest.php`
- Create: `backend/tests/Feature/ClassSchoolsPermissionMappingTest.php`

**Interfaces:**
- Produces: `ClassSchool` model (`TenantScoped`), `/class-schools` resource with `index`/`store`/`destroy` (no subjects action yet — Task 2 adds it). `school_classes` keeps its current `school_id`/`district_id`/`section` columns untouched in this task — this task is purely additive.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/ClassSchoolControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ClassSchoolControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $classSix;

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

        $this->classSix = SchoolClass::create(['name' => 'Class Six', 'numeric_level' => 6]);
    }

    public function test_store_and_destroy_are_authorized(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_schools.create', 'class_schools.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $created = $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertCreated()
            ->assertJsonPath('data.class_id', $this->classSix->id)
            ->assertJsonPath('data.school_id', $this->schoolA->id);

        $classSchoolId = $created->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/class-schools/{$classSchoolId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('class_schools', ['id' => $classSchoolId]);
    }

    public function test_index_is_tenant_scoped(): void
    {
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $actor = $this->actorWithPermissions(['class_schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-schools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.school_id', $this->schoolA->id);
    }

    public function test_store_rejects_a_duplicate_assignment(): void
    {
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $allowed = $this->actorWithPermissions(['class_schools.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/class-schools', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id])
            ->assertUnprocessable();
    }

    public function test_destroy_is_blocked_while_a_class_version_exists_for_this_assignment(): void
    {
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassVersion::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);

        $allowed = $this->actorWithPermissions(['class_schools.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/class-schools/{$assignment->id}")
            ->assertUnprocessable();
    }
}
```

Create `backend/tests/Feature/ClassSchoolsPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSchoolsPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * class_schools.create is mapped to exactly the roles that may manage
     * a school's own class offering — school_admin (their own school) and
     * national_admin via its all-permissions wildcard.
     */
    public function test_class_schools_create_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'class_schools.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin', 'school_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ClassSchoolControllerTest --compact` and `php artisan test --filter=ClassSchoolsPermissionMappingTest --compact`
Expected: FAIL — `/api/v1/class-schools` routes don't exist yet (404), `ClassSchool` class doesn't exist, `class_schools.*` permissions don't exist.

- [ ] **Step 3: Create the migration**

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
     * Records which schools have adopted which national class names —
     * the gate that replaces the implicit "a class belongs to one
     * school" fact school_classes.school_id used to carry before
     * school_classes became national content (see
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md).
     */
    public function up(): void
    {
        Schema::create('class_schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // Denormalized from the school at creation time (never taken
            // directly from the request) — same convention as every other
            // TenantScoped table in this app.
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_id', 'school_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_schools');
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Create the `ClassSchool` model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSchool extends Model
{
    use Auditable, TenantScoped;

    protected $fillable = [
        'class_id',
        'school_id',
        'district_id',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
```

(No `SoftDeletes` — unlike the content tables in sub-project 1, a `class_schools` row has no downstream content that needs a trashed/restore lifecycle; `destroy()` is a hard delete, matching this table's own migration having no `softDeletes()` column.)

- [ ] **Step 5: Add relations on `School` and `SchoolClass`**

In `backend/app/Models/School.php`, replace:

```php
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
```

with:

```php
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_schools', 'school_id', 'class_id');
    }
```

Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to the imports; remove `use Illuminate\Database\Eloquent\Relations\HasMany;` only if nothing else in the file still uses `HasMany` (check `users()`/`classrooms()` — they do, so keep the import).

In `backend/app/Models/SchoolClass.php`, add a new relation:

```php
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'class_schools', 'class_id', 'school_id');
    }
```

(Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to this file's imports. `SchoolClass` still has its `school_id`/`district_id`/`section` columns and `TenantScoped` at this point in the plan — this task does not touch any of that; it only adds the new relation alongside the existing ones.)

- [ ] **Step 6: Add permissions and policy**

In `backend/database/seeders/PermissionSeeder.php`, add after the `classes.*` block (after line 43's `classes.delete` entry):

```php
            ['name' => 'class_schools.view', 'label' => 'View class-school assignments'],
            ['name' => 'class_schools.create', 'label' => 'Assign a class to a school'],
            ['name' => 'class_schools.delete', 'label' => 'Unassign a class from a school'],
```

(No `class_schools.update` — nothing on this row is mutable beyond the subject list, which gets its own `class_schools.updateSubjects` permission in Task 2, not a general `.update`.)

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'school_admin'` array, add right after the `'classes.view', 'classes.create', 'classes.update', 'classes.delete',` line:

```php
                'class_schools.view', 'class_schools.create', 'class_schools.delete',
```

(Leave the existing `classes.*` line untouched in this task — narrowing it to `national_admin`-only happens in Task 3, alongside the `school_classes` cutover itself, not here.)

Create `backend/app/Policies/ClassSchoolPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\ClassSchool;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassSchoolPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('class_schools.view');
    }

    public function view(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('class_schools.create');
    }

    public function delete(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.delete');
    }
}
```

- [ ] **Step 7: Create the request and resource**

Create `backend/app/Http/Requests/Api/V1/StoreClassSchoolRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassSchoolRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchool::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'school_id' => [
                'required', 'integer', Rule::exists('schools', 'id')->withoutTrashed(),
                Rule::unique('class_schools', 'school_id')->where(fn ($query) => $query->where('class_id', $this->integer('class_id'))),
            ],
        ];
    }
}
```

Create `backend/app/Http/Resources/Api/V1/ClassSchoolResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassSchoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'updated_by_type' => $this->updated_by_type,
            'updated_by_id' => $this->updated_by_id,
            'class_id' => $this->class_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'school' => new SchoolResource($this->whenLoaded('school')),
        ];
    }
}
```

(No `subjects` key yet — Task 2 adds it once `class_subjects` has a `school_id` column to back it.)

- [ ] **Step 8: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassSchoolRequest;
use App\Http\Resources\Api\V1\ClassSchoolResource;
use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClassSchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = ClassSchool::query()
            ->with(['schoolClass', 'school'])
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->orderByDesc('id')
            ->get();

        return ClassSchoolResource::collection($assignments);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClassSchool $class_school): ClassSchoolResource
    {
        return new ClassSchoolResource($class_school->load(['schoolClass', 'school']));
    }

    /**
     * Store a newly created resource in storage: assign a class to a
     * school.
     */
    public function store(StoreClassSchoolRequest $request): JsonResponse
    {
        $this->authorize('create', ClassSchool::class);

        $school = School::findOrFail($request->integer('school_id'));

        $assignment = ClassSchool::create([
            'class_id' => $request->integer('class_id'),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ]);

        return (new ClassSchoolResource($assignment->load(['schoolClass', 'school'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Remove the specified resource from storage: unassign a class from a
     * school. Blocked while the school still has a ClassVersion under
     * this class — removing the assignment would orphan live curriculum
     * data.
     */
    public function destroy(ClassSchool $class_school): Response
    {
        $this->authorize('delete', $class_school);

        if (ClassVersion::where('class_id', $class_school->class_id)->where('school_id', $class_school->school_id)->exists()) {
            throw ValidationException::withMessages([
                'class_school' => ['This school still has class versions under this class and cannot be unassigned.'],
            ]);
        }

        $class_school->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 9: Register routes**

In `backend/routes/api.php`, add to the public (`only(['index', 'show'])`) section, alongside the other flat resources:

```php
    Route::apiResource('class-schools', ClassSchoolController::class)->only(['index', 'show']);
```

And to the `auth:sanctum` group, alongside the other mutating-verb registrations:

```php
        Route::apiResource('class-schools', ClassSchoolController::class)->only(['store', 'destroy']);
```

Add `use App\Http\Controllers\Api\V1\ClassSchoolController;` to the file's imports if it uses explicit `use` statements per-controller (check the existing import style in this file before adding — follow whatever convention is already there).

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --filter=ClassSchoolControllerTest --compact`, `php artisan test --filter=ClassSchoolsPermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 11: Run the full suite, format, and commit**

Run: `php artisan test --compact` — expect everything still green (this task is purely additive; nothing existing should break).

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_19_100000_create_class_schools_table.php app/Models/ClassSchool.php app/Models/School.php app/Models/SchoolClass.php app/Policies/ClassSchoolPolicy.php app/Http/Requests/Api/V1/StoreClassSchoolRequest.php app/Http/Resources/Api/V1/ClassSchoolResource.php app/Http/Controllers/Api/V1/ClassSchoolController.php database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php routes/api.php tests/Feature/ClassSchoolControllerTest.php tests/Feature/ClassSchoolsPermissionMappingTest.php
git commit -m "Add class_schools: per-school class assignment resource"
```

---

## Task 2: Move subject assignment from `class_subjects` (global) to per-(school, class)

**Files:**
- Create: `backend/database/migrations/2026_11_19_100100_add_school_id_to_class_subjects_table.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassSchoolController.php`
- Modify: `backend/app/Policies/ClassPolicy.php`
- Modify: `backend/app/Policies/ClassSchoolPolicy.php`
- Delete: `backend/app/Http/Requests/Api/V1/UpdateClassSubjectsRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateClassSchoolSubjectsRequest.php`
- Modify: `backend/app/Http/Resources/Api/V1/ClassSchoolResource.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Unit/ControllerAuthorizationConventionTest.php`
- Modify: `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`
- Modify: `backend/tests/Feature/ClassSchoolControllerTest.php`

**Interfaces:**
- Consumes: `ClassSchool`/`ClassSchoolController` from Task 1.
- Produces: `class_subjects` has a `school_id` column; subject-list management for a class moves from `PUT /classes/{class}/subjects` to `PUT /class-schools/{class_school}/subjects`. Task 3's `ClassRoutineController` fix depends on `class_subjects.school_id` existing.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/ClassSchoolControllerTest.php`, add (after the existing tests, inside the class):

```php
    public function test_update_subjects_is_authorized_and_syncs_the_schools_offering(): void
    {
        $subjectMath = \App\Models\Subject::create(['name' => 'Math']);
        $subjectScience = \App\Models\Subject::create(['name' => 'Science']);
        $assignment = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignment->id}/subjects", ['subject_ids' => [$subjectMath->id]])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_schools.updateSubjects'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignment->id}/subjects", ['subject_ids' => [$subjectMath->id, $subjectScience->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.subjects');

        $this->assertDatabaseHas('class_subjects', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subjectMath->id]);
    }

    public function test_update_subjects_does_not_affect_another_schools_offering_of_the_same_class(): void
    {
        $subject = \App\Models\Subject::create(['name' => 'Math']);
        $assignmentA = ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        ClassSchool::create(['class_id' => $this->classSix->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $allowed = $this->actorWithPermissions(['class_schools.updateSubjects'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')
            ->putJson("/api/v1/class-schools/{$assignmentA->id}/subjects", ['subject_ids' => [$subject->id]])
            ->assertOk();

        $this->assertDatabaseCount('class_subjects', 1);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $this->classSix->id, 'school_id' => $this->schoolA->id, 'subject_id' => $subject->id]);
    }
```

In `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`, the existing `test_class_store_update_and_update_subjects_are_authorized` test currently does:

```php
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertForbidden();
        ...
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertOk();
```

Remove both of these lines (and rename the test to `test_class_store_and_update_are_authorized`, dropping "and_update_subjects" from the name) — `PUT /classes/{class}/subjects` no longer exists; its coverage moves to `ClassSchoolControllerTest` above.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ClassSchoolControllerTest --compact`
Expected: FAIL — `class_subjects` has no `school_id` column yet, `PUT /class-schools/{class_school}/subjects` route doesn't exist, `class_schools.updateSubjects` permission doesn't exist.

- [ ] **Step 3: Create the migration**

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
     * class_subjects moves from a global fact ("this class teaches these
     * subjects, everywhere") to a per-school fact ("this school's
     * offering of this class teaches these subjects") — matches how the
     * parallel version_subjects (ClassVersion <-> Subject) pivot already
     * works per-school. See
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md.
     */
    public function up(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'subject_id']);
            $table->foreignId('school_id')->after('subject_id')->constrained()->cascadeOnDelete();
            $table->unique(['class_id', 'subject_id', 'school_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'subject_id', 'school_id']);
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
            $table->unique(['class_id', 'subject_id']);
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Remove the old `ClassController::updateSubjects()` action**

In `backend/app/Http/Controllers/Api/V1/ClassController.php`, remove the entire `updateSubjects()` method:

```php
    /**
     * Replace the class's configured subject list.
     */
    public function updateSubjects(UpdateClassSubjectsRequest $request, SchoolClass $class): ClassResource
    {
        $this->authorize('updateSubjects', $class);

        $class->subjects()->sync($request->validated('subject_ids'));

        return new ClassResource($class->load(['school', 'subjects']));
    }
```

Remove the now-unused `use App\Http\Requests\Api\V1\UpdateClassSubjectsRequest;` import.

Delete `backend/app/Http/Requests/Api/V1/UpdateClassSubjectsRequest.php`.

In `backend/app/Policies/ClassPolicy.php`, remove the `updateSubjects()` method entirely (it's no longer called by anything).

In `backend/routes/api.php`, remove:

```php
        Route::put('/classes/{class}/subjects', [ClassController::class, 'updateSubjects']);
```

(Check the exact line — it may be registered via a dedicated `Route::put` call near the `classes` resource registration, or bundled differently; find and remove whatever currently routes to `ClassController::updateSubjects`.)

- [ ] **Step 5: Add the new `ClassSchoolController::updateSubjects()` action**

In `backend/app/Http/Controllers/Api/V1/ClassSchoolController.php`, add:

```php
    /**
     * Replace this school's subject list for this class.
     */
    public function updateSubjects(UpdateClassSchoolSubjectsRequest $request, ClassSchool $class_school): ClassSchoolResource
    {
        $this->authorize('updateSubjects', $class_school);

        DB::table('class_subjects')->where('class_id', $class_school->class_id)->where('school_id', $class_school->school_id)->delete();

        $rows = collect($request->validated('subject_ids'))->map(fn (int $subjectId) => [
            'class_id' => $class_school->class_id,
            'subject_id' => $subjectId,
            'school_id' => $class_school->school_id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('class_subjects')->insert($rows);
        }

        return new ClassSchoolResource($class_school->load(['schoolClass', 'school', 'subjects']));
    }
```

Add `use App\Http\Requests\Api\V1\UpdateClassSchoolSubjectsRequest;` and `use Illuminate\Support\Facades\DB;` to this file's imports.

In `backend/app/Policies/ClassSchoolPolicy.php`, add:

```php
    /**
     * Configuring this assignment's subject list is as sensitive as
     * creating/deleting the assignment itself, so it gets its own
     * permission rather than reusing create/delete.
     */
    public function updateSubjects(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.updateSubjects');
    }
```

Create `backend/app/Http/Requests/Api/V1/UpdateClassSchoolSubjectsRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassSchoolSubjectsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('updateSubjects', $this->route('class_school')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject_ids' => ['present', 'array'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->withoutTrashed()],
        ];
    }
}
```

In `backend/app/Http/Resources/Api/V1/ClassSchoolResource.php`, add a `subjects` key:

```php
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
```

This requires a `subjects()` relation on `ClassSchool` — add to `backend/app/Models/ClassSchool.php`:

```php
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id')
            ->wherePivot('school_id', $this->school_id);
    }
```

(Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to this file's imports. Note this relation's `wherePivot('school_id', $this->school_id)` only works correctly when called on an already-loaded `ClassSchool` instance with its `school_id` set — which is always true here, since it's only ever accessed via `$class_school->load('subjects')` on a specific row, never as a bare class-level relation.)

- [ ] **Step 6: Add the new permission and route**

In `backend/database/seeders/PermissionSeeder.php`, add after the `class_schools.delete` line from Task 1:

```php
            ['name' => 'class_schools.updateSubjects', 'label' => 'Configure a school\'s subject list for a class'],
```

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'school_admin'` array, change the line Task 1 added:

```php
                'class_schools.view', 'class_schools.create', 'class_schools.delete',
```

to:

```php
                'class_schools.view', 'class_schools.create', 'class_schools.delete', 'class_schools.updateSubjects',
```

In `backend/routes/api.php`, add to the `auth:sanctum` group, alongside the `class-schools` registrations from Task 1:

```php
        Route::put('/class-schools/{class_school}/subjects', [ClassSchoolController::class, 'updateSubjects']);
```

- [ ] **Step 7: Update the authorization-convention test**

In `backend/tests/Unit/ControllerAuthorizationConventionTest.php`, find the entry:

```php
            [ClassController::class, 'updateSubjects'],
```

Remove it. Add, near the other `ClassSchoolController`-adjacent entries (or wherever this file groups entries — follow its existing ordering convention, likely alphabetical by controller):

```php
            [ClassSchoolController::class, 'store'],
            [ClassSchoolController::class, 'destroy'],
            [ClassSchoolController::class, 'updateSubjects'],
```

Add `use App\Http\Controllers\Api\V1\ClassSchoolController;` to this file's imports if not already present from how it's structured (check first — it may already import controllers alphabetically and need insertion at the right spot).

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=ClassSchoolControllerTest --compact`, `php artisan test --filter=ClassAndVersionAuthorizationTest --compact`, `php artisan test --filter=ControllerAuthorizationConventionTest --compact`
Expected: PASS

- [ ] **Step 9: Run the full suite, format, and commit**

Run: `php artisan test --compact` — expect green.

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_19_100100_add_school_id_to_class_subjects_table.php app/Http/Controllers/Api/V1/ClassController.php app/Http/Controllers/Api/V1/ClassSchoolController.php app/Policies/ClassPolicy.php app/Policies/ClassSchoolPolicy.php app/Http/Requests/Api/V1/UpdateClassSchoolSubjectsRequest.php app/Http/Resources/Api/V1/ClassSchoolResource.php app/Models/ClassSchool.php database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php routes/api.php tests/Unit/ControllerAuthorizationConventionTest.php tests/Feature/ClassAndVersionAuthorizationTest.php tests/Feature/ClassSchoolControllerTest.php
git rm app/Http/Requests/Api/V1/UpdateClassSubjectsRequest.php
git commit -m "Move class-subject assignment from global to per-(school, class)"
```

---

## Task 3: Cut `school_classes` over to a national list and fix every consumer

**Files:**
- Create: `backend/database/migrations/2026_11_19_100200_drop_tenant_columns_from_school_classes_table.php`
- Modify: `backend/app/Models/SchoolClass.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassController.php`
- Modify: `backend/app/Http/Requests/Api/V1/StoreClassRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/UpdateClassRequest.php`
- Modify: `backend/app/Http/Resources/Api/V1/ClassResource.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Create: `backend/tests/Feature/ClassesAuthoringPermissionMappingTest.php`
- Modify: `backend/tests/Feature/ClassListPaginationTest.php`
- Modify: `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassVersionController.php`
- Modify: `backend/app/Http/Requests/Api/V1/StoreClassVersionRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassRoutineController.php`
- Modify: `backend/app/Http/Requests/Api/V1/StoreClassRoutineRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`
- Modify: `backend/database/seeders/ClassSeeder.php`
- Modify: `backend/database/seeders/ClassSubjectSeeder.php`
- Modify: `backend/database/seeders/SectionBackfillSeeder.php`
- Test: every file above that has its own existing test suite, plus `backend/tests/Feature/ClassVersionShiftSectionPaginationTest.php`, `backend/tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`, `backend/tests/Feature/StudentProfileAuthorizationTest.php`, `backend/tests/Feature/StudentUserAuthorizationTest.php` (read each before assuming which fixtures break — see Step 5)

**Interfaces:**
- Consumes: `ClassSchool`/`class_schools` (Tasks 1–2) as the new gate that replaces `school_classes.school_id`.
- Produces: `school_classes` with no tenant columns; `ClassVersionController`/`ClassRoutineController` both require an explicit `school_id` validated against `class_schools`; `StudentProfileController`'s class-ownership check queries `class_schools`.

This is the biggest task in this plan — it is the one place every consumer of `school_classes.school_id` breaks at once. Work through it in the order below; each numbered group is roughly one commit.

### Group A — cut `school_classes` itself over to national

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`'s class-creation half. The file's `setUp()` currently has no `SchoolClass::create()` call of its own in `test_class_store_update_and_update_subjects_are_authorized` (renamed in Task 2 to `test_class_store_and_update_are_authorized`) — read the current file first (Task 2 already edited it once), then update that one remaining test:

```php
    public function test_class_store_and_update_are_authorized(): void
    {
        $class = SchoolClass::create(['name' => 'Existing Class']);
        $payload = ['name' => 'New Class'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classes', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }
```

(`actorWithPermissions(['classes.create', 'classes.update'])` with no school/district args — `national_admin`'s own actors in this app are unscoped, matching the pattern every sub-project-1 `*AuthoringPermissionMappingTest`-adjacent test already uses for national-content actors.)

`test_class_version_store_update_and_update_subjects_are_authorized` in the same file still creates `SchoolClass::create(['school_id' => ..., 'district_id' => ..., 'name' => 'Class'])` — this is Group B's concern (`ClassVersionController` needs the new explicit `school_id` + `class_schools` row), fix it there, not here, to keep this group's diff focused on `school_classes` itself.

Create `backend/tests/Feature/ClassesAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassesAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_classes_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'classes.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ClassesAuthoringPermissionMappingTest --compact`
Expected: FAIL — `classes.create` is still mapped to `school_admin` too.

- [ ] **Step 3: Create the migration**

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
     * school_classes becomes a national list of class names — the same
     * class exists once, shared by every school that adopts it via the
     * new class_schools pivot (see
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md).
     * `section` (a per-school curriculum stream) is dropped outright, not
     * relocated — that distinction is already expressible through the
     * existing ClassVersion/Shift/Section hierarchy.
     */
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropUnique(['school_id', 'name', 'section']);
            $table->dropColumn(['school_id', 'district_id', 'section']);

            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['name']);

            $table->foreignId('school_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable();

            $table->unique(['school_id', 'name', 'section']);
        });
    }
};
```

Run: `php artisan migrate`. **Note:** this will immediately break several other things — expect the full suite to go red until Groups B–D in this same task are done. Do not stop and commit here; continue through the rest of this task first.

- [ ] **Step 4: Update the model, controller, requests, resource, and permission seeder**

In `backend/app/Models/SchoolClass.php`, remove `TenantScoped` from the `use` list and its import, remove `school()`/`district()` relations, remove `'section'` from `$fillable`. Keep the `schools()` relation added in Task 1 and the existing `studentProfiles()`/`routines()`/`versions()` relations unchanged.

In `backend/app/Http/Controllers/Api/V1/ClassController.php`:
- `index()` — remove the `?school_id=`/`?district_id=` filters.
- `store()` — remove `School::findOrFail($request->integer('school_id'))` and the `'school_id'`/`'district_id'` entries from `$attributes`; the soft-deleted-restore lookup changes from `where('school_id', ...)->where('name', ...)->when($section ...)` to `where('name', $attributes['name'])` alone (the `$section` local variable and its `when()` clause are removed entirely).
- Remove the now-unused `use App\Models\School;` import.

In `backend/app/Http/Requests/Api/V1/StoreClassRequest.php`, replace in full:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', SchoolClass::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('school_classes', 'name')->withoutTrashed()],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'numeric_level' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
```

In `backend/app/Http/Requests/Api/V1/UpdateClassRequest.php`, replace the `name` rule:

```php
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('school_classes', 'name')->ignore($class)],
```

(drop the `$class = $this->route('class');` line entirely if nothing else in the file uses `$class` after this change — check before removing) and remove `'section' => [...]` from the rules array.

In `backend/app/Http/Resources/Api/V1/ClassResource.php`, remove `'school_id'`, `'district_id'`, `'section'`, and `'school'` keys. `'subjects'` key stays but will now only ever be empty when loaded on a bare `SchoolClass` (no direct relation backs it anymore — this is fine, it's dead weight that can be removed in a later cleanup pass, not required for this task).

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'school_admin'` array, change:

```php
                'classes.view', 'classes.create', 'classes.update', 'classes.delete',
```

to:

```php
                'classes.view',
```

- [ ] **Step 5: Read and understand the ripple before fixing it**

Before writing any more code, read the FULL current content of these four files — the snippets in this plan describe them accurately as of this plan's writing, but confirm nothing has drifted:
- `backend/app/Http/Controllers/Api/V1/ClassVersionController.php`
- `backend/app/Http/Requests/Api/V1/StoreClassVersionRequest.php`
- `backend/app/Http/Controllers/Api/V1/ClassRoutineController.php`
- `backend/app/Http/Requests/Api/V1/StoreClassRoutineRequest.php`
- `backend/app/Http/Controllers/Api/V1/StudentProfileController.php` (specifically `assertClassBelongsToSchool()`)

Then proceed to Groups B, C, D below.

### Group B — fix `ClassVersionController`

- [ ] **Step 6: Update `StoreClassVersionRequest`**

Replace in full:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $classSchool = ClassSchool::where('class_id', $this->integer('class_id'))
            ->where('school_id', $this->integer('school_id'))
            ->first();

        return $classSchool !== null && ($this->user()?->can('create', [ClassVersion::class, $classSchool->school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('class_versions', 'name')->withoutTrashed()->where('class_id', $this->integer('class_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * class_id/school_id existing individually isn't enough — the school
     * must actually have adopted this class via class_schools before a
     * version can be created under it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['class_id', 'school_id'])) {
                return;
            }

            $exists = ClassSchool::where('class_id', $this->integer('class_id'))
                ->where('school_id', $this->integer('school_id'))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('class_id', 'This school has not adopted this class.');
            }
        });
    }
}
```

- [ ] **Step 7: Update `ClassVersionController::store()`**

Replace:

```php
        $class = SchoolClass::findOrFail($request->integer('class_id'));

        $attributes = [
            'name' => $request->string('name')->toString(),
            'bn_name' => $request->input('bn_name'),
            'is_active' => $request->boolean('is_active', true),
            'class_id' => $class->id,
            'school_id' => $class->school_id,
            'district_id' => $class->district_id,
        ];
```

with:

```php
        $school = School::findOrFail($request->integer('school_id'));

        $attributes = [
            'name' => $request->string('name')->toString(),
            'bn_name' => $request->input('bn_name'),
            'is_active' => $request->boolean('is_active', true),
            'class_id' => $request->integer('class_id'),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];
```

(The `withValidator` check above already guarantees the `class_schools` row exists before `store()` runs, so no additional check is needed here — `school_id` is now resolved from the explicit request field via `School::findOrFail`, exactly like `ClassroomController::store()`.) Add `use App\Models\School;` to this file's imports; remove `use App\Models\SchoolClass;` only if nothing else in the file still references `SchoolClass` directly (check the rest of the file first — `index()`'s filters and other methods may not need it, but `update()` doesn't touch `SchoolClass` either, so it likely becomes fully unused).

- [ ] **Step 8: Fix the test fixtures**

In `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`, `test_class_version_store_update_and_update_subjects_are_authorized` currently does:

```php
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'name' => 'New Version'];
```

Change to:

```php
        $class = SchoolClass::create(['name' => 'Class']);
        \App\Models\ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'school_id' => $this->school->id, 'name' => 'New Version'];
```

The `updateSubjects` lines in this same test (`PUT /class-versions/{version}/subjects`) are unrelated to this plan — `version_subjects` is untouched — leave them exactly as they are.

Read `backend/tests/Feature/ClassVersionShiftSectionPaginationTest.php` in full and fix every `SchoolClass::create(['school_id' => ..., 'district_id' => ..., ...])` call the same way: drop `school_id`/`district_id` from the `SchoolClass::create()` call, add a `ClassSchool::create(['class_id' => $class->id, 'school_id' => ..., 'district_id' => ...])` row immediately after for whichever school that class needs to belong to, and add `'school_id' => ...` to any `postJson('/api/v1/class-versions', [...])` payload in that file.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --filter=ClassAndVersionAuthorizationTest --compact`, `php artisan test --filter=ClassVersionShiftSectionPaginationTest --compact`
Expected: PASS

### Group C — fix `ClassRoutineController`

- [ ] **Step 10: Update `StoreClassRoutineRequest`**

Add a `school_id` field, following the exact same pattern as Group B's `StoreClassVersionRequest` fix — add to `rules()`:

```php
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
```

and add a `withValidator()` method identical in shape to `StoreClassVersionRequest`'s (checking `ClassSchool::where('class_id', ...)->where('school_id', ...)->exists()`, adding an error on `class_id` if it doesn't). Update `authorize()`:

```php
    public function authorize(): bool
    {
        $classSchool = \App\Models\ClassSchool::where('class_id', $this->integer('class_id'))
            ->where('school_id', $this->integer('school_id'))
            ->first();

        return $classSchool !== null && ($this->user()?->can('create', [ClassRoutine::class, $classSchool->school]) ?? false);
    }
```

(Add `use App\Models\ClassSchool;` and `use Illuminate\Validation\Validator;` to this file's imports.)

- [ ] **Step 11: Update `ClassRoutineController`**

In `store()`, replace:

```php
        $class = SchoolClass::findOrFail($request->integer('class_id'));

        $attributes = [
            'subject_id' => $request->integer('subject_id'),
            // Explicit null fallback rather than request->safe()->only():
            // a `nullable` (non-`sometimes`) rule drops the key entirely
            // from validated() when absent, which previously left these
            // missing from $attributes instead of set to null.
            'teacher_id' => $request->filled('teacher_id') ? $request->integer('teacher_id') : null,
            'classroom_id' => $request->filled('classroom_id') ? $request->integer('classroom_id') : null,
            'academic_year_id' => $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
            'day_of_week' => $request->string('day_of_week')->toString(),
            'period_no' => $request->integer('period_no'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'class_id' => $class->id,
            'school_id' => $class->school_id,
            'district_id' => $class->district_id,
        ];

        $this->assertValid($class, $attributes);
        $this->assertNoConflicts($attributes);
```

with:

```php
        $class = SchoolClass::findOrFail($request->integer('class_id'));
        $school = School::findOrFail($request->integer('school_id'));

        $attributes = [
            'subject_id' => $request->integer('subject_id'),
            // Explicit null fallback rather than request->safe()->only():
            // a `nullable` (non-`sometimes`) rule drops the key entirely
            // from validated() when absent, which previously left these
            // missing from $attributes instead of set to null.
            'teacher_id' => $request->filled('teacher_id') ? $request->integer('teacher_id') : null,
            'classroom_id' => $request->filled('classroom_id') ? $request->integer('classroom_id') : null,
            'academic_year_id' => $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
            'day_of_week' => $request->string('day_of_week')->toString(),
            'period_no' => $request->integer('period_no'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];

        $this->assertValid($class, $attributes, $school->id);
        $this->assertNoConflicts($attributes);
```

Add `use App\Models\School;` to this file's imports.

In `update()`, the call `$this->assertValid($class, $merged);` becomes `$this->assertValid($class, $merged, $class_routine->school_id);` (the routine's `school_id` is immutable after creation, per this request's own existing doc comment — no new resolution needed here, just pass the value that's already on the row).

Update `assertValid()`'s signature and body:

```php
    /**
     * Cross-tenant and configuration guards: the subject must actually be
     * configured for this school's offering of this class, and a
     * teacher/classroom given by id must belong to the same school as the
     * routine — never trusted from client input alone.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(SchoolClass $class, array $attributes, int $schoolId): void
    {
        $subjectConfigured = DB::table('class_subjects')
            ->where('class_id', $class->id)
            ->where('school_id', $schoolId)
            ->where('subject_id', $attributes['subject_id'])
            ->exists();

        if (! $subjectConfigured) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject is not configured for the selected class.'],
            ]);
        }

        if ($attributes['teacher_id'] !== null) {
            $teacher = TeacherUser::find($attributes['teacher_id']);

            if (! $teacher || $teacher->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    'teacher_id' => ['The selected teacher does not belong to this class\'s school.'],
                ]);
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    'classroom_id' => ['The selected classroom does not belong to this class\'s school.'],
                ]);
            }
        }
    }
```

Add `use Illuminate\Support\Facades\DB;` to this file's imports. The `Subject` import may now be unused — check the rest of the file before removing it.

- [ ] **Step 12: Fix the test fixtures**

Read `backend/tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php` in full. Every `SchoolClass::create(['school_id' => ..., ...])` call needs the same treatment as Group B's Step 8: drop `school_id`/`district_id` from the `SchoolClass::create()` call, add a matching `ClassSchool::create([...])` row, add `class_subjects` rows (via `DB::table('class_subjects')->insert([...])` or however this test already wires up subject configuration for its class routines — read how it currently calls `$class->subjects()->sync(...)` or similar, since that relation may already be exercised in this file's `setUp()`) scoped to the right `school_id`, and add `'school_id' => ...` to every `postJson('/api/v1/class-routines', [...])` payload.

- [ ] **Step 13: Run the tests to verify they pass**

Run: `php artisan test --filter=RoutineAssignmentTimetableAuthorizationTest --compact`
Expected: PASS

### Group D — fix `StudentProfileController` and the three seeders

- [ ] **Step 14: Update `StudentProfileController::assertClassBelongsToSchool()`**

Replace:

```php
    private function assertClassBelongsToSchool(?int $classId, ?int $schoolId): void
    {
        if ($classId === null) {
            return;
        }

        if (SchoolClass::where('id', $classId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'class_id' => ['The selected class does not belong to the student\'s school.'],
            ]);
        }
    }
```

with:

```php
    private function assertClassBelongsToSchool(?int $classId, ?int $schoolId): void
    {
        if ($classId === null) {
            return;
        }

        if (ClassSchool::where('class_id', $classId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'class_id' => ['The selected class has not been adopted by the student\'s school.'],
            ]);
        }
    }
```

Add `use App\Models\ClassSchool;` to this file's imports; remove `use App\Models\SchoolClass;` only if nothing else in the file still uses it (check `$profiles->with(['schoolClass', ...])`-style eager-loads — those reference the relation name, not the class, so they don't need the import; check for any other bare `SchoolClass::` usage before removing).

- [ ] **Step 15: Fix the test fixtures**

Read `backend/tests/Feature/StudentProfileAuthorizationTest.php` in full and fix every place it creates a `SchoolClass` with `school_id`/`district_id` the same way as prior groups (drop those keys, add a `ClassSchool` row). Also check `backend/tests/Feature/StudentUserAuthorizationTest.php` — sub-project 1's Task 1 added a test there (`test_index_can_be_filtered_by_class_id_and_returns_the_current_class`) that creates `SchoolClass::create(['school_id' => $school->id, 'district_id' => $district->id, 'name' => 'Class Six'])` — fix it the same way.

- [ ] **Step 16: Rewrite `ClassSeeder`**

Replace in full:

```php
<?php

namespace Database\Seeders;

use App\Models\ClassSchool;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Each class name is created once, nationally — then every school is
     * assigned to the grade levels its own `level` field implies, via
     * class_schools. The grade-band logic itself is unchanged from
     * before this class became national content.
     */
    public function run(): void
    {
        $classesByLevel = collect($this->grades())->mapWithKeys(
            fn (array $grade, int $numericLevel) => [$numericLevel => SchoolClass::updateOrCreate(
                ['name' => $grade['name']],
                ['bn_name' => $grade['bn_name'], 'numeric_level' => $numericLevel],
            )]
        );

        School::all()->each(function (School $school) use ($classesByLevel) {
            foreach ($this->gradesFor($school->level) as $numericLevel => $grade) {
                ClassSchool::firstOrCreate(
                    ['class_id' => $classesByLevel[$numericLevel]->id, 'school_id' => $school->id],
                    ['district_id' => $school->district_id],
                );
            }
        });
    }

    /**
     * @return array<int, array{name: string, bn_name: string}> numeric_level => grade
     */
    private function grades(): array
    {
        return [
            1 => ['name' => 'Class 1', 'bn_name' => 'প্রথম শ্রেণি'],
            2 => ['name' => 'Class 2', 'bn_name' => 'দ্বিতীয় শ্রেণি'],
            3 => ['name' => 'Class 3', 'bn_name' => 'তৃতীয় শ্রেণি'],
            4 => ['name' => 'Class 4', 'bn_name' => 'চতুর্থ শ্রেণি'],
            5 => ['name' => 'Class 5', 'bn_name' => 'পঞ্চম শ্রেণি'],
            6 => ['name' => 'Class 6', 'bn_name' => 'ষষ্ঠ শ্রেণি'],
            7 => ['name' => 'Class 7', 'bn_name' => 'সপ্তম শ্রেণি'],
            8 => ['name' => 'Class 8', 'bn_name' => 'অষ্টম শ্রেণি'],
            9 => ['name' => 'Class 9', 'bn_name' => 'নবম শ্রেণি'],
            10 => ['name' => 'Class 10', 'bn_name' => 'দশম শ্রেণি'],
            11 => ['name' => 'HSC 1st Year', 'bn_name' => 'একাদশ শ্রেণি'],
            12 => ['name' => 'HSC 2nd Year', 'bn_name' => 'দ্বাদশ শ্রেণি'],
        ];
    }

    /**
     * @return array<int, array{name: string, bn_name: string}> numeric_level => grade
     */
    private function gradesFor(string $schoolLevel): array
    {
        $levels = match ($schoolLevel) {
            'primary' => [1, 2, 3, 4, 5],
            'secondary' => [6, 7, 8, 9, 10],
            'higher_secondary' => [9, 10, 11, 12],
            'college' => [11, 12],
            default => [],
        };

        return array_intersect_key($this->grades(), array_flip($levels));
    }
}
```

- [ ] **Step 17: Rewrite `ClassSubjectSeeder`**

Replace in full:

```php
<?php

namespace Database\Seeders;

use App\Models\ClassSchool;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClassSubjectSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Configures a standard subject set per grade band for every
     * school's offering of every class seeded by ClassSeeder. This is a
     * simplified, single curriculum track — it does not model
     * science/commerce/arts streams for HSC.
     */
    public function run(): void
    {
        ClassSchool::with('schoolClass')->get()->each(function (ClassSchool $assignment) {
            $subjectIds = Subject::whereIn('name', $this->subjectsFor($assignment->schoolClass->numeric_level))->pluck('id');

            DB::table('class_subjects')->where('class_id', $assignment->class_id)->where('school_id', $assignment->school_id)->delete();

            $rows = $subjectIds->map(fn (int $subjectId) => [
                'class_id' => $assignment->class_id,
                'subject_id' => $subjectId,
                'school_id' => $assignment->school_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($rows !== []) {
                DB::table('class_subjects')->insert($rows);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function subjectsFor(?int $numericLevel): array
    {
        return match (true) {
            $numericLevel === null => [],
            $numericLevel <= 5 => ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh and Global Studies', 'Religious Studies'],
            $numericLevel <= 10 => ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh and Global Studies', 'Information and Communication Technology', 'Religious Studies'],
            default => ['Bangla', 'English', 'Information and Communication Technology', 'Physics', 'Chemistry', 'Biology', 'Higher Mathematics'],
        };
    }
}
```

- [ ] **Step 18: Fix `SectionBackfillSeeder`**

Find the line:

```php
            ['shift_id' => $shift->id, 'name' => $class->section ?? 'Main'],
```

Change to:

```php
            ['shift_id' => $shift->id, 'name' => 'Main'],
```

(Read the surrounding context first — `$class` may become an entirely unused variable in its enclosing scope after this change; if so, check whether it's still needed for anything else in that method before leaving or removing it.)

- [ ] **Step 19: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS — this is the first point since Step 3 of this task where the full suite should be green again. If anything outside the files explicitly listed in this task is still failing, read the failure, determine whether it's another `school_classes.school_id` consumer this plan didn't anticipate, and fix it the same way (explicit `school_id` + `class_schools` gate, or a direct `class_subjects`/`class_schools` query) rather than reverting any of the above.

- [ ] **Step 20: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Cut school_classes over to a national list, fixing every school_id consumer"
```

(Using `git add -A` here rather than an itemized list — this task's file list above is already exhaustive for what should be staged; a broad add is safe as long as `git status` before committing shows only files from that list, which is worth double-checking given this task's size.)

---

## Task 4: Full backend test suite against MySQL

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1–3.

This repo has a documented history of SQLite masking MySQL-only migration/behavior bugs (see sub-project 1's Task 10, which found several pre-existing ones entirely unrelated to that plan). This task is the final gate before considering this sub-project done.

- [ ] **Step 1: Confirm the test environment's DB connection targets MySQL**

Check `phpunit.xml` or `.env.testing` for `DB_CONNECTION`. If it defaults to SQLite (it does, per this repo's `phpunit.xml`), override via shell environment variables when invoking the test command rather than editing `phpunit.xml` permanently — `phpunit.xml`'s `<env>` tags don't carry `force="true"`, so a real shell-exported `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` takes precedence over them. **Create a throwaway MySQL database for this run — never point it at a database that might hold real development data.** If no local MySQL server is reachable, say so and report this step as not independently verifiable rather than skipping it silently.

- [ ] **Step 2: Run the full suite**

Run (with the MySQL env vars from Step 1 exported): `php artisan test --compact`
Expected: PASS, zero failures.

- [ ] **Step 3: If anything fails, attribute it before fixing anything**

Before changing any code, determine whether a failure is caused by THIS plan's changes (Tasks 1–3) or is a pre-existing bug unrelated to this plan (sub-project 1's Task 10 found several of the latter — a `UserSeeder`/`DatabaseSeeder` FK-ordering bug, a timetable row-lock assertion, and two split-user-table ID-assertion fragilities — none of which this plan's tasks touch). The fastest way to check: `git stash` is unsafe in a shared worktree (per this repo's own tooling guidance) — instead, note the failing test names, then compare against sub-project 1's final-review findings (`docs/superpowers/plans/2026-11-18-national-content-descoping.md`'s Task 10, now merged) to see if the same tests were already failing before this plan started. If a failure is new and traces to a file this plan's Tasks 1–3 touched, fix it there. If it's one of the already-known pre-existing failures, do not fix it — leave it exactly as sub-project 1's Task 10 left it (out of scope, already ruled on) and just confirm in your report that you recognized it as pre-existing rather than silently working around it.

- [ ] **Step 4: Commit if any fix-forward changes were made**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Fix stragglers found by the full MySQL test run"
```

(Skip this step entirely if Step 2 passed clean, or if every failure found in Step 3 was attributed to a pre-existing, out-of-scope bug.)
