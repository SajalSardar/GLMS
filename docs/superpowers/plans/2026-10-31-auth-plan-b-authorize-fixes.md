# Missing authorize() Calls Implementation Plan (Plan B of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the systemic authorization gap found during Plan A's investigation: every one of the 20 `Api\V1` controllers calls `$this->authorize(...)` in `destroy()` (and a few other sensitive actions), but `store()`/`update()` and custom mutating actions call no `authorize()` at all. Add the missing calls everywhere, so a same-school authenticated user without the relevant `{resource}.{ability}` permission can no longer create or update any tenant-owned resource.

**Architecture:** Every controller's `store()` gains `$this->authorize('create', ModelClass::class)` as its first statement (one-argument form — Plan A already collapsed every Policy's `create()` to `create(User $user): bool`), and every `update()` gains `$this->authorize('update', $model)` as its first statement. Three custom mutating actions need a *new* Policy ability first (`StudentProfilePolicy::attachGuardian`/`::transferSection`); the rest (`ClassPolicy::updateSubjects`, `ClassVersionPolicy::updateSubjects`, `RolePolicy::updatePermissions`, `UserPolicy::assignRole`, `AcademicYearPolicy::setCurrent`, `TimetableEntryPolicy::publish`) already exist from Plan A and just need their controller call sites wired up. `TimetableEntryController::update()`/`::destroy()` and `StudentProfileController::detachGuardian()` already call `authorize()` and are left untouched.

**Tech Stack:** Laravel (PHP 8.5), PHPUnit, SQLite in-memory for tests (existing project convention).

**Spec:** `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`

## Global Constraints

- Every `store()` across all 20 controllers gets `$this->authorize('create', ModelClass::class)` as the first line of the method body (before any other logic).
- Every `update()` across all 20 controllers gets `$this->authorize('update', $model)` as the first line of the method body — **except** `TimetableEntryController::update()`, which already authorizes via its existing `assertEditable()` helper (calls `$this->authorize('update', $entry)` then additionally checks the publish-permission hard constraint) — leave it unchanged.
- Custom mutating actions needing a new call: `ClassController::updateSubjects` → `authorize('updateSubjects', $class)`; `ClassVersionController::updateSubjects` → `authorize('updateSubjects', $class_version)`; `RoleController::updatePermissions` → `authorize('updatePermissions', $role)`; `UserController::assignRole` → `authorize('assignRole', $user)`; `StudentProfileController::attachGuardian` → `authorize('attachGuardian', $student_profile)`; `StudentProfileController::transferSection` → `authorize('transferSection', $student_profile)`. `AcademicYearController::setCurrent`, `TimetableEntryController::publish`/`unpublish`, and `StudentProfileController::detachGuardian` already call `authorize()` — untouched.
- `StudentProfilePolicy` needs two new abilities, `attachGuardian` and `transferSection`, each delegating to `update()` (same pattern as Plan A's `ClassPolicy::updateSubjects`/`AcademicYearPolicy::setCurrent`/etc.). Every other Policy ability this plan calls already exists (confirmed by reading all 20 Policy files).
- Every `Store*Request`'s validation rules are unconditionally required fields (no `sometimes`); every `Update*Request`'s rules use `sometimes` on every field, so an empty JSON body `{}` always passes an update endpoint's validation — the only thing then standing between an unpermitted actor and a successful update is the `authorize()` call this plan adds.
- Business-rule guards already in each controller (e.g. `ClassRoutineController::assertValid()`'s subject-configured-for-class check, `SubjectTeacherAssignmentController::assertTeacherBelongsToSchool()`) are pre-existing and out of scope — this plan only adds permission checks, never touches those guards.
- This plan does NOT touch login endpoints or docs (Plan C). This plan does NOT revisit Plan A's `TenantScope`/Policy work except the two new `StudentProfilePolicy` abilities described above.
- After every PHP file change, run `vendor/bin/pint --dirty --format agent` before considering a task done (project convention from `backend/CLAUDE.md`).
- No model factories exist in this project besides `UserFactory` — every other model's test fixtures are built with `Model::create([...])` directly (matches Plan A's `TenantScopeTest`/`TenantScopeLeakageTest` convention), using exactly the fields the resource's own controller already assigns via mass-assignment (proof those fields are `$fillable`).
- Every feature test authenticates via `$this->actingAs($actor, 'sanctum')`, matching the existing `TenantScopeLeakageTest` convention.

---

### Task 1: `StudentProfilePolicy::attachGuardian`/`::transferSection` abilities

**Files:**
- Modify: `backend/app/Policies/StudentProfilePolicy.php`
- Test: `backend/tests/Unit/StudentProfilePolicyTest.php`

**Interfaces:**
- Consumes: nothing new (Policy class already exists from Plan A)
- Produces: `StudentProfilePolicy::attachGuardian(User, StudentProfile): bool` and `::transferSection(User, StudentProfile): bool`, each `return $this->update($user, $profile);` — consumed by Task 9's `StudentProfileController::attachGuardian()`/`::transferSection()` call sites.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/StudentProfilePolicyTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Policies\StudentProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfilePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_guardian_and_transfer_section_use_the_update_ability(): void
    {
        $permission = Permission::create(['name' => 'student_profiles.update', 'label' => 'Update student profiles']);
        $role = Role::create(['name' => 'school_admin_sp_update', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id]);
        $targetUser = User::factory()->create();
        $profile = StudentProfile::create(['user_id' => $targetUser->id]);

        $policy = new StudentProfilePolicy;

        $this->assertTrue($policy->attachGuardian($user, $profile));
        $this->assertTrue($policy->transferSection($user, $profile));
    }

    public function test_attach_guardian_and_transfer_section_are_denied_without_the_update_permission(): void
    {
        $role = Role::create(['name' => 'no_perm_sp', 'label' => 'No Permission']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $targetUser = User::factory()->create();
        $profile = StudentProfile::create(['user_id' => $targetUser->id]);

        $policy = new StudentProfilePolicy;

        $this->assertFalse($policy->attachGuardian($user, $profile));
        $this->assertFalse($policy->transferSection($user, $profile));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/StudentProfilePolicyTest.php`
Expected: FAIL — `Call to undefined method App\Policies\StudentProfilePolicy::attachGuardian()`.

- [ ] **Step 3: Add the two abilities** — in `backend/app/Policies/StudentProfilePolicy.php`, add after `delete()`:

```php
    /**
     * Linking a guardian to a student is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function attachGuardian(User $user, StudentProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    /**
     * Moving a student between sections is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function transferSection(User $user, StudentProfile $profile): bool
    {
        return $this->update($user, $profile);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/StudentProfilePolicyTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Policies/StudentProfilePolicy.php tests/Unit/StudentProfilePolicyTest.php
git commit -m "Add StudentProfilePolicy::attachGuardian/transferSection abilities"
```

---

### Task 2: Reference-data controllers — Division, District, Upazila, Subject, Chapter, Guardian

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/DivisionController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/DistrictController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/UpazilaController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/SubjectController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/GuardianController.php`
- Test: `backend/tests/Feature/ReferenceDataAuthorizationTest.php`

**Interfaces:**
- Consumes: `DivisionPolicy`/`DistrictPolicy`/`UpazilaPolicy`/`SubjectPolicy`/`ChapterPolicy`/`GuardianPolicy` `create(User): bool` and `update(User, Model): bool` (all already exist, untouched by Plan A).
- Produces: nothing consumed by a later task — this is a leaf group.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ReferenceDataAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_division_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Existing Division', 'bn_name' => 'বিদ্যমান বিভাগ']);
        $payload = ['name' => 'New Division', 'bn_name' => 'নতুন বিভাগ'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/divisions', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['divisions.create', 'divisions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/divisions', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/divisions/{$division->id}", [])->assertOk();
    }

    public function test_district_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'Existing District', 'bn_name' => 'বিদ্যমান জেলা']);
        $payload = ['division_id' => $division->id, 'name' => 'New District', 'bn_name' => 'নতুন জেলা'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/districts', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['districts.create', 'districts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/districts', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/districts/{$district->id}", [])->assertOk();
    }

    public function test_upazila_store_and_update_are_authorized(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Existing Upazila', 'bn_name' => 'বিদ্যমান উপজেলা']);
        $payload = ['district_id' => $district->id, 'name' => 'New Upazila', 'bn_name' => 'নতুন উপজেলা'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/upazilas', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['upazilas.create', 'upazilas.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/upazilas', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/upazilas/{$upazila->id}", [])->assertOk();
    }

    public function test_subject_store_and_update_are_authorized(): void
    {
        $subject = Subject::create(['name' => 'Existing Subject']);
        $payload = ['name' => 'New Subject'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/subjects', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['subjects.create', 'subjects.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subjects', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subjects/{$subject->id}", [])->assertOk();
    }

    public function test_chapter_store_and_update_are_authorized(): void
    {
        $subject = Subject::create(['name' => 'Subject']);
        $chapter = Chapter::create(['subject_id' => $subject->id, 'name' => 'Existing Chapter']);
        $payload = ['subject_id' => $subject->id, 'name' => 'New Chapter'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertOk();
    }

    public function test_guardian_store_and_update_are_authorized(): void
    {
        $guardian = Guardian::create(['name' => 'Existing Guardian', 'phone' => '01700000001']);
        $payload = ['name' => 'New Guardian', 'phone' => '01700000002'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/guardians', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['guardians.create', 'guardians.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardians', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardians/{$guardian->id}", [])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion — none of these `store()`/`update()` methods call `authorize()` yet, so every actor (even one with no permissions) currently succeeds.

- [ ] **Step 3: Add the `authorize()` calls**

`backend/app/Http/Controllers/Api/V1/DivisionController.php` — change:
```php
    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $division = Division::create($request->validated());
```
to:
```php
    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $this->authorize('create', Division::class);

        $division = Division::create($request->validated());
```
and change:
```php
    public function update(UpdateDivisionRequest $request, Division $division): DivisionResource
    {
        $division->update($request->validated());
```
to:
```php
    public function update(UpdateDivisionRequest $request, Division $division): DivisionResource
    {
        $this->authorize('update', $division);

        $division->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/DistrictController.php` — change:
```php
    public function store(StoreDistrictRequest $request): JsonResponse
    {
        $district = District::create($request->validated());
```
to:
```php
    public function store(StoreDistrictRequest $request): JsonResponse
    {
        $this->authorize('create', District::class);

        $district = District::create($request->validated());
```
and change:
```php
    public function update(UpdateDistrictRequest $request, District $district): DistrictResource
    {
        $district->update($request->validated());
```
to:
```php
    public function update(UpdateDistrictRequest $request, District $district): DistrictResource
    {
        $this->authorize('update', $district);

        $district->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/UpazilaController.php` — change:
```php
    public function store(StoreUpazilaRequest $request): JsonResponse
    {
        $upazila = Upazila::create($request->validated());
```
to:
```php
    public function store(StoreUpazilaRequest $request): JsonResponse
    {
        $this->authorize('create', Upazila::class);

        $upazila = Upazila::create($request->validated());
```
and change:
```php
    public function update(UpdateUpazilaRequest $request, Upazila $upazila): UpazilaResource
    {
        $upazila->update($request->validated());
```
to:
```php
    public function update(UpdateUpazilaRequest $request, Upazila $upazila): UpazilaResource
    {
        $this->authorize('update', $upazila);

        $upazila->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/SubjectController.php` — change:
```php
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $request->validated();
```
to:
```php
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $data = $request->validated();
```
and change:
```php
    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $subject->update($request->validated());
```
to:
```php
    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $this->authorize('update', $subject);

        $subject->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/ChapterController.php` — change:
```php
    public function store(StoreChapterRequest $request): JsonResponse
    {
        $subject = Subject::findOrFail($request->integer('subject_id'));
```
to:
```php
    public function store(StoreChapterRequest $request): JsonResponse
    {
        $this->authorize('create', Chapter::class);

        $subject = Subject::findOrFail($request->integer('subject_id'));
```
and change:
```php
    public function update(UpdateChapterRequest $request, Chapter $chapter): ChapterResource
    {
        $chapter->update($this->applyAutoNumbering($request->validated()));
```
to:
```php
    public function update(UpdateChapterRequest $request, Chapter $chapter): ChapterResource
    {
        $this->authorize('update', $chapter);

        $chapter->update($this->applyAutoNumbering($request->validated()));
```

`backend/app/Http/Controllers/Api/V1/GuardianController.php` — change:
```php
    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $data = $request->validated();
```
to:
```php
    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $this->authorize('create', Guardian::class);

        $data = $request->validated();
```
and change:
```php
    public function update(UpdateGuardianRequest $request, Guardian $guardian): GuardianResource
    {
        $guardian->update($request->validated());
```
to:
```php
    public function update(UpdateGuardianRequest $request, Guardian $guardian): GuardianResource
    {
        $this->authorize('update', $guardian);

        $guardian->update($request->validated());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/DivisionController.php app/Http/Controllers/Api/V1/DistrictController.php app/Http/Controllers/Api/V1/UpazilaController.php app/Http/Controllers/Api/V1/SubjectController.php app/Http/Controllers/Api/V1/ChapterController.php app/Http/Controllers/Api/V1/GuardianController.php tests/Feature/ReferenceDataAuthorizationTest.php
git commit -m "Add missing authorize() calls: Division, District, Upazila, Subject, Chapter, Guardian"
```

---

### Task 3: School-direct controllers — School, Classroom, AcademicYear, TeacherProfile

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/SchoolController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassroomController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AcademicYearController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/TeacherProfileController.php`
- Test: `backend/tests/Feature/SchoolDirectAuthorizationTest.php`

**Interfaces:**
- Consumes: `SchoolPolicy`/`ClassroomPolicy`/`AcademicYearPolicy`/`TeacherProfilePolicy` `create(User): bool` and `update(User, Model): bool` (all already exist from Plan A).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/SchoolDirectAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolDirectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);
    }

    private function actorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    private function actorWithNoPermissions(?int $schoolId = null, ?int $districtId = null): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    public function test_school_store_and_update_are_authorized(): void
    {
        $payload = [
            'division_id' => $this->school->division_id,
            'district_id' => $this->school->district_id,
            'upazila_id' => $this->school->upazila_id,
            'name' => 'New School',
            'type' => 'government',
            'level' => 'secondary',
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/schools', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['schools.create', 'schools.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/schools', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/schools/{$this->school->id}", [])->assertOk();
    }

    public function test_classroom_store_and_update_are_authorized(): void
    {
        $classroom = Classroom::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Room']);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Room'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classrooms', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classrooms.create', 'classrooms.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classrooms', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classrooms/{$classroom->id}", [])->assertOk();
    }

    public function test_academic_year_store_and_update_are_authorized(): void
    {
        $year = AcademicYear::create([
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Existing Year',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Year', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/academic-years', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['academic_years.create', 'academic_years.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/academic-years', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/academic-years/{$year->id}", [])->assertOk();
    }

    public function test_teacher_profile_store_and_update_are_authorized(): void
    {
        $existingTarget = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = TeacherProfile::create([
            'user_id' => $existingTarget->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $newTarget = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $payload = ['user_id' => $newTarget->id];

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['teacher_profiles.create', 'teacher_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-profiles', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-profiles/{$profile->id}", [])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/SchoolDirectAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls**

`backend/app/Http/Controllers/Api/V1/SchoolController.php` — change:
```php
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $data = $request->validated();
```
to:
```php
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $this->authorize('create', School::class);

        $data = $request->validated();
```
and change:
```php
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $school->update($request->validated());
```
to:
```php
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $this->authorize('update', $school);

        $school->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/ClassroomController.php` — change:
```php
    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $school = School::findOrFail($request->integer('school_id'));
```
to:
```php
    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $this->authorize('create', Classroom::class);

        $school = School::findOrFail($request->integer('school_id'));
```
and change:
```php
    public function update(UpdateClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        $classroom->update($request->validated());
```
to:
```php
    public function update(UpdateClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        $this->authorize('update', $classroom);

        $classroom->update($request->validated());
```

`backend/app/Http/Controllers/Api/V1/AcademicYearController.php` — change:
```php
    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $school = School::findOrFail($request->integer('school_id'));
```
to:
```php
    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicYear::class);

        $school = School::findOrFail($request->integer('school_id'));
```
and change:
```php
    public function update(UpdateAcademicYearRequest $request, AcademicYear $academic_year): AcademicYearResource
    {
        $data = $request->validated();
```
to:
```php
    public function update(UpdateAcademicYearRequest $request, AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('update', $academic_year);

        $data = $request->validated();
```

`backend/app/Http/Controllers/Api/V1/TeacherProfileController.php` — change:
```php
    public function store(StoreTeacherProfileRequest $request): JsonResponse
    {
        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));
```
to:
```php
    public function store(StoreTeacherProfileRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherProfile::class);

        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));
```
and change:
```php
    public function update(UpdateTeacherProfileRequest $request, TeacherProfile $teacher_profile): TeacherProfileResource
    {
        $teacher_profile->update($request->validated());
```
to:
```php
    public function update(UpdateTeacherProfileRequest $request, TeacherProfile $teacher_profile): TeacherProfileResource
    {
        $this->authorize('update', $teacher_profile);

        $teacher_profile->update($request->validated());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/SchoolDirectAuthorizationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/SchoolController.php app/Http/Controllers/Api/V1/ClassroomController.php app/Http/Controllers/Api/V1/AcademicYearController.php app/Http/Controllers/Api/V1/TeacherProfileController.php tests/Feature/SchoolDirectAuthorizationTest.php
git commit -m "Add missing authorize() calls: School, Classroom, AcademicYear, TeacherProfile"
```

---

### Task 4: Class & ClassVersion controllers (including `updateSubjects`)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ClassController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassVersionController.php`
- Test: `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`

**Interfaces:**
- Consumes: `ClassPolicy::create/update/updateSubjects(User, ...): bool`, `ClassVersionPolicy::create/update/updateSubjects(User, ...): bool` (all already exist from Plan A).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassAndVersionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);
    }

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_class_store_update_and_update_subjects_are_authorized(): void
    {
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Class']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['school_id' => $this->school->id, 'name' => 'New Class'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/classes', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertForbidden();

        $allowed = $this->actorWithPermissions(['classes.create', 'classes.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/classes', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/classes/{$class->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/classes/{$class->id}/subjects", ['subject_ids' => [$subject->id]])->assertOk();
    }

    public function test_class_version_store_update_and_update_subjects_are_authorized(): void
    {
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'name' => 'New Version'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/class-versions', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_versions.create', 'class_versions.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-versions/{$version->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/class-versions/{$version->id}/subjects", ['subjects' => [['subject_id' => $subject->id]]])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/ClassAndVersionAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls**

`backend/app/Http/Controllers/Api/V1/ClassController.php` — change:
```php
    public function store(StoreClassRequest $request): JsonResponse
    {
        $school = School::findOrFail($request->integer('school_id'));
        $section = $request->input('section');
```
to:
```php
    public function store(StoreClassRequest $request): JsonResponse
    {
        $this->authorize('create', SchoolClass::class);

        $school = School::findOrFail($request->integer('school_id'));
        $section = $request->input('section');
```
change:
```php
    public function update(UpdateClassRequest $request, SchoolClass $class): ClassResource
    {
        $class->update($request->validated());
```
to:
```php
    public function update(UpdateClassRequest $request, SchoolClass $class): ClassResource
    {
        $this->authorize('update', $class);

        $class->update($request->validated());
```
and change:
```php
    public function updateSubjects(UpdateClassSubjectsRequest $request, SchoolClass $class): ClassResource
    {
        $class->subjects()->sync($request->validated('subject_ids'));
```
to:
```php
    public function updateSubjects(UpdateClassSubjectsRequest $request, SchoolClass $class): ClassResource
    {
        $this->authorize('updateSubjects', $class);

        $class->subjects()->sync($request->validated('subject_ids'));
```

`backend/app/Http/Controllers/Api/V1/ClassVersionController.php` — change:
```php
    public function store(StoreClassVersionRequest $request): JsonResponse
    {
        $class = SchoolClass::findOrFail($request->integer('class_id'));
```
to:
```php
    public function store(StoreClassVersionRequest $request): JsonResponse
    {
        $this->authorize('create', ClassVersion::class);

        $class = SchoolClass::findOrFail($request->integer('class_id'));
```
change:
```php
    public function update(UpdateClassVersionRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $class_version->update($request->validated());
```
to:
```php
    public function update(UpdateClassVersionRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $this->authorize('update', $class_version);

        $class_version->update($request->validated());
```
and change:
```php
    public function updateSubjects(UpdateVersionSubjectsRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $subjects = collect($request->validated('subjects'))
```
to:
```php
    public function updateSubjects(UpdateVersionSubjectsRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $this->authorize('updateSubjects', $class_version);

        $subjects = collect($request->validated('subjects'))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/ClassAndVersionAuthorizationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/ClassController.php app/Http/Controllers/Api/V1/ClassVersionController.php tests/Feature/ClassAndVersionAuthorizationTest.php
git commit -m "Add missing authorize() calls: Class, ClassVersion (incl. updateSubjects)"
```

---

### Task 5: Shift & Section controllers

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ShiftController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/SectionController.php`
- Test: `backend/tests/Feature/ShiftAndSectionAuthorizationTest.php`

**Interfaces:**
- Consumes: `ShiftPolicy::create/update(User, ...): bool`, `SectionPolicy::create/update(User, ...): bool` (already exist from Plan A).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/ShiftAndSectionAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftAndSectionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private ClassVersion $classVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class']);

        $this->classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Version A',
        ]);
    }

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_shift_store_and_update_are_authorized(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->classVersion->school_id,
            'district_id' => $this->classVersion->district_id,
            'name' => 'Existing Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);
        $payload = [
            'class_version_id' => $this->classVersion->id,
            'name' => 'New Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/shifts', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['shifts.create', 'shifts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/shifts', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])->assertOk();
    }

    public function test_section_store_and_update_are_authorized(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->classVersion->school_id,
            'district_id' => $this->classVersion->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $shift->school_id,
            'district_id' => $shift->district_id,
            'name' => 'Existing Section',
        ]);
        $payload = ['shift_id' => $shift->id, 'name' => 'New Section'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/sections', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['sections.create', 'sections.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/sections', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/ShiftAndSectionAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls**

`backend/app/Http/Controllers/Api/V1/ShiftController.php` — change:
```php
    public function store(StoreShiftRequest $request): JsonResponse
    {
        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
```
to:
```php
    public function store(StoreShiftRequest $request): JsonResponse
    {
        $this->authorize('create', Shift::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
```
and change:
```php
    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        $data = $request->validated();
```
to:
```php
    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        $this->authorize('update', $shift);

        $data = $request->validated();
```

`backend/app/Http/Controllers/Api/V1/SectionController.php` — change:
```php
    public function store(StoreSectionRequest $request): JsonResponse
    {
        $shift = Shift::findOrFail($request->integer('shift_id'));
```
to:
```php
    public function store(StoreSectionRequest $request): JsonResponse
    {
        $this->authorize('create', Section::class);

        $shift = Shift::findOrFail($request->integer('shift_id'));
```
and change:
```php
    public function update(UpdateSectionRequest $request, Section $section): SectionResource
    {
        $data = $request->validated();
```
to:
```php
    public function update(UpdateSectionRequest $request, Section $section): SectionResource
    {
        $this->authorize('update', $section);

        $data = $request->validated();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/ShiftAndSectionAuthorizationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/ShiftController.php app/Http/Controllers/Api/V1/SectionController.php tests/Feature/ShiftAndSectionAuthorizationTest.php
git commit -m "Add missing authorize() calls: Shift, Section"
```

---

### Task 6: ClassRoutine, SubjectTeacherAssignment, TimetableEntry controllers

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ClassRoutineController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/SubjectTeacherAssignmentController.php`
- Modify: `backend/app/Http/Controllers/Api/V1/TimetableEntryController.php`
- Test: `backend/tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`

**Interfaces:**
- Consumes: `ClassRoutinePolicy::create/update`, `SubjectTeacherAssignmentPolicy::create/update`, `TimetableEntryPolicy::create` (all already exist from Plan A). `TimetableEntryController::update()`/`::destroy()` already authorize via `assertEditable()` (unchanged).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\ClassRoutine;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutineAssignmentTimetableAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $classVersion = ClassVersion::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
        ]);

        $this->subject = Subject::create(['name' => 'Math']);
        $this->class->subjects()->attach($this->subject->id);
        $classVersion->subjects()->attach($this->subject->id);

        $this->teacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
    }

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_class_routine_store_and_update_are_authorized(): void
    {
        $routine = ClassRoutine::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 2,
        ]);
        $payload = ['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'day_of_week' => 'saturday', 'period_no' => 1];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/class-routines', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])->assertOk();
    }

    public function test_subject_teacher_assignment_store_and_update_are_authorized(): void
    {
        $assignment = SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
        $payload = ['section_id' => $this->section->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['subject_teacher_assignments.create', 'subject_teacher_assignments.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", ['teacher_id' => $this->teacher->id])->assertOk();
    }

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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls**

`backend/app/Http/Controllers/Api/V1/ClassRoutineController.php` — change:
```php
    public function store(StoreClassRoutineRequest $request): JsonResponse
    {
        $class = SchoolClass::findOrFail($request->integer('class_id'));
```
to:
```php
    public function store(StoreClassRoutineRequest $request): JsonResponse
    {
        $this->authorize('create', ClassRoutine::class);

        $class = SchoolClass::findOrFail($request->integer('class_id'));
```
and change:
```php
    public function update(UpdateClassRoutineRequest $request, ClassRoutine $class_routine): ClassRoutineResource
    {
        $data = $request->validated();
```
to:
```php
    public function update(UpdateClassRoutineRequest $request, ClassRoutine $class_routine): ClassRoutineResource
    {
        $this->authorize('update', $class_routine);

        $data = $request->validated();
```

`backend/app/Http/Controllers/Api/V1/SubjectTeacherAssignmentController.php` — change:
```php
    public function store(StoreSubjectTeacherAssignmentRequest $request): JsonResponse
    {
        $section = Section::findOrFail($request->integer('section_id'));
```
to:
```php
    public function store(StoreSubjectTeacherAssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', SubjectTeacherAssignment::class);

        $section = Section::findOrFail($request->integer('section_id'));
```
and change:
```php
    public function update(UpdateSubjectTeacherAssignmentRequest $request, SubjectTeacherAssignment $subject_teacher_assignment): SubjectTeacherAssignmentResource
    {
        $this->assertTeacherBelongsToSchool($request->integer('teacher_id'), $subject_teacher_assignment->school_id);
```
to:
```php
    public function update(UpdateSubjectTeacherAssignmentRequest $request, SubjectTeacherAssignment $subject_teacher_assignment): SubjectTeacherAssignmentResource
    {
        $this->authorize('update', $subject_teacher_assignment);

        $this->assertTeacherBelongsToSchool($request->integer('teacher_id'), $subject_teacher_assignment->school_id);
```

`backend/app/Http/Controllers/Api/V1/TimetableEntryController.php` — change:
```php
    public function store(StoreTimetableEntryRequest $request): JsonResponse
    {
        $section = Section::findOrFail($request->integer('section_id'));
```
to:
```php
    public function store(StoreTimetableEntryRequest $request): JsonResponse
    {
        $this->authorize('create', TimetableEntry::class);

        $section = Section::findOrFail($request->integer('section_id'));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/ClassRoutineController.php app/Http/Controllers/Api/V1/SubjectTeacherAssignmentController.php app/Http/Controllers/Api/V1/TimetableEntryController.php tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php
git commit -m "Add missing authorize() calls: ClassRoutine, SubjectTeacherAssignment, TimetableEntry store"
```

---

### Task 7: RoleController (including `updatePermissions`)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/RoleController.php`
- Test: `backend/tests/Feature/RoleAuthorizationTest.php`

**Interfaces:**
- Consumes: `RolePolicy::create/update/updatePermissions(User, ...): bool` (`updatePermissions` already existed before Plan A/B — only the controller call site was missing).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/RoleAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_role_store_update_and_update_permissions_are_authorized(): void
    {
        $role = Role::create(['name' => 'existing_role', 'label' => 'Existing Role']);
        $grantablePermission = Permission::create(['name' => 'classes.view', 'label' => 'View classes']);
        $payload = ['name' => 'new_role', 'label' => 'New Role'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/roles', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();

        $allowed = $this->actorWithPermissions(['roles.create', 'roles.update', 'classes.view']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/roles', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/roles/{$role->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->putJson("/api/v1/roles/{$role->id}/permissions", ['permissions' => [$grantablePermission->name]])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/RoleAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls** — in `backend/app/Http/Controllers/Api/V1/RoleController.php`, change:
```php
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
```
to:
```php
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $data = $request->validated();
```
change:
```php
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $role->update($request->validated());
```
to:
```php
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->authorize('update', $role);

        $role->update($request->validated());
```
and change:
```php
    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): RoleResource
    {
        $requestedNames = collect($request->validated('permissions'));
```
to:
```php
    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): RoleResource
    {
        $this->authorize('updatePermissions', $role);

        $requestedNames = collect($request->validated('permissions'));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/RoleAuthorizationTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/RoleController.php tests/Feature/RoleAuthorizationTest.php
git commit -m "Add missing authorize() calls: Role (incl. updatePermissions)"
```

---

### Task 8: UserController (including `assignRole`)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/UserController.php`
- Test: `backend/tests/Feature/UserAuthorizationTest.php`

**Interfaces:**
- Consumes: `UserPolicy::create/update/assignRole(User, ...): bool` (all already exist from Plan A).
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/UserAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function actorWithPermissions(array $permissionNames): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function actorWithNoPermissions(): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_user_store_update_and_assign_role_are_authorized(): void
    {
        $target = User::factory()->create();
        $assignableRole = Role::create(['name' => 'assignable_role', 'label' => 'Assignable Role']);
        $payload = ['name' => 'New User', 'email' => 'new-user@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/users/{$target->id}/role", ['role_id' => $assignableRole->id])->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/UserAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls** — in `backend/app/Http/Controllers/Api/V1/UserController.php`, change:
```php
    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
```
to:
```php
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $actor = $request->user();
```
change:
```php
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->safe()->only(['name', 'email', 'phone', 'password']));
```
to:
```php
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $user->update($request->safe()->only(['name', 'email', 'phone', 'password']));
```
and change:
```php
    public function assignRole(AssignRoleRequest $request, User $user): UserResource
    {
        $actor = $request->user();
```
to:
```php
    public function assignRole(AssignRoleRequest $request, User $user): UserResource
    {
        $this->authorize('assignRole', $user);

        $actor = $request->user();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/UserAuthorizationTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/UserController.php tests/Feature/UserAuthorizationTest.php
git commit -m "Add missing authorize() calls: User (incl. assignRole)"
```

---

### Task 9: StudentProfileController (including `attachGuardian`/`transferSection`)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`
- Test: `backend/tests/Feature/StudentProfileAuthorizationTest.php`

**Interfaces:**
- Consumes: `StudentProfilePolicy::create/update/attachGuardian/transferSection(User, ...): bool` — `attachGuardian`/`transferSection` were added in Task 1 of this plan; the rest already existed from Plan A. `detachGuardian()` already calls `authorize('update', ...)` — unchanged.
- Produces: nothing consumed by a later task.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/StudentProfileAuthorizationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $classVersion = \App\Models\ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
        ]);
    }

    private function actorWithPermissions(array $permissionNames, int $schoolId, int $districtId): User
    {
        $role = Role::create(['name' => 'role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    private function actorWithNoPermissions(int $schoolId, int $districtId): User
    {
        $role = Role::create(['name' => 'role_no_perm_'.md5((string) microtime()), 'label' => 'No Permission']);

        return User::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    public function test_store_update_attach_guardian_and_transfer_section_are_authorized(): void
    {
        $existingTargetUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $profile = StudentProfile::create([
            'user_id' => $existingTargetUser->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
        ]);

        $newTargetUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['name' => 'Guardian', 'phone' => '01700000001']);

        $storePayload = ['user_id' => $newTargetUser->id];
        $attachPayload = ['guardian_id' => $guardian->id, 'relation' => 'father'];
        $transferPayload = ['to_section_id' => $this->section->id];

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/transfer-section", $transferPayload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['student_profiles.create', 'student_profiles.update'], $this->school->id, $this->school->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-profiles', $storePayload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-profiles/{$profile->id}", [])->assertOk();
        $this->actingAs($allowed, 'sanctum')->postJson("/api/v1/student-profiles/{$profile->id}/guardians", $attachPayload)->assertOk();
    }
}
```

Note: `transferSection` is not asserted to succeed here because the section used is the profile's current section (the controller doesn't forbid a no-op move, but exercising the full "succeeds" path isn't necessary to prove the authorization gate — the `assertForbidden()` call above already proves `authorize('transferSection', ...)` is wired in before any business logic runs).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/StudentProfileAuthorizationTest.php`
Expected: FAIL on every `assertForbidden()` assertion.

- [ ] **Step 3: Add the `authorize()` calls** — in `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`, change:
```php
    public function store(StoreStudentProfileRequest $request): JsonResponse
    {
        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));
```
to:
```php
    public function store(StoreStudentProfileRequest $request): JsonResponse
    {
        $this->authorize('create', StudentProfile::class);

        $actor = $request->user();
        $targetUser = User::findOrFail($request->integer('user_id'));
```
change:
```php
    public function update(UpdateStudentProfileRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $data = $request->validated();
```
to:
```php
    public function update(UpdateStudentProfileRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $this->authorize('update', $student_profile);

        $data = $request->validated();
```
change:
```php
    public function attachGuardian(AttachGuardianRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $data = $request->validated();
```
to:
```php
    public function attachGuardian(AttachGuardianRequest $request, StudentProfile $student_profile): StudentProfileResource
    {
        $this->authorize('attachGuardian', $student_profile);

        $data = $request->validated();
```
and change:
```php
    public function transferSection(TransferStudentSectionRequest $request, StudentProfile $student_profile): StudentSectionTransferResource
    {
        $toSection = Section::findOrFail($request->integer('to_section_id'));
```
to:
```php
    public function transferSection(TransferStudentSectionRequest $request, StudentProfile $student_profile): StudentSectionTransferResource
    {
        $this->authorize('transferSection', $student_profile);

        $toSection = Section::findOrFail($request->integer('to_section_id'));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/StudentProfileAuthorizationTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/StudentProfileController.php tests/Feature/StudentProfileAuthorizationTest.php
git commit -m "Add missing authorize() calls: StudentProfile (incl. attachGuardian, transferSection)"
```

---

### Task 10: Full-suite regression run

**Files:**
- None (verification-only task; no code changes).

**Interfaces:**
- Consumes: every task above.
- Produces: confirmation that the full systemic gap described in the spec is closed with no regressions, before this plan is merged.

- [ ] **Step 1: Run the full backend test suite**

Run: `cd backend && php artisan test`
Expected: PASS — every test from Plan A (`TenantScopeTest`, `TenantScopeLeakageTest`) and every test added in Tasks 1-9 of this plan passes. No pre-existing test should fail (confirmed in Plan A: only `TenantScopeTest`/`TenantScopeLeakageTest`/`HealthEndpointTest`/`ExampleTest` existed before this plan; none of them exercise any of the 20 controllers' `store()`/`update()`/custom actions this plan touches, so none can regress).

- [ ] **Step 2: Grep-confirm no controller's mutating action was missed**

Run: `cd backend && grep -n "public function \(store\|update\|updateSubjects\|assignRole\|attachGuardian\|transferSection\|updatePermissions\)(" app/Http/Controllers/Api/V1/*.php`

For each matched method, manually confirm (by reading 1-3 lines below the signature) that either `$this->authorize(` appears as the first statement, or the method is one of the two documented exceptions (`TimetableEntryController::update()` — authorizes via `assertEditable()`; `StudentProfileController::detachGuardian()` — already authorized, untouched by this plan). Report any method that still has neither — that would mean a gap in this plan's task coverage, not something to silently patch; stop and flag it.

- [ ] **Step 3: Report**

Summarize: all 20 controllers now call `authorize()` on every `store()`/`update()`/custom mutating action; full suite green; systemic finding from `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md` closed. No commit needed for this task (verification only).
