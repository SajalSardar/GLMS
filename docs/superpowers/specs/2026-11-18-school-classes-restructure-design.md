# Design: Restructure `school_classes` into a National Class List + Per-School Assignment

**Status:** Approved, ready for implementation planning
**Sub-project:** 2 of 5 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

Context carried from `docs/superpowers/specs/2026-11-18-national-content-descoping-design.md`'s
own program decomposition (sub-project 1, already merged): a public, nationally
shared student-facing learning platform on top of the existing school-tenant
system, decomposed into five sub-projects:

1. De-scope national content + remove video progress — **done, merged**.
2. **Restructure `school_classes`** (this spec) — becomes a national
   class-name list with a new school-assignment pivot table.
3. Public/guest exam-taking.
4. Management frontend class-wise filter fixes — depends on (2).
5. New public student frontend — depends on (2) and (3).

Each sub-project gets its own spec → plan → implementation cycle.

## Scope of this spec

Make `school_classes` a national list of class names (e.g. "Class Six"),
owned by `national_admin`, with a new pivot table recording which schools
offer which classes. Move the existing per-class subject list
(`class_subjects`) from a global fact to a per-school fact, since it's
real, load-bearing validation logic today (it gates `subject_id` validity
when creating a class routine) and schools legitimately differ in which
subjects they teach for a given class. Backend only — no frontend work
(deferred to sub-project 4, same precedent as sub-project 1).

## Current state vs. target

Today, `SchoolClass` is `TenantScoped`: `school_id`/`district_id` are
columns on the row itself (not denormalized from a parent — they're the
actual owning facts), plus an optional `section` field (a curriculum
stream like "Science"/"Commercial" — unrelated to the `Section` model used
in the timetable hierarchy, which belongs to a `Shift`). Each school has
its own row per class name (`ClassSeeder` creates one `SchoolClass` row
per school per grade level). `class_subjects` (`SchoolClass` ↔ `Subject`)
is a plain many-to-many with no school dimension.

**Decisions confirmed during brainstorming:**

- `section` (the stream field) is dropped entirely, not relocated. Stream/
  grouping distinctions are already expressible through the existing
  `ClassVersion`/`Shift`/`Section` hierarchy; `school_classes` doesn't need
  its own copy of that concept.
- `class_subjects` moves to being a per-school fact (gains a `school_id`
  column) rather than becoming a national fact — matches how the parallel
  `version_subjects` (`ClassVersion` ↔ `Subject`) pivot already works
  per-school, and avoids assuming every school follows an identical
  national subject list per class.
- Authorship splits the same way sub-project 1 split authorship of
  national content: `national_admin` owns the global class-name list
  (create/update/delete); `school_admin` owns their own school's
  class-schools assignment and that assignment's subject list, scoped to
  their own school.

Target: `school_classes` has no tenant columns, no `TenantScoped`, no
`section` column — just `name`, `bn_name`, `numeric_level`. A new pivot
table `class_schools` records which schools have adopted which classes.
`class_subjects` gains a `school_id` column, making "which subjects does
this school's offering of this class teach" a per-(school, class) fact
instead of a global one.

**Critical hidden dependency, found while researching this spec (not
obvious from the request alone):** `ClassVersionController::store()`
currently denormalizes `class_versions.school_id`/`district_id` from
`$class->school_id`/`$class->district_id` — i.e. today, a `ClassVersion`'s
school is *implied* by which school's `SchoolClass` row it points at.
Once `school_classes` has no `school_id`, this denormalization source is
gone, and nothing stops a request from pointing a `ClassVersion` at a
class the target school never adopted. The new `class_schools` pivot
becomes the gatekeeper that used to be implicit: `ClassVersionController`
must now take an explicit `school_id` and validate `(class_id, school_id)`
exists in `class_schools` before creating or denormalizing from it. The
same pattern applies to `StudentProfileController::assertClassBelongsToSchool()`,
which currently does `SchoolClass::where('id', $classId)->where('school_id',
$schoolId)->doesntExist()` — this must become a `class_schools` existence
check instead.

## Schema

### `school_classes` (modify existing table)

```
drop school_id        (+ its FK, + the old unique(school_id, name, section))
drop district_id       (+ its FK)
drop section
add  unique(name)
```

`SchoolClass` model: drop `TenantScoped` (and its import), drop `school()`/
`district()` relations, drop `section` from `$fillable`. Add
`schools(): BelongsToMany` via the new `class_schools` pivot.

### New `class_schools` table

```
id
class_id      FK -> school_classes, cascade delete
school_id     FK -> schools, cascade delete
district_id   denormalized from school at creation time (never taken
              directly from the request) — same convention as every other
              TenantScoped table in this app
timestamps

unique(class_id, school_id)
```

New model `ClassSchool`: `TenantScoped` (it's a per-school fact — a school-
scoped actor should only see/manage their own school's assignments);
`belongsTo(SchoolClass::class, 'class_id')`, `belongsTo(School::class)`,
`belongsTo(District::class)`. `School::classes()` changes from
`hasMany(SchoolClass)` to `belongsToMany(SchoolClass::class,
'class_schools')` (zero existing call sites for this relation — confirmed
by searching the codebase — so this change has no ripple of its own).

### `class_subjects` (modify existing pivot table)

```
add school_id    FK -> schools, cascade delete (never taken directly from
                 the request — resolved from the class_schools row being
                 configured)
drop unique(class_id, subject_id)   (confirmed present in
                                     2026_10_28_065054_create_class_subjects_table.php)
add  unique(class_id, subject_id, school_id)
```

No new model — `class_subjects` stays a plain pivot, reached through
`ClassSchool`'s own subject-management action (see API surface below), not
through a bare `SchoolClass`/`Subject` relation accessor (a bare
`SchoolClass::subjects()` with no school context is no longer a meaningful
question to ask).

## API surface

- **`ClassController`** (global class-name CRUD, `/classes`):
  - `index()` — drop `?school_id=`/`?district_id=` filters; keep `?search=`.
  - `store()` — drop `school_id` resolution entirely; no denormalization.
    Uniqueness check changes from `(school_id, name, section)` to `(name)`
    alone (soft-deleted-restore lookup follows the same simplification).
  - `update()` — same uniqueness simplification.
  - `updateSubjects()` — **removed**. A bare global `SchoolClass` has no
    meaningful "its subjects" anymore; subject configuration moves to the
    `class_schools` resource below.
  - `StoreClassRequest`/`UpdateClassRequest` — drop `school_id`/`section`
    validation rules entirely; uniqueness `Rule::unique('school_classes',
    'name')->withoutTrashed()` with no `where()` scoping.
  - `ClassResource` — drop `school_id`/`district_id`/`section`/`school`
    keys. Keep `subjects` key but it's now only ever populated when loaded
    through a `class_schools`-scoped query, never from a bare
    `SchoolClass::with('subjects')` (there's no longer a direct
    `SchoolClass::subjects()` relation to eager-load this way).
- **New `ClassSchoolController`** over a new flat resource `/class-schools`
  (this app has no `/schools/{school}/...` nesting convention anywhere —
  confirmed by checking `routes/api.php` — so this follows the existing
  flat-resource pattern, same as `/class-versions`, `/shifts`, `/sections`):
  - `index()` — `?school_id=`/`?class_id=` filters, tenant-scoped via
    `ClassSchool`'s own `TenantScoped`.
  - `store()` — assign a class to a school: validates `class_id` exists
    (`school_classes`, `withoutTrashed()`), resolves `school_id`/
    `district_id` the same way every other `TenantScoped` store() in this
    app does (denormalized from the actor's own school for a school-scoped
    actor; explicit `school_id` input for `national_admin`, matching the
    pattern `StoreAcademicYearRequest` used pre-sub-project-1).
  - `destroy()` — unassign. Blocked (422, same `ValidationException`
    pattern as `BookController::destroy()`'s chapter-exists check) while
    the school has any `ClassVersion` pointing at this `(class_id,
    school_id)` pair — removing the assignment out from under live
    curriculum data would orphan those versions.
  - `PUT /class-schools/{class_school}/subjects` — replace this specific
    school's subject list for this class (syncs `class_subjects` rows
    scoped to `class_id` + this `school_id`), mirroring the existing
    `PUT /class-versions/{class_version}/subjects` pattern exactly.
  - `ClassSchoolPolicy` — `view`/`create`/`delete`/`updateSubjects`,
    single-parameter abilities (no scope parameter — `TenantScoped`'s
    global scope already prevents reaching another school's row), same
    shape as every policy in this app since the multi-portal-auth spec's
    collapsed-policy design.
  - `ClassSchoolResource` — `id`, `class_id`, `school_id`, `district_id`,
    `class` (whenLoaded), `school` (whenLoaded), `subjects`
    (`SubjectResource::collection`, whenLoaded, with `required_weekly_periods`
    pivot data **not** carried — that field is specific to
    `version_subjects` and has no equivalent here).
- **`ClassVersionController::store()`** — add a required `school_id` to
  `StoreClassVersionRequest`; validate `ClassSchool::where('class_id',
  $classId)->where('school_id', $schoolId)->exists()` (via the request's
  `authorize()`, same place it currently resolves `$school` from
  `SchoolClass::find(...)->school` — that line is replaced, not added
  alongside); denormalize `school_id`/`district_id` from the resolved
  `School` instead of from `$class`.
- **`ClassRoutineController::store()`** has the same denormalization
  problem as `ClassVersionController::store()`, confirmed by reading the
  full current method: it sets `'school_id' => $class->school_id,
  'district_id' => $class->district_id,` directly from the `SchoolClass`
  it just resolved. `StoreClassRoutineRequest` doesn't take an explicit
  `school_id` today — it needs to gain one, validated against
  `class_schools` the same way `StoreClassVersionRequest` is fixed above
  (a class routine is created for one specific school's offering of a
  class, and that pairing must exist in `class_schools` first).
- **`ClassRoutineController::assertValid()`** has two separate problems,
  both found by reading the full current method, not just the subject
  check mentioned during brainstorming:
  - The subject-validity check (`$class->subjects()->where('subjects.id',
    $attributes['subject_id'])->exists()`) becomes a direct query once
    `class_subjects` is school-scoped and `SchoolClass::subjects()` no
    longer exists as a bare relation: `DB::table('class_subjects')
    ->where('class_id', $class->id)->where('school_id',
    $attributes['school_id'])->where('subject_id',
    $attributes['subject_id'])->exists()`.
  - The teacher/classroom school-match checks (`$teacher->school_id !==
    $class->school_id`, `$classroom->school_id !== $class->school_id`)
    also read `$class->school_id`, which is gone. Both become
    `!== $attributes['school_id']` instead — the routine's own (now
    explicitly-resolved, not denormalized-from-$class) `school_id`.
- **`StudentProfileController::assertClassBelongsToSchool()`** — currently
  `SchoolClass::where('id', $classId)->where('school_id',
  $schoolId)->doesntExist()`. Becomes `ClassSchool::where('class_id',
  $classId)->where('school_id', $schoolId)->doesntExist()`.

## Permissions

New permissions in `PermissionSeeder`: `class_schools.view`,
`class_schools.create`, `class_schools.delete`, `class_schools.updateSubjects`
(no `.update` — a `ClassSchool` row itself has nothing mutable beyond its
subject list, which gets its own ability, same shape as
`chapter_videos.reorder`/`questions.reorder` being their own abilities
rather than reusing `.update`).

In `RolePermissionSeeder`:
- `school_admin` loses `classes.create/update/delete`, keeps
  `classes.view`; gains `class_schools.view/create/delete/updateSubjects`.
- Every other role that currently holds `classes.view` keeps it unchanged.
- `national_admin` keeps everything via `$allPermissions` (unchanged).

## Seeders

All three run as part of the regular `DatabaseSeeder` chain today (not
one-off historical scripts), so all three need rewriting, not just
patching:

- **`ClassSeeder`** — currently creates one `SchoolClass` row *per school
  per grade level*. Rewrite: create each class name **once**, globally
  (`SchoolClass::updateOrCreate(['name' => ...], ['bn_name' => ...,
  'numeric_level' => ...])`), then for every school, create a
  `ClassSchool` row assigning that school to every grade level its
  `gradesFor($school->level)` already computes today — the grade-band
  logic itself (`primary`/`secondary`/`higher_secondary`/`college` →
  grade list) is unchanged, only the target table changes.
- **`ClassSubjectSeeder`** — currently `$class->subjects()->sync(...)`
  once per global `SchoolClass`. Rewrite: iterate `ClassSchool::all()`
  instead of `SchoolClass::all()`, compute the same `subjectsFor($numericLevel)`
  list (now read from the assignment's `schoolClass->numeric_level`), and
  write directly to the `class_subjects` pivot scoped to that
  `(class_id, school_id)` pair (no bare `SchoolClass::subjects()` relation
  to sync through anymore — use `DB::table('class_subjects')->insert(...)`
  or a small explicit helper, following whichever idiom this seeder's
  sibling seeders in this codebase already use for pivot rows that carry
  an extra column beyond the two FKs).
- **`SectionBackfillSeeder`** — currently `'name' => $class->section ??
  'Main'`. Since `section` no longer exists, this becomes unconditionally
  `'name' => 'Main'`.

## Testing

- `ClassControllerTest` (existing, updated) — drop every assertion/setup
  referencing `school_id`/`district_id`/`section`; add a permission-mapping
  test (`classes.create` → exactly `['national_admin']`), following the
  exact pattern established in sub-project 1's `*AuthoringPermissionMappingTest`
  files.
- `ClassSchoolControllerTest` (new) — CRUD, tenant-scoping (a School A actor
  cannot see/assign/unassign School B's rows), the destroy-blocked-while-
  class-versions-exist guard, the subjects-sync endpoint, policy denial for
  an actor without `class_schools.*` permissions.
- `ClassVersionControllerTest` (existing, updated) — every test that
  creates a `ClassVersion` must first create the corresponding
  `ClassSchool` assignment row; add a test asserting `store()` rejects a
  `(class_id, school_id)` pair with no matching `class_schools` row (422,
  not a silent denormalization of null/wrong values).
- `ClassRoutineControllerTest`/`StudentProfileControllerTest` (existing,
  updated) — update their subject-validity and class-belongs-to-school
  fixtures to create the `class_schools`/`class_subjects` rows these
  checks now require, and add a test for the now-explicit "class not
  offered by this school" rejection path on each (including
  `ClassRoutineController::store()`'s new required `school_id` field).
- `ClassSeederTest`/seeder-chain tests (if a general `DatabaseSeederTest`
  exercises this chain, as one already does per sub-project 1's Task 10
  findings) — confirm the rewritten seeders still produce a usable
  dataset (each seeded school ends up with the right `ClassSchool`
  assignments and `class_subjects` rows for its grade band).

## Out of scope for this sub-project

- Any frontend change (management or student) — deferred to sub-project 4,
  which must explicitly pick up `ClassesPage.tsx` (currently has a
  `school_id` required field and a school filter that will both silently
  stop working against the new API), `ClassVersionsPage.tsx` (now a hard
  break: it posts only `{class_id, name}` with no `school_id`, so every
  class-version creation will 403 once this branch merges, since
  `StoreClassVersionRequest::authorize()` requires `school_id`), and
  `StudentDetailPage.tsx` (its class picker is now fed by the unfiltered
  national class list, so a user could pick a class their school hasn't
  adopted, which now 422s) — in addition to whatever else that
  sub-project already covers. More broadly, `GET /api/v1/classes` dropped
  its `school_id`/`district_id` query-param filters entirely (it's the
  national list now), so any frontend code that relied on implicit
  per-school filtering there needs to go through `/class-schools?school_id=`
  instead — this affects more of the frontend surface than just the three
  pages named above.
- `ClassVersion`, `Shift`, `Section` schema — these remain school-owned,
  untouched by this sub-project (only their *validation logic* against the
  new `class_schools` gate changes, not their own columns).
- Any change to `version_subjects` (`ClassVersion` ↔ `Subject`) — stays as
  it is; it already works per-school and is unrelated to this
  restructuring beyond both being "subject assignment" pivots.
- Re-numbering or renaming existing class names (e.g. consolidating
  "Class 6" across schools that might have seeded slightly different
  names) — not needed since no production data exists yet (confirmed
  during sub-project 1's brainstorming for this same codebase state, and
  nothing since has introduced production class data).

## Definition of done

- `school_classes` has no `school_id`/`district_id`/`section` columns; no
  `TenantScoped`; unique on `name` alone.
- `class_schools` table and `ClassSchool` model exist, `TenantScoped`,
  with denormalized `district_id`.
- `class_subjects` has a `school_id` column and a 3-column unique
  constraint.
- `ClassController` is `national_admin`-only for create/update/destroy;
  `updateSubjects()` is removed from it.
- `ClassSchoolController`/`ClassSchoolPolicy`/requests/resource exist at
  `/class-schools`, following this app's flat-resource convention, with
  `school_admin` scoped to their own school via `TenantScoped` and able to
  create/delete assignments and sync that assignment's subject list.
- `ClassVersionController::store()` requires an explicit `school_id` and
  rejects a `(class_id, school_id)` pair absent from `class_schools`.
- `ClassRoutineController::store()` requires an explicit `school_id`
  (validated against `class_schools`, same as `ClassVersionController`)
  instead of denormalizing from `$class->school_id`; its subject-validity
  and teacher/classroom school-match checks in `assertValid()` query
  `class_subjects`/compare against the routine's own resolved
  `school_id` instead of the now-nonexistent `$class->school_id`.
  `StudentProfileController::assertClassBelongsToSchool()` queries
  `class_schools` directly instead of the now-nonexistent
  `school_classes.school_id`.
- `ClassSeeder`/`ClassSubjectSeeder`/`SectionBackfillSeeder` are rewritten
  and the full `DatabaseSeeder` chain runs cleanly end to end.
- Full backend test suite passes against MySQL (not just SQLite — per
  this project's documented SQLite/MySQL migration-behavior gaps).
