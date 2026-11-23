# School Admin Tenant Scoping & Menu Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A school_admin sees the same sidebar menu as a national_admin (minus System Setup), and every list/filter/create/edit flow they use is scoped to their own school — with create/edit/delete availability driven by their role's permissions, both enforced server-side and reflected in the UI.

**Architecture:** Fix `TenantScope` to resolve the acting user via the `sanctum` guard directly (one line, centrally fixes 5 resources). Add explicit transitive school-filtering to the 4 controllers whose models have no `school_id` column. Unblock 4 sidebar menu groups for school_admin by swapping their route guard. Add a small permission-gated UI capability to the shared `GeoCrudTable` component and wire it into the newly-reachable pages.

**Tech Stack:** Laravel 13 (PHP 8.5) backend, MySQL/SQLite, React + TypeScript (`frontend/apps/management`).

**Spec:** `docs/superpowers/specs/2026-11-23-school-admin-tenant-scoping-design.md`

## Global Constraints

- Anonymous/public callers on every touched route must keep working exactly as before — no new auth requirement anywhere.
- `national_admin`'s own behavior (visibility, data, actions) must not change in any way.
- District-scoped actors (`district_id` set, `school_id` null) must not regress — `TenantScope`'s existing district branch stays as-is.
- Test account for live verification: `school.admin@tlap.test` / `password`, `school_id=1`.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change; run `npx tsc -b` + the frontend lint script after any TypeScript change — per this repo's standing conventions.
- `ClassVersion`/`Shift`/`Section`/`SubjectTeacherAssignment`/`School` already use the `TenantScoped` trait (`backend/app/Models/Concerns/TenantScoped.php`, which registers `App\Models\Scopes\TenantScope`). `Book`/`Chapter`/`ChapterVideo` do not, and have no `school_id`/`district_id` column at all — they reach "their" school only transitively via relations. `ChapterMaterial` also doesn't use the trait, and **does** have its own `school_id` column, but that column means something different (see Task 5's Context) — it is not usable for this fix the way the `TenantScoped` models' columns are.

---

### Task 1: Fix `TenantScope` to resolve the actor via the `sanctum` guard

**Files:**
- Modify: `backend/app/Models/Scopes/TenantScope.php`
- Test: `backend/tests/Feature/TenantScopeAuthGuardTest.php` (new)

**Interfaces:**
- Produces: `TenantScope::apply()` now correctly restricts `School`/`ClassVersion`/`Shift`/`Section`/`SubjectTeacherAssignment` for any authenticated school-scoped or district-scoped actor, on every route (public or `auth:sanctum`), with no behavior change for anonymous requests or `national_admin`.

- [ ] **Step 1: Change the actor resolution**

In `backend/app/Models/Scopes/TenantScope.php`, change line 49 from:

```php
            $user = Auth::user();
```

to:

```php
            // Asking the `sanctum` guard directly (not the default guard)
            // means this scope activates correctly on every route — not
            // just ones behind `auth:sanctum` middleware, which is the only
            // thing that normally switches the default guard. Laravel
            // Sanctum always registers its own `sanctum` guard at boot
            // (SanctumServiceProvider::configureGuard()), independent of
            // any middleware, and that guard already handles both this
            // app's auth mechanisms transparently: a stateful session
            // cookie (the student app's SPA login) or a bearer token (the
            // management/teacher apps) — see Laravel\Sanctum\Guard::
            // __invoke(). Without this, an authenticated school_admin
            // hitting one of the many "public" index/show routes (schools,
            // class-versions, shifts, sections, subject-teacher-
            // assignments — registered outside auth:sanctum because the
            // same endpoints also serve genuinely anonymous callers) never
            // got scoped at all: Auth::user() silently resolved to null,
            // identically to a real anonymous request, because the
            // default guard was never switched to sanctum for this
            // request. Confirmed via tinker: an unauthenticated
            // ClassVersion::count() returns every school's rows; the same
            // query run as a logged-in school_admin, with this old line,
            // returned the same unrestricted count.
            $user = Auth::guard('sanctum')->user();
```

- [ ] **Step 2: Write the regression/behavior test file**

Create `backend/tests/Feature/TenantScopeAuthGuardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\SubjectTeacherAssignment;
use App\Models\Subject;
use App\Models\TeacherUser;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TenantScopeAuthGuardTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

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

        $class = SchoolClass::create(['name' => 'Class 6']);
        $banglaId = Version::where('name', 'Bangla')->value('id');

        $this->versionA = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => $banglaId]);
        $this->versionB = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'version_id' => $banglaId]);
    }

    public function test_anonymous_request_sees_every_school_unrestricted(): void
    {
        $response = $this->getJson('/api/v1/class-versions?per_page=50')->assertOk();

        $ids = $response->json('data.*.id');
        $this->assertContains($this->versionA->id, $ids);
        $this->assertContains($this->versionB->id, $ids);
    }

    public function test_national_admin_sees_every_school_unrestricted(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $ids = $response->json('data.*.id');
        $this->assertContains($this->versionA->id, $ids);
        $this->assertContains($this->versionB->id, $ids);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_on_a_public_route(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->versionA->id);
    }

    public function test_district_scoped_actor_sees_only_their_own_district(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], null, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/class-versions?per_page=50')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->versionA->id);
    }

    public function test_school_scoped_actor_cannot_findorfail_another_schools_class_version(): void
    {
        $actor = $this->actorWithPermissions(['class_versions.view'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($actor, 'sanctum');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        ClassVersion::findOrFail($this->versionB->id);
    }

    /**
     * Guards against the re-entrancy regression the TenantScope class's own
     * doc comment warns about: resolving Auth::guard('sanctum')->user()
     * itself queries the `users` table (via Sanctum's PersonalAccessToken
     * -> tokenable lookup), and User is itself TenantScoped — if the
     * re-entrancy guard didn't hold for the new guard() call the same way
     * it held for the old default-guard call, this would recurse until PHP's
     * memory limit is hit. A simple authenticated request against any
     * TenantScoped model completing at all (not timing out/erroring) is the
     * proof.
     */
    public function test_authenticated_request_does_not_recurse_resolving_the_actor(): void
    {
        $actor = $this->actorWithPermissions(['schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/schools')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_school_scoped_actor_sees_only_their_own_school_via_school_model(): void
    {
        $actor = $this->actorWithPermissions(['schools.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/schools')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->schoolA->id);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_shifts_and_sections(): void
    {
        $shiftA = Shift::create(['class_version_id' => $this->versionA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $shiftB = Shift::create(['class_version_id' => $this->versionB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        Section::create(['shift_id' => $shiftA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'A']);
        Section::create(['shift_id' => $shiftB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'A']);

        $actor = $this->actorWithPermissions(['shifts.view', 'sections.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/shifts?per_page=50')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/sections?per_page=50')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_school_scoped_actor_sees_only_their_own_school_subject_teacher_assignments(): void
    {
        // Unlike Shift/Section, SubjectTeacherAssignment has no
        // class_version_id column — it belongs to a Section (and,
        // through that, a Teacher), both required (non-nullable) FKs —
        // see the subject_teacher_assignments migration.
        $subject = Subject::create(['name' => 'Mathematics']);

        $shiftA = Shift::create(['class_version_id' => $this->versionA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionA = Section::create(['shift_id' => $shiftA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'A']);
        $teacherA = TeacherUser::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $shiftB = Shift::create(['class_version_id' => $this->versionB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionB = Section::create(['shift_id' => $shiftB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'A']);
        $teacherB = TeacherUser::factory()->create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $sectionB->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);

        $actor = $this->actorWithPermissions(['subject_teacher_assignments.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/subject-teacher-assignments?per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
```

The `Shift`/`Section` field shapes above (`start_time`/`end_time`/`days_of_week` on `Shift`; no `capacity` needed on `Section`) are copied verbatim from `ClassRoutineControllerTest.php`'s own existing fixture creation calls, so they're already verified against the real schema — no need to re-derive them. `SubjectTeacherAssignment`'s fields (`class_version_id`, `school_id`, `district_id`, `subject_id`) match this session's existing `ChapterControllerTest.php`/`BookControllerTest.php` convention for the models they do use, but this exact model hasn't been constructed directly in a test this session — open `backend/app/Models/SubjectTeacherAssignment.php` to confirm its `fillable` list before running, in case it requires an additional column (e.g. a `teacher_id`) not listed above.

- [ ] **Step 3: Run the new test file**

Run: `cd backend && php artisan test --filter=TenantScopeAuthGuardTest`
Expected: all tests pass. If `test_authenticated_request_does_not_recurse_resolving_the_actor` hangs or errors with a memory exhaustion message, the re-entrancy guard in `TenantScope::apply()` needs investigation — do not proceed to Task 2 until this passes cleanly.

- [ ] **Step 4: Run the full backend suite and Pint**

Run: `cd backend && php artisan test` — expected: every existing test still passes (this is the single highest-risk change in the whole plan; a regression here would show up broadly, not just in the new test file).
Run: `cd backend && vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/Scopes/TenantScope.php backend/tests/Feature/TenantScopeAuthGuardTest.php
git commit -m "Fix TenantScope to resolve the actor via the sanctum guard directly"
```

---

### Task 2: Scope `BookController` to the actor's own school

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Test: `backend/tests/Feature/BookControllerTest.php`

**Interfaces:**
- Consumes: Nothing new from Task 1 (Book is not `TenantScoped`, so Task 1 doesn't cover it — this task adds explicit filtering).
- Produces: `BookController::index()`/`show()` restricted to the actor's own school when school-scoped; `store()`'s existing `ClassVersion::findOrFail()` call (line 74) is automatically protected by Task 1's fix — no code change needed there, only a new test confirming it.

- [ ] **Step 1: Add actor-aware scoping to `index()`**

In `backend/app/Http/Controllers/Api/V1/BookController.php`, change the `index()` method (currently lines 36-52) to resolve the actor and add a school filter:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous and cross-school public-catalog callers), so the
        // user is resolved explicitly from the sanctum guard — same
        // pattern as ChapterVideoController::index()'s existing $actor
        // resolution, now also needed here so a school_admin's own list
        // is narrowed to their own school's books.
        $actor = $request->user('sanctum');

        $books = Book::query()
            ->with(['classVersion.schoolClass', 'subject', 'academicYear'])
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('class_id'), fn ($query, $id) => $query->whereHas('classVersion', fn ($q) => $q->where('class_id', $id)))
            ->when($request->integer('version_id'), fn ($query, $id) => $query->whereHas('classVersion', fn ($q) => $q->where('version_id', $id)))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->when($actor?->school_id, fn ($query, $schoolId) => $query->whereHas('classVersion', fn ($q) => $q->where('school_id', $schoolId)))
            ->orderBy('class_version_id')
            ->orderBy('subject_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return BookResource::collection($books);
    }
```

Update the method's existing docblock (lines 26-35) to add a line noting the new mandatory school filter for a school-scoped actor, alongside the existing class_id/version_id explanation.

- [ ] **Step 2: Add the equivalent scoping to `show()`**

`show()` (currently lines 57-60) uses route-model binding, so `Book $book` is already resolved before the method runs — scoping there means rejecting (404) a cross-school book rather than filtering a list. Change:

```php
    public function show(Request $request, Book $book): BookResource
    {
        $actor = $request->user('sanctum');
        abort_if($actor?->school_id && $book->classVersion?->school_id !== $actor->school_id, 404);

        return new BookResource($book->load(['classVersion.schoolClass', 'subject', 'academicYear']));
    }
```

(This changes the method signature to accept `Request $request` — add `use Illuminate\Http\Request;` if not already imported; it already is, per the existing `use Illuminate\Http\Request;` at the top of this file for `index()`.)

- [ ] **Step 3: Write new tests in `BookControllerTest.php`**

Read the current `BookControllerTest.php` in full first (its `setUp()` already creates `$this->schoolA`/`$this->schoolB`/`$this->versionA`/`$this->versionB`/`$this->subject`/`$this->academicYear` — reuse these, don't recreate). Add these tests after the existing `test_index_filters_by_the_national_version_id` test:

```php
    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $bookA = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Book A']);
        Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/books')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $bookA->id);
    }

    public function test_show_404s_a_school_scoped_actor_for_another_schools_book(): void
    {
        $bookB = Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/books/{$bookB->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_a_school_scoped_actor_targeting_another_schools_class_version(): void
    {
        $actor = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/books', [
                'class_version_id' => $this->versionB->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->academicYear->id,
                'name' => 'Cross School Book',
            ])
            ->assertNotFound();
    }

    public function test_national_admin_still_sees_every_schools_books(): void
    {
        $bookA = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Book A']);
        $bookB = Book::create(['class_version_id' => $this->versionB->id, 'subject_id' => $this->subject->id, 'name' => 'Book B']);

        $actor = $this->actorWithPermissions(['books.view']);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/books')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
    }
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=BookControllerTest`
Expected: all pass, including the 4 new tests above.

- [ ] **Step 5: Pint, then commit**

```bash
cd backend && vendor/bin/pint --dirty --format agent
git add backend/app/Http/Controllers/Api/V1/BookController.php backend/tests/Feature/BookControllerTest.php
git commit -m "Scope BookController's index/show to the actor's own school"
```

---

### Task 3: Scope `ChapterController` to the actor's own school, including the write side

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterController.php`
- Test: `backend/tests/Feature/ChapterControllerTest.php`

**Interfaces:**
- Consumes: Nothing from Task 1 (`Chapter` is not `TenantScoped`). `Chapter.book_id -> Book` is also not `TenantScoped` (Task 2 only added explicit controller-level filtering to `BookController`, not a model scope) — so `Chapter::store()`'s existing `Book::findOrFail($request->integer('book_id'))` call does **not** automatically reject a cross-school book the way `ClassVersion::findOrFail()` does in `BookController::store()`. This task must add an explicit ownership check.
- Produces: `ChapterController::index()`/`show()`/`store()` all school-scoped.

- [ ] **Step 1: Add actor-aware scoping to `index()`**

In `backend/app/Http/Controllers/Api/V1/ChapterController.php`, add actor resolution and a school filter to `index()`:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user('sanctum');

        $chapters = Chapter::query()
            ->with(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear'])
            ->when($request->integer('book_id'), fn ($query, $bookId) => $query->where('book_id', $bookId))
            ->when($request->integer('class_id'), fn ($query, $id) => $query->whereHas('book.classVersion', fn ($q) => $q->where('class_id', $id)))
            ->when($request->integer('version_id'), fn ($query, $id) => $query->whereHas('book.classVersion', fn ($q) => $q->where('version_id', $id)))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->whereHas('book', fn ($q) => $q->where('subject_id', $id)))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->whereHas('book', fn ($q) => $q->where('academic_year_id', $id)))
            ->when($actor?->school_id, fn ($query, $schoolId) => $query->whereHas('book.classVersion', fn ($q) => $q->where('school_id', $schoolId)))
            ->orderBy('book_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return ChapterResource::collection($chapters);
    }
```

- [ ] **Step 2: Scope `show()`**

```php
    public function show(Request $request, Chapter $chapter): ChapterResource
    {
        $actor = $request->user('sanctum');
        $chapter->loadMissing('book.classVersion');
        abort_if($actor?->school_id && $chapter->book?->classVersion?->school_id !== $actor->school_id, 404);

        return new ChapterResource($chapter->load(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear']));
    }
```

- [ ] **Step 3: Add the write-side ownership check to `store()`**

The current `store()` does `$book = Book::findOrFail($request->integer('book_id'));` — since `Book` has no tenant scope, this must be followed by an explicit check. Change the start of `store()` to:

```php
    public function store(StoreChapterRequest $request): JsonResponse
    {
        $this->authorize('create', Chapter::class);

        $book = Book::with('classVersion')->findOrFail($request->integer('book_id'));
        $actor = $request->user('sanctum');
        abort_if($actor?->school_id && $book->classVersion?->school_id !== $actor->school_id, 404);

        $data = $this->applyAutoNumbering($request->validated());
        // ... rest of the method unchanged
```

(Keep everything after the `$data = ...` line exactly as it is today — only the lines above it change.)

- [ ] **Step 4: Write new tests in `ChapterControllerTest.php`**

Read the current file first (its `setUp()` already has `$this->schoolA`/`$this->schoolB`/`$this->bookA`/`$this->bookB`/`$this->versionA`/`$this->versionB`/`$this->subject`). Add after the existing national-version-filter test:

```php
    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $chapterA = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter A']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapters')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $chapterA->id);
    }

    public function test_show_404s_a_school_scoped_actor_for_another_schools_chapter(): void
    {
        $chapterB = Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapters/{$chapterB->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_a_school_scoped_actor_targeting_another_schools_book(): void
    {
        $actor = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookB->id, 'name' => 'Cross School Chapter'])
            ->assertNotFound();
    }

    public function test_national_admin_still_sees_every_schools_chapters(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter A']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter B']);

        $actor = $this->actorWithPermissions(['chapters.view']);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapters')->assertOk()->assertJsonCount(2, 'data');
    }
```

- [ ] **Step 5: Run tests, Pint, commit**

Run: `cd backend && php artisan test --filter=ChapterControllerTest`
Run: `cd backend && vendor/bin/pint --dirty --format agent`

```bash
git add backend/app/Http/Controllers/Api/V1/ChapterController.php backend/tests/Feature/ChapterControllerTest.php
git commit -m "Scope ChapterController to the actor's own school, including store()"
```

---

### Task 4: Scope `ChapterVideoController` to the actor's own school, including the write side

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Test: `backend/tests/Feature/ChapterVideoControllerTest.php`

**Interfaces:**
- Consumes: Same gap as Task 3 — `ChapterVideo.chapter_id -> Chapter.book_id -> Book` has no tenant scope anywhere in the chain, so `store()`'s `Chapter::findOrFail()` needs an explicit check.

- [ ] **Step 1: Extend `index()`'s existing actor-aware filtering**

`index()` already resolves `$actor = $request->user('sanctum');` (for the `is_enabled` gate). Add one more `when()` clause to the existing query chain, right after the `academic_year_id` filter and before the `is_enabled` one:

```php
            ->when($actor?->school_id, fn ($query, $schoolId) => $query->whereHas('chapter.book.classVersion', fn ($q) => $q->where('school_id', $schoolId)))
```

- [ ] **Step 2: Scope `show()`**

Current `show()`:
```php
    public function show(Request $request, ChapterVideo $chapter_video): ChapterVideoResource
    {
        if (! $chapter_video->is_enabled && ! $request->user('sanctum')?->hasPermission('chapter_videos.update')) {
            abort(404);
        }

        return new ChapterVideoResource($chapter_video->load(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear']));
    }
```

Change to also check school ownership (reuse the already-resolved actor rather than calling `$request->user('sanctum')` twice):

```php
    public function show(Request $request, ChapterVideo $chapter_video): ChapterVideoResource
    {
        $actor = $request->user('sanctum');

        if (! $chapter_video->is_enabled && ! $actor?->hasPermission('chapter_videos.update')) {
            abort(404);
        }

        $chapter_video->loadMissing('chapter.book.classVersion');
        abort_if($actor?->school_id && $chapter_video->chapter?->book?->classVersion?->school_id !== $actor->school_id, 404);

        return new ChapterVideoResource($chapter_video->load(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear']));
    }
```

- [ ] **Step 3: Add the write-side ownership check to `store()`**

Current `store()` begins `$chapter = Chapter::findOrFail($request->integer('chapter_id'));`. Change to:

```php
    public function store(StoreChapterVideoRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterVideo::class);

        $chapter = Chapter::with('book.classVersion')->findOrFail($request->integer('chapter_id'));
        $actor = $request->user('sanctum');
        abort_if($actor?->school_id && $chapter->book?->classVersion?->school_id !== $actor->school_id, 404);

        $data = $request->validated();
        // ... rest of the method unchanged
```

- [ ] **Step 4: Write new tests in `ChapterVideoControllerTest.php`**

Read the current file first (its `setUp()` has `$this->schoolA`/`$this->schoolB`/`$this->chapterA`/`$this->chapterB`/`$this->bookA`/`$this->bookB`). Add after the existing national-version-filter test:

```php
    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $videoA = ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Video A']);
        ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Video B']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapter-videos')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $videoA->id);
    }

    public function test_show_404s_a_school_scoped_actor_for_another_schools_video(): void
    {
        $videoB = ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Video B']);

        $actor = $this->actorWithPermissions(['chapter_videos.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/chapter-videos/{$videoB->id}")
            ->assertNotFound();
    }

    public function test_store_rejects_a_school_scoped_actor_targeting_another_schools_chapter(): void
    {
        $actor = $this->actorWithPermissions(['chapter_videos.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/chapter-videos', [
                'chapter_id' => $this->chapterB->id,
                'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'title' => 'Cross School Video',
            ])
            ->assertNotFound();
    }

    public function test_national_admin_still_sees_every_schools_videos(): void
    {
        ChapterVideo::create(['chapter_id' => $this->chapterA->id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Video A']);
        ChapterVideo::create(['chapter_id' => $this->chapterB->id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Video B']);

        $actor = $this->actorWithPermissions(['chapter_videos.view']);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/chapter-videos')->assertOk()->assertJsonCount(2, 'data');
    }
```

- [ ] **Step 5: Run tests, Pint, commit**

Run: `cd backend && php artisan test --filter=ChapterVideoControllerTest`
Run: `cd backend && vendor/bin/pint --dirty --format agent`

```bash
git add backend/app/Http/Controllers/Api/V1/ChapterVideoController.php backend/tests/Feature/ChapterVideoControllerTest.php
git commit -m "Scope ChapterVideoController to the actor's own school, including store()"
```

---

### Task 5: Scope `ChapterMaterialController::index()` to the actor's own school (defense in depth)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`
- Test: `backend/tests/Feature/ChapterMaterialControllerTest.php`

**Context — read this before starting:** `ChapterMaterial` has its own `school_id` column, but it means something different from "which school this material's chapter belongs to" — per the model's own doc comment (`backend/app/Models/ChapterMaterial.php:44-54`), it's set only when the uploader is a `TeacherUser` who supplied a specific `section_id`, and is **null** for anything uploaded by staff (school_admin/national_admin) or predating this scoping — meaning most rows have `school_id = null` even though they unambiguously belong to one school via `chapter.book.classVersion.school_id`. **Do not filter by `ChapterMaterial.school_id` directly for this task** — it would incorrectly exclude every staff-uploaded material. Filter transitively through the same `chapter.book.classVersion` path as Tasks 3/4.

There is currently no Management Portal page that lists `ChapterMaterial` directly (it's reached only via the Teacher app's upload/my-uploads flows and the Student app's own scoped `mine()` endpoint) — this task is backend-only defense in depth for the public `index()` route, not a new UI surface. Do not add any frontend work for this task.

**No write-side (`store()`) check is needed for this resource**, unlike Tasks 3/4: per `RolePermissionSeeder.php`, `school_admin` holds `chapter_materials.view` only — not `.create` — so a school_admin can never reach `store()` at all regardless of scoping. Only `teacher` and `national_admin` hold `chapter_materials.create`, and a teacher's upload is already authorized against their own `SubjectTeacherAssignment` (inherently school-bound via the teacher's own profile) by `ChapterMaterialPolicy::create()` — a pre-existing, unrelated control this plan doesn't need to touch.

- [ ] **Step 1: Add actor-aware scoping to `index()`**

In `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`, the current `index()` (lines 42-60) resolves `$actor = $request->user('sanctum');` already. Add one more `when()` clause to the query chain, after the existing `chapter_id` filter and before the `student`/`whereNull('section_id')` branch:

```php
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->when($actor?->school_id, fn ($query, $schoolId) => $query->whereHas('chapter.book.classVersion', fn ($q) => $q->where('school_id', $schoolId)))
            ->when(
                $actor instanceof StudentUser,
                fn ($query) => $this->scopeToStudentsOwnProfile($query, $actor),
                fn ($query) => $query->whereNull('section_id'),
            )
```

Note: a `StudentUser` actor never has a `school_id` set directly on the `User`-family row the same way `school_admin` does (students are matched via their own profile in `scopeToStudentsOwnProfile`) — confirm this by checking `StudentUser`'s fillable/columns before assuming `$actor?->school_id` is always null for a student; if it does carry a `school_id`, the new filter is still correct/harmless since it narrows to the same school the profile-based scoping already narrows to.

- [ ] **Step 2: Store `$schoolB` on `$this` in `setUp()`**

The file's current `setUp()` creates a second school as a local variable (`$schoolB = School::create([...]);`, not stored on `$this`), unlike `BookControllerTest.php`/`ChapterControllerTest.php`'s `$this->schoolB` convention this session's other tests already follow. Add a `private School $schoolB;` property declaration (alongside the existing `private School $schoolA;`), and change the `$schoolB = School::create([...]);` line in `setUp()` to `$this->schoolB = School::create([...]);` (then update the two lines below it that reference `$schoolB` — the `ClassVersion::create([...'school_id' => $schoolB->id...])` for `$versionB` — to `$this->schoolB->id` instead). This is the only change to `setUp()`.

- [ ] **Step 3: Write a new test**

Add, mirroring `test_store_and_update_are_authorized`'s existing use of the real POST endpoint (rather than direct `ChapterMaterial::create()`, which would require supplying every file-metadata column by hand):

```php
    public function test_index_restricts_a_school_scoped_actor_to_their_own_school(): void
    {
        $creatorA = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $materialAId = $this->actingAs($creatorA, 'sanctum')
            ->post('/api/v1/chapter-materials', [
                'chapter_id' => $this->chapterA->id,
                'type' => 'primary',
                'file' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data.id');

        $creatorB = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolB->id, $this->schoolB->district_id);
        $this->actingAs($creatorB, 'sanctum')
            ->post('/api/v1/chapter-materials', [
                'chapter_id' => $this->chapterB->id,
                'type' => 'primary',
                'file' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
            ])
            ->assertCreated();

        // Both materials were uploaded by a non-teacher actor, so
        // resolveUploaderScope() left section_id/school_id null on both
        // rows (see ChapterMaterialController's own doc comment) — this
        // test is deliberately exercising the NEW chapter.book.classVersion
        // filter, not the material's own (here-unset) school_id column.
        $viewer = $this->actorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/chapter-materials')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $materialAId);
    }
```

- [ ] **Step 4: Run tests, Pint, commit**

Run: `cd backend && php artisan test --filter=ChapterMaterialControllerTest`
Run: `cd backend && vendor/bin/pint --dirty --format agent`

```bash
git add backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php backend/tests/Feature/ChapterMaterialControllerTest.php
git commit -m "Scope ChapterMaterialController's index() to the actor's own school"
```

---

### Task 6: Make `ClassRoutineController::index()`'s school filter mandatory for a school-scoped actor

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ClassRoutineController.php`
- Test: `backend/tests/Feature/ClassRoutineControllerTest.php`

**Context:** `ClassRoutine` has its own `school_id` column directly (unlike Chapter/ChapterMaterial), and `index()`'s existing `school_id` filter (`->when($request->integer('school_id'), ...)`) is purely optional/request-driven today — a school_admin calling this route without passing `?school_id=` gets every school's routines. The Management Portal's own `ManagementClassRoutinePage.tsx`/`TeacherRoutinePage.tsx` already lock their School picker to the actor's own school client-side (per the investigation), so this is defense-in-depth for the raw API, matching `TimetableEntryController::index()`'s already-correct pattern (resolve `$request->user('sanctum')`, scope unconditionally when school-scoped).

- [ ] **Step 1: Add actor-aware scoping**

Change the start of `index()` (currently starting at line 30) to resolve the actor, and change the existing `school_id` `when()` clause so a school-scoped actor's own school always wins over (or is unconditionally applied regardless of) any `school_id` query param they might pass:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous/public callers), so the user is resolved
        // explicitly from the sanctum guard — same pattern as
        // TimetableEntryController::index(), this controller's sibling.
        $actor = $request->user('sanctum');

        $routines = ClassRoutine::query()
            ->with(['schoolClass', 'subject', 'teacher', 'classroom', 'section', 'shift', 'classVersion', 'academicYear'])
            ->when(
                $actor?->school_id,
                fn ($query, $schoolId) => $query->where('school_id', $schoolId),
                fn ($query) => $query->when($request->integer('school_id'), fn ($q, $schoolId) => $q->where('school_id', $schoolId)),
            )
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('class_version_id'), fn ($query, $classVersionId) => $query->where('class_version_id', $classVersionId))
            ->when($request->integer('shift_id'), fn ($query, $shiftId) => $query->where('shift_id', $shiftId))
            ->when($request->integer('section_id'), fn ($query, $sectionId) => $query->where('section_id', $sectionId))
            ->when($request->integer('teacher_id'), fn ($query, $teacherId) => $query->where('teacher_id', $teacherId))
            ->when($request->integer('academic_year_id'), fn ($query, $academicYearId) => $query->where('academic_year_id', $academicYearId))
            ->when($request->string('day_of_week')->toString(), fn ($query, $day) => $query->where('day_of_week', $day))
            ->orderBy('class_id')
            ->get()
            ->sortBy(fn (ClassRoutine $routine) => [$routine->class_id, array_search($routine->day_of_week, ClassRoutine::DAYS_OF_WEEK), $routine->period_no])
            ->values();

        return ClassRoutineResource::collection($routines);
    }
```

(The `->when($actor?->school_id, ..., ...)` 3-arg form: the first closure runs when the actor has a `school_id`, forcing it unconditionally; the second (default) closure runs otherwise, preserving today's optional request-driven filter for anonymous/national_admin/district-scoped callers — do not let a school_admin's own `?school_id=` query param override their own school.)

- [ ] **Step 2: Write a new test in `ClassRoutineControllerTest.php`**

This file's `setUp()` creates only one school (`$this->school`/`$this->class`/`$this->subject`, with a matching `ClassSchool` row and a `class_subjects` table row). Add a second school with the same two prerequisite rows, create one routine per school via the real POST endpoint (mirroring `test_index_filters_by_class_version_shift_and_section`'s existing use of `teacherActorWithPermissions`), and confirm a school-A actor's `index()` only ever returns school A's routine — even when the request explicitly asks for school B's via the query string:

```php
    public function test_index_restricts_a_school_scoped_actor_to_their_own_school_regardless_of_query_param(): void
    {
        $divisionB = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ বি']);
        $districtB = District::create(['division_id' => $divisionB->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);
        $schoolB = School::create(['division_id' => $divisionB->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        ClassSchool::create(['class_id' => $this->class->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id]);
        DB::table('class_subjects')->insert([
            'class_id' => $this->class->id, 'school_id' => $schoolB->id, 'subject_id' => $this->subject->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $teacherA = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);
        $routineAId = $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->assertCreated()->json('data.id');

        $teacherB = $this->teacherActorWithPermissions(['class_routines.create'], $schoolB->id, $schoolB->district_id);
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $schoolB->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->assertCreated();

        $viewer = $this->actorWithPermissions(['class_routines.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/class-routines?school_id={$schoolB->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $routineAId);
    }
```

(`Division`/`District`/`Upazila`/`School`/`ClassSchool`/`DB` are already imported at the top of this file — no new `use` statements needed.)

- [ ] **Step 3: Run tests, Pint, commit**

Run: `cd backend && php artisan test --filter=ClassRoutineControllerTest`
Run: `cd backend && vendor/bin/pint --dirty --format agent`

```bash
git add backend/app/Http/Controllers/Api/V1/ClassRoutineController.php backend/tests/Feature/ClassRoutineControllerTest.php
git commit -m "Make ClassRoutineController's school filter mandatory for a school-scoped actor"
```

---

### Task 7: Add `permissions` to the frontend auth context

**Files:**
- Modify: `frontend/packages/auth/src/index.tsx`
- Test: `frontend/packages/auth/src/index.test.ts` (check if this file exists; if so, extend it following its existing pattern for `role`/`schoolId`; if it doesn't exist, skip adding one — this package may not have its own test file today)

**Interfaces:**
- Produces: `useAuth()` gains `permissions: string[]`, `setPermissions: (permissions: string[]) => void`, and `can: (permission: string) => boolean`.

- [ ] **Step 1: Add storage functions**

In `frontend/packages/auth/src/index.tsx`, after the existing `SCHOOL_ID_STORAGE_KEY` constant (line 14) and its matching get/store/clear trio (lines 65-76), add:

```ts
const PERMISSIONS_STORAGE_KEY = 'ttla.authPermissions'

export function getStoredPermissions(): string[] {
  const raw = localStorage.getItem(PERMISSIONS_STORAGE_KEY)
  if (raw === null) return []
  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export function storePermissions(permissions: string[]): void {
  localStorage.setItem(PERMISSIONS_STORAGE_KEY, JSON.stringify(permissions))
}

export function clearStoredPermissions(): void {
  localStorage.removeItem(PERMISSIONS_STORAGE_KEY)
}
```

- [ ] **Step 2: Extend `AuthContextValue`**

Add to the interface (after the existing `schoolId: number | null` field and its doc comment, before `setToken`):

```ts
  /**
   * The signed-in user's own role.permissions list, as returned by the
   * login response — empty for a guest or a session stored before this
   * field existed. Drives GeoCrudTable's optional createPermission/
   * updatePermission/deletePermission props so a page can hide an action
   * a role genuinely can't perform, rather than letting the user hit a
   * 403 after filling out a form. This is a UX improvement only — every
   * write endpoint already enforces permissions server-side via policies
   * regardless of what this array says.
   */
  permissions: string[]
  setToken: (token: string) => void
  setRole: (role: string | null) => void
  setName: (name: string | null) => void
  setUserId: (userId: number | null) => void
  setSchoolId: (schoolId: number | null) => void
  setPermissions: (permissions: string[]) => void
  /**
   * True when the signed-in user's permissions include this exact
   * permission string. Always false for a guest (empty permissions array).
   */
  can: (permission: string) => boolean
  clearToken: () => void
```

- [ ] **Step 3: Wire it into `AuthProvider`**

Add state, setter, and clearing, mirroring the existing `schoolId` pattern exactly:

```ts
  const [permissions, setPermissionsState] = useState<string[]>(() => getStoredPermissions())
```

(add this line right after the existing `const [schoolId, setSchoolIdState] = useState<number | null>(() => getStoredSchoolId())` line)

```ts
  const setPermissions = useCallback((next: string[]) => {
    storePermissions(next)
    setPermissionsState(next)
  }, [])

  const can = useCallback((permission: string) => permissions.includes(permission), [permissions])
```

(add these two blocks right after the existing `setSchoolId` `useCallback` block)

In `clearToken`, add `clearStoredPermissions()` alongside the other `clearStored*()` calls, and `setPermissionsState([])` alongside the other `set*State(null)` calls.

In the `value` `useMemo`, add `permissions` and `can` to both the returned object and the dependency array:

```ts
  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      role,
      name,
      userId,
      schoolId,
      permissions,
      setToken,
      setRole,
      setName,
      setUserId,
      setSchoolId,
      setPermissions,
      can,
      clearToken,
    }),
    [token, role, name, userId, schoolId, permissions, setToken, setRole, setName, setUserId, setSchoolId, setPermissions, can, clearToken],
  )
```

- [ ] **Step 4: Typecheck**

Run: `cd frontend/packages/auth && npx tsc --noEmit` (or `cd frontend && npx tsc -b` from the workspace root if this package has no standalone tsconfig check — verify which applies by checking for a `tsconfig.json` in this package first).

- [ ] **Step 5: Commit**

```bash
git add frontend/packages/auth/src/index.tsx
git commit -m "Add permissions array and can() helper to the auth context"
```

---

### Task 8: Wire `permissions` through the login flow

**Files:**
- Modify: `frontend/apps/management/src/pages/ManagementLogin.tsx`

**Interfaces:**
- Consumes: `useAuth().setPermissions` from Task 7.

- [ ] **Step 1: Call `setPermissions` after login**

In `LoginForm()`, change line 39 from:

```ts
  const { setToken, setRole, setSchoolId } = useAuth()
```

to:

```ts
  const { setToken, setRole, setSchoolId, setPermissions } = useAuth()
```

And in `handleSubmit`, change lines 57-59 from:

```ts
      setToken(result.token)
      setRole(result.data.role?.name ?? null)
      setSchoolId(result.data.school?.id ?? null)
```

to:

```ts
      setToken(result.token)
      setRole(result.data.role?.name ?? null)
      setSchoolId(result.data.school?.id ?? null)
      setPermissions(result.data.role?.permissions ?? [])
```

- [ ] **Step 2: Typecheck and manually verify**

Run: `cd frontend/apps/management && npx tsc -b`

- [ ] **Step 3: Commit**

```bash
git add frontend/apps/management/src/pages/ManagementLogin.tsx
git commit -m "Store the logged-in actor's permissions on login"
```

---

### Task 9: Add permission-gated actions to `GeoCrudTable`

**Files:**
- Modify: `frontend/apps/management/src/components/GeoCrudTable.tsx`

**Interfaces:**
- Consumes: `useAuth().can` from Task 7.
- Produces: `GeoCrudTableProps<T>` gains three new optional props: `createPermission?: string`, `updatePermission?: string`, `deletePermission?: string`.

- [ ] **Step 1: Add the three new props to the interface**

In `GeoCrudTableProps<T>` (currently lines 71-105), add after `deleteRow: (id: number, token: string) => Promise<void>` (line 89):

```ts
  // When provided, the corresponding action is hidden unless
  // useAuth().can(permission) is true — the Add button for
  // createPermission, the row's edit (pencil) icon for updatePermission,
  // the row's delete (trash) icon for deletePermission. Omitted (the
  // default), the action is always shown, exactly as before this prop
  // existed — this keeps every existing page's behavior unchanged. This
  // is a UX improvement only: every write endpoint already enforces
  // permissions server-side via policies regardless of what this hides.
  createPermission?: string
  updatePermission?: string
  deletePermission?: string
```

- [ ] **Step 2: Destructure the new props and read `can` from `useAuth()`**

Add `createPermission, updatePermission, deletePermission,` to the destructured props list (after `rowActions,` at line 151).

Change line 153 from:

```ts
  const { token } = useAuth()
```

to:

```ts
  const { token, can } = useAuth()
```

- [ ] **Step 3: Gate the Add button**

Change the Add button block (lines 357-364):

```tsx
        {(!createPermission || can(createPermission)) && (
          <button
            type="button"
            className={`${buttonPrimary} inline-flex items-center gap-1.5`}
            onClick={() => (onAdd ? onAdd() : openCreateForm())}
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
            {t['management.table.addNew']}
          </button>
        )}
```

Note the title/heading `<h2>` above it is in a `flex justify-between` container — when the button is hidden, that container still renders with just the heading, which is fine (no layout break; verify visually in Task 12's live check).

- [ ] **Step 4: Gate the Edit and Delete row buttons**

Change the Edit button block (lines 492-499):

```tsx
                      {(!updatePermission || can(updatePermission)) && (
                        <button
                          type="button"
                          className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                          onClick={() => (onEdit ? onEdit(row) : openEditForm(row))}
                          aria-label={t['management.table.edit']}
                        >
                          <span className="material-symbols-outlined !text-md">edit</span>
                        </button>
                      )}
```

Change the Delete button block (lines 500-507):

```tsx
                      {(!deletePermission || can(deletePermission)) && (
                        <button
                          type="button"
                          className="cursor-pointer leading-none text-danger-500 transition-colors duration-150 hover:text-danger-600"
                          onClick={() => openDeleteConfirm(row.id)}
                          aria-label={t['management.table.delete']}
                        >
                          <span className="material-symbols-outlined !text-md">delete</span>
                        </button>
                      )}
```

- [ ] **Step 5: Typecheck, build, lint**

Run: `cd frontend/apps/management && npx tsc -b`
Run: `cd frontend && npm run build`
Run: `cd frontend/apps/management && npm run lint`

- [ ] **Step 6: Commit**

```bash
git add frontend/apps/management/src/components/GeoCrudTable.tsx
git commit -m "Add optional permission-gated Add/Edit/Delete to GeoCrudTable"
```

---

### Task 10: Unblock the four menus for school_admin in `App.tsx`

**Files:**
- Modify: `frontend/apps/management/src/App.tsx`

**Interfaces:**
- Produces: school_admin's sidebar gains Class Materials, Exam Control, Holiday Calendar, and a flat "Schools" item; the four corresponding route groups accept school_admin.

- [ ] **Step 1: Change the four route guards**

Change lines 292, 295, 299, 305 (the `element={<RequireNationalAdmin />}` on the `holidays`, `schools`, `class-materials`, and `exam-control` route groups) to `element={<RequireSchoolManager />}`. Do **not** change line 310 (`system-setup`) — that one stays `RequireNationalAdmin`, per the one named exception.

- [ ] **Step 2: Add a school_admin-only flat "Schools" nav item**

`schoolSetupItem` (lines 139-146) bundles Schools together with `schoolOperationsNavItems` (class-versions/shifts/sections/subject-teacher-assignments) as a nested group. school_admin already sees those four flat via `schoolManagedNavItems` (line 163) — reusing `schoolSetupItem` wholesale for school_admin would duplicate all four. Add a new, school_admin-specific item instead, right after the `schoolSetupItem` definition (after line 146):

```ts
// school_admin becomes able to reach School Setup too (scoped to their
// own single school by the backend's TenantScope fix — see
// TenantScope.php), but unlike national_admin, who sees Schools nested
// alongside class-versions/shifts/sections/subject-teacher-assignments
// in one "School Setup" group, school_admin already sees those four as
// flat top-level items (schoolManagedNavItems below) — bundling them
// again here would duplicate every one of those four entries. So
// school_admin gets a single flat "Schools" item of its own instead.
const schoolsOnlyNavItem: SidebarItem = { to: '/dashboard/schools', label: t['management.schools.title'], icon: 'apartment' }
```

- [ ] **Step 3: Update the stale comments above `classMaterialsNavItem`/`examControlNavItem`/`holidaysNavItem`/`schoolSetupItem`**

These four comments currently assert "national_admin only" as settled fact — now inaccurate. Update each:

Replace the comment above `classMaterialsNavItem` (lines 53-57):
```ts
// Visible to both school_admin and national_admin — school_admin holds
// books.view/chapters.view/chapter_videos.view but not create/update/
// delete (see RolePermissionSeeder), so GeoCrudTable's permission-gated
// Add/Edit/Delete (see BooksPage.tsx/AllChaptersPage.tsx/
// ClassVideosPage.tsx) renders this read-only for them, not role-gated
// like the route itself. Placed right after Students, per the explicit
// request to move it there.
```

Replace the comment above `examControlNavItem` (lines 68-72):
```ts
// Visible to both school_admin and national_admin — school_admin only
// holds the .view permission on each of these (see RolePermissionSeeder),
// not create/update/delete, so each page's own GeoCrudTable permission
// props render it read-only for them. Placed right after Class
// Materials, per the explicit request to move it there.
```

Replace the comment above `holidaysNavItem` (lines 113-118):
```ts
// The academic holiday calendar is national, not per-school — no
// TenantScope/school filtering applies to it. Visible to both
// school_admin and national_admin now (school_admin holds holidays.view
// but not create/update/delete, per RolePermissionSeeder — gated via
// GeoCrudTable's permission props on HolidaysPage.tsx, not via this nav
// item's role check).
```

Replace the comment above `schoolSetupItem` (lines 136-138):
```ts
// This full nested group (Schools + class-versions/shifts/sections/
// subject-teacher-assignments) stays national_admin only — a
// national_admin manages every school's operations data from one place.
// school_admin reaches Schools via the separate flat schoolsOnlyNavItem
// below instead, and already has the four nested items here as their
// own flat schoolManagedNavItems.
```

- [ ] **Step 4: Update `navItems` construction in `DashboardLayout`**

Change lines 241-250 from:

```ts
  const navItems = [
    ...baseNavItems,
    ...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem] : []),
    ...(role === 'national_admin' ? [classMaterialsNavItem, examControlNavItem] : []),
    ...(role === 'national_admin' || role === 'school_admin'
      ? [attendanceNavItem, publishResultsNavItem, promoteStudentsNavItem, routineNavItem]
      : []),
    ...(role === 'national_admin' ? [holidaysNavItem, schoolSetupItem] : []),
    ...(role === 'national_admin' ? [systemSetupItem] : role === 'school_admin' ? schoolManagedNavItems : []),
  ]
```

to:

```ts
  const navItems = [
    ...baseNavItems,
    ...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem] : []),
    ...(role === 'national_admin' || role === 'school_admin' ? [classMaterialsNavItem, examControlNavItem] : []),
    ...(role === 'national_admin' || role === 'school_admin'
      ? [attendanceNavItem, publishResultsNavItem, promoteStudentsNavItem, routineNavItem]
      : []),
    ...(role === 'national_admin' ? [holidaysNavItem, schoolSetupItem] : []),
    ...(role === 'school_admin' ? [holidaysNavItem, schoolsOnlyNavItem] : []),
    ...(role === 'national_admin' ? [systemSetupItem] : role === 'school_admin' ? schoolManagedNavItems : []),
  ]
```

(`national_admin`'s own line is untouched — same `[holidaysNavItem, schoolSetupItem]` array as before, so their nav is byte-for-byte identical to today.)

- [ ] **Step 5: Typecheck, build, lint**

Run: `cd frontend/apps/management && npx tsc -b`
Run: `cd frontend && npm run build`
Run: `cd frontend/apps/management && npm run lint`

- [ ] **Step 6: Commit**

```bash
git add frontend/apps/management/src/App.tsx
git commit -m "Unblock Class Materials, Exam Control, Holiday Calendar, and Schools for school_admin"
```

---

### Task 11: Wire permission props into the newly-reachable pages

**Files:**
- Modify: `frontend/apps/management/src/pages/BooksPage.tsx`
- Modify: `frontend/apps/management/src/pages/AllChaptersPage.tsx`
- Modify: `frontend/apps/management/src/pages/ClassVideosPage.tsx`
- Modify: `frontend/apps/management/src/pages/HolidaysPage.tsx`
- Modify: `frontend/apps/management/src/pages/AcademicExamTermsPage.tsx`
- Modify: `frontend/apps/management/src/pages/AcademicGradeConfiguresPage.tsx`
- Modify: `frontend/apps/management/src/pages/AcademicSubjectMarkConfiguresPage.tsx`
- Modify: `frontend/apps/management/src/pages/SchoolsPage.tsx`

**Context:** Before starting, grep `backend/database/seeders/PermissionSeeder.php` for each permission string below to confirm it actually exists as a seeded `Permission` row (not just assumed by naming convention) — e.g. `grep -n "books.create\|books.update\|books.delete" backend/database/seeders/PermissionSeeder.php`. If any string doesn't exist, stop and report back rather than guessing a different name.

Per `RolePermissionSeeder.php`, `school_admin` holds `.view` only (no `.create`/`.update`/`.delete`) for every one of these seven resources except Schools (`.view` + `.update`, no `.create`/`.delete`) — so this task makes these pages render read-only for school_admin and unchanged (every permission present) for national_admin, who holds `$allPermissions`.

- [ ] **Step 1: `BooksPage.tsx`**

Find the `<GeoCrudTable<Book> ...>` call (its props currently end with `quaternaryFilter={{...}}`). Add immediately after `deleteRow={(id, token) => deleteBook(id, token)}`:

```tsx
      createPermission="books.create"
      updatePermission="books.update"
      deletePermission="books.delete"
```

- [ ] **Step 2: `AllChaptersPage.tsx`**

Same pattern — find `<GeoCrudTable<Chapter> ...>`, add after its `deleteRow={...}` line:

```tsx
      createPermission="chapters.create"
      updatePermission="chapters.update"
      deletePermission="chapters.delete"
```

- [ ] **Step 3: `ClassVideosPage.tsx`**

Same pattern — find `<GeoCrudTable<ChapterVideo> ...>`, add after its `deleteRow={...}` line:

```tsx
      createPermission="chapter_videos.create"
      updatePermission="chapter_videos.update"
      deletePermission="chapter_videos.delete"
```

- [ ] **Step 4: `HolidaysPage.tsx`**

Find `<GeoCrudTable<Holiday> ...>` (its props end with `parentFilter={{...}}` today) — add the three props right after `deleteRow={(id, token) => deleteHoliday(id, token)}` (before `parentFilter`):

```tsx
      createPermission="holidays.create"
      updatePermission="holidays.update"
      deletePermission="holidays.delete"
```

- [ ] **Step 5: `AcademicExamTermsPage.tsx`**

Add after `deleteRow={(id, token) => deleteAcademicExamTerm(id, token)}`:

```tsx
      createPermission="academic_exam_terms.create"
      updatePermission="academic_exam_terms.update"
      deletePermission="academic_exam_terms.delete"
```

- [ ] **Step 6: `AcademicGradeConfiguresPage.tsx`**

Read this file first to find its `deleteRow={...}` line (it follows the same `GeoCrudTable` pattern as `AcademicExamTermsPage.tsx`, just for a different resource). Add immediately after it:

```tsx
      createPermission="academic_grade_configures.create"
      updatePermission="academic_grade_configures.update"
      deletePermission="academic_grade_configures.delete"
```

- [ ] **Step 7: `AcademicSubjectMarkConfiguresPage.tsx`**

Same as Step 6 — read the file, find `deleteRow={...}`, add immediately after:

```tsx
      createPermission="academic_subject_mark_configures.create"
      updatePermission="academic_subject_mark_configures.update"
      deletePermission="academic_subject_mark_configures.delete"
```

- [ ] **Step 8: `SchoolsPage.tsx`**

Add after `deleteRow={(id, token) => deleteSchool(id, token)}` (before `onView={...}`):

```tsx
      createPermission="schools.create"
      updatePermission="schools.update"
      deletePermission="schools.delete"
```

(school_admin holds `schools.update` but neither `schools.create` nor `schools.delete` — so for them this page shows the Edit icon but not the Add button or the Delete icon. National_admin is unaffected, holding every permission.)

- [ ] **Step 9: Typecheck, build, lint**

Run: `cd frontend/apps/management && npx tsc -b`
Run: `cd frontend && npm run build`
Run: `cd frontend/apps/management && npm run lint`

- [ ] **Step 10: Commit**

```bash
git add frontend/apps/management/src/pages/BooksPage.tsx frontend/apps/management/src/pages/AllChaptersPage.tsx frontend/apps/management/src/pages/ClassVideosPage.tsx frontend/apps/management/src/pages/HolidaysPage.tsx frontend/apps/management/src/pages/AcademicExamTermsPage.tsx frontend/apps/management/src/pages/AcademicGradeConfiguresPage.tsx frontend/apps/management/src/pages/AcademicSubjectMarkConfiguresPage.tsx frontend/apps/management/src/pages/SchoolsPage.tsx
git commit -m "Wire permission-gated Add/Edit/Delete into the newly-reachable pages"
```

---

### Task 12: Full regression run and live browser verification

**Files:** None modified — verification only.

- [ ] **Step 1: Full backend suite**

Run: `cd backend && php artisan test`
Expected: every test passes (this confirms Tasks 1-6 didn't regress anything else in the suite, including all of this session's earlier Books/Chapters/Class Videos work).

- [ ] **Step 2: Full frontend suite**

Run: `cd frontend && npx vitest run packages/api-client/src/index.test.ts` (and any `packages/auth` test file, if Task 7 added/found one)
Run: `cd frontend && npm run build`
Run: `cd frontend/apps/management && npm run lint`

- [ ] **Step 3: Live browser — school_admin**

Seed a fresh dev DB (`php artisan migrate:fresh --seed`), start backend + management frontend dev servers on dedicated ports (check for stale listeners first), log in as `school.admin@tlap.test` / `password`, and verify:
- Sidebar shows Dashboard, Teachers, Students, Class Materials (Books/Chapters/Class Videos), Exam Control (3 items), Attendance Reports, Publish Results, Promote Students, Routine, Holiday Calendar, Schools, Classes, Academic Years, Class Versions, Shifts, Sections, Subject-Teacher Assignments — and does **not** show System Setup.
- Books/Chapters/Class Videos/Holidays/Academic Exam Terms/Academic Grade Configures/Academic Subject Mark Configures pages: only school 1's data appears (cross-check against a second school's seeded data via tinker, same as this session's earlier verification pattern); no "Add new" button, no Edit/Delete icons on any row (school_admin holds `.view` only on all of these).
- Schools page: exactly one row (their own school); Edit icon present (they hold `schools.update`), no Add button, no Delete icon.
- Class Versions/Shifts/Sections/Subject-Teacher Assignments pages: only school 1's rows appear (previously leaked all 16 schools) — Add/Edit/Delete still present and working (unaffected by this plan's permission-gating, which only touched the 8 pages in Task 11).

- [ ] **Step 4: Live browser — national_admin regression check**

Log in as `national.admin@tlap.test` / `password` and confirm: sidebar unchanged from before this work, every page still shows every school's data, every Add/Edit/Delete action still present and working everywhere.

- [ ] **Step 5: Report**

Summarize pass/fail for each check above. If anything fails, fix before considering this plan complete — do not proceed to `finishing-a-development-branch` with a known-broken check.
