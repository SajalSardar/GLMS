# Design: National Version Catalog, Decoupled from the District-School-Class Mapping

**Status:** Approved, ready for implementation planning

## Scope

Today, `class_versions` is a single table that conflates two different
facts: (1) *what version names exist* (free text, typed per row — in
practice placeholder values like `"General"`, with nothing preventing a
school from inventing a third, fourth, or misspelled version), and (2)
*which district/school/class offers which version* (`class_id`, `school_id`,
`district_id` on the same row).

Across all of Bangladesh there are only two curriculum versions — Bangla
and English (`docs/Multi-Version-Multi-Shift-Multi-Section-Smart Timetable
Management.md` §2.3). This spec splits fact (1) into a small national
catalog table owned by `national_admin`, and keeps fact (2) — the
district/school/class mapping — as its own mechanism, which is already
what `class_versions` structurally is once the name is removed from it.

This mirrors the precedent already set by
`docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md`
(national class-name list + `class_schools` pivot) — same shape, applied to
versions instead of classes.

**Decisions confirmed during brainstorming:**

- The new `versions` catalog is a **hard-fixed set of exactly two rows**
  (Bangla, English), not an open CRUD resource. No `store`/`destroy` route
  exists for it at all — `national_admin` can only view and rename/
  translate/toggle `is_active` on the two seeded rows. This is enforced at
  the route level, not by convention.
- `ClassVersion` keeps exposing flat `name`/`bn_name` fields (now derived
  via accessors from the linked `Version`), so none of the ~20 pages that
  only *read* a class-version's name (Books, Shifts, Sections, Attendance,
  Class Routines, Subject Teacher Assignments, student-facing pages, etc.)
  need any change. Only the class-version admin CRUD form itself changes.
- `class_versions` (table and model) is **not renamed**. It already is the
  district/school/class mapping mechanism the request asks for — it just
  stops owning the name text. Every downstream FK (`shifts.class_version_id`,
  `student_profiles.class_version_id`, `class_routines.class_version_id`,
  `exams`, `books`, `chapter_materials`, `subject_teacher_assignments`,
  `student_class_promotions`, `attendances`, `student_leave_applications`,
  `academic_exam_marks`) is untouched — those all point at
  `class_versions.id`, whose identity never changes.

## Current state vs. target

Today: `class_versions` has `class_id`, `school_id`, `district_id`
(denormalized from the resolved `School` at creation, per
`ClassVersionController::store()`), free-text `name`, nullable `bn_name`,
`is_active`. Unique on `(class_id, school_id, name)`.

Target: a new national `versions` table holds exactly two rows (`name`,
`bn_name`, `is_active`). `class_versions` drops `name`/`bn_name`, gains
`version_id` (FK → `versions`), and its unique index becomes
`(class_id, school_id, version_id)`.

## Schema

### New `versions` table

```
id
name        string, unique
bn_name      string, nullable
is_active    boolean, default true
timestamps
```

No `SoftDeletes` — there is no delete path. Seed the two rows **inside the
migration's `up()`** (not a separate seeder step), so the fixed-set
invariant holds in every environment including a fresh `RefreshDatabase`
test run:

```php
DB::table('versions')->insert([
    ['name' => 'Bangla', 'bn_name' => 'বাংলা', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ['name' => 'English', 'bn_name' => 'ইংরেজি', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
]);
```

### `class_versions` (modify existing table)

```
backfill version_id from existing free-text name:
  case-insensitive match containing "english" -> English row's id
  everything else (including "General", "Bangla", NULL/blank)
    -> Bangla row's id
add    version_id  FK -> versions, restrictOnDelete
drop   name
drop   bn_name
drop   unique(class_id, school_id, name)
add    unique(class_id, school_id, version_id)
```

The backfill step exists for safety even though no production data is
expected yet (same assumption the school_classes restructure confirmed and
nothing since has changed it) — it costs nothing and protects any
dev/staging data that does exist.

## Models

### New `Version` model

`fillable: ['name', 'bn_name', 'is_active']`. No relations needed beyond
what `ClassVersion::version()` provides from the other side.

### `ClassVersion` (modify existing model)

- `fillable` swaps `name`/`bn_name` → `version_id`.
- Add `belongsTo(Version::class)`.
- Add `protected $with = ['version'];` — every query eager-loads it. The
  table is tiny (2 rows) so the join cost is negligible, and this is what
  makes the accessor-proxy approach safe against N+1 across the many
  existing call sites that read `->name`/`->bn_name` without having
  explicitly eager-loaded anything.
- Add derived accessors:
  ```php
  protected function name(): Attribute
  {
      return Attribute::make(get: fn () => $this->version->name);
  }

  protected function bnName(): Attribute
  {
      return Attribute::make(get: fn () => $this->version->bn_name);
  }
  ```
  (matches the `Attribute::make()` style already used in `Exam.php`, the
  only existing accessor in this codebase.)

## API surface

- **New `VersionController`** at `/versions` (flat resource, same
  convention as `/class-versions`, `/shifts`):
  - `index()` — `?search=`, no school/district filters (it's global).
  - `show()`.
  - `update()` — `name`/`bn_name`/`is_active`, uniqueness on `name`
    ignoring self.
  - **No `store()`/`destroy()`** — not registered on the route at all.
  - `VersionPolicy`: `viewAny`/`view` → `versions.view`; `update` →
    `versions.update`.
  - `VersionResource`: `id`, `name`, `bn_name`, `is_active`.
- **`ClassVersionController::store()`**:
  - Drop `name`/`bn_name` from the attributes assembled; add `version_id`.
  - Soft-delete-restore-in-place lookup changes from matching on `name` to
    matching on `version_id`.
- **`ClassVersionController::update()`** — same field swap.
- **`StoreClassVersionRequest`**:
  - Drop `name`/`bn_name` rules.
  - Add `version_id`: `required`, `integer`,
    `Rule::exists('versions', 'id')->where('is_active', true)`, plus the
    existing uniqueness constraint moved from `(class_id, school_id, name)`
    to `(class_id, school_id, version_id)`.
  - Keep the existing `class_schools` adoption-gate check in
    `withValidator()` unchanged.
- **`UpdateClassVersionRequest`** — same field swap; `version_id` becomes
  `sometimes`.
- **`ClassVersionResource`** — unchanged keys plus `version_id` added;
  `name`/`bn_name` stay (now derived).
- **`ClassVersionPolicy`** — unchanged.

## Permissions

New permissions in `PermissionSeeder`: `versions.view`, `versions.update`
(no `.create`/`.delete` — matches the closed-set decision; same shape as
`class_schools.updateSubjects` having no `.update` counterpart).

In `RolePermissionSeeder`:
- Every role that currently holds `class_versions.view`
  (`district_officer`, `school_admin`, `teacher`, `student`, `trainer`,
  `office_staff`, `parent_guardian`, `it_admin`, `auditor`) also gains
  `versions.view` — they need it to populate the Bangla/English picker
  wherever a class-version is created or displayed.
- `national_admin` gets `versions.update` via `$allPermissions`
  (unchanged, automatic).
- No other role gains `versions.update`.

## Seeders

- **`SectionBackfillSeeder`** — currently:
  ```php
  $version = ClassVersion::firstOrCreate(
      ['class_id' => $class->id, 'school_id' => $schoolId, 'name' => 'General'],
      ['district_id' => $districtId, 'is_active' => true],
  );
  ```
  Becomes `['class_id' => ..., 'school_id' => $schoolId, 'version_id' =>
  $bangladVersionId]` (resolve `$bangladVersionId` once via
  `Version::where('name', 'Bangla')->value('id')` at the top of `run()`) —
  `"General"` was always a stand-in for "whichever single version this
  school has," and Bangla is the natural default.

## Testing / Audit

- **Systematic audit pass** over every file found referencing
  "version"/"ClassVersion" (118 backend files, 22 frontend files per the
  search done during brainstorming) — confirm each one falls into exactly
  one of:
  1. Only uses `class_version_id` as an opaque FK → unaffected.
  2. Only reads `.name`/`.bn_name` off a class-version → unaffected by the
     accessor proxy.
  3. Is part of the admin CRUD surface covered explicitly above.

  Anything that doesn't fit one of these three buckets gets its own
  targeted fix, documented as a finding when the implementation plan
  executes this task.
- **New `VersionControllerTest`** — `index`/`show`/`update` work;
  `versions.view`/`versions.update` permission mapping; confirm there is
  no route for `store`/`destroy` (404, not 403 — the route plain doesn't
  exist); `update` rejects a duplicate `name`.
- **`ClassVersionControllerTest`** (existing, updated) — every fixture
  that creates a `ClassVersion` with a free-text `name` switches to
  resolving a seeded `Version` row's id; add a test asserting `store()`
  rejects an unknown `version_id` and an inactive one (422).
- **The ~71 other test files** that create `ClassVersion` fixtures with
  arbitrary `name` values — mechanical fixture updates only (swap `'name'
  => 'Something'` for `'version_id' => <a seeded Bangla or English id>`),
  no logic changes expected since none of them assert on the free-text
  value itself (confirmed during the audit pass above, not assumed).
- Full backend test suite run against MySQL, not just SQLite, per this
  project's documented SQLite/MySQL migration-behavior gap.

## Frontend

- **`ClassVersionsPage.tsx`**: replace the free-text `name`/`bn_name`
  inputs with a picker fetching `/versions` and submitting `version_id`
  instead of `name`/`bn_name`. Table/detail columns keep showing
  `row.name`/`row.bn_name` (now proxied server-side, API shape unchanged).
  `api-client`'s `createClassVersion`/`updateClassVersion` payload types
  and the `ClassVersion` type gain `version_id`, drop `name`/`bn_name` from
  the *write* payload type (the *read* type keeps them).
- No other frontend file requires a change — confirmed by the audit pass,
  since every other consumer only displays `.name`/`.bn_name` or passes
  `class_version_id` through as an opaque value.

## Out of scope

- Any change to `version_subjects` (`ClassVersion` ↔ `Subject`) — stays
  exactly as it is; keyed on `class_versions.id`, unaffected.
- Any change to `Shift`, `Section`, or any of the ten tables that carry a
  `class_version_id` FK — their schema and behavior are untouched.
- Re-translating or renaming the seeded Bangla/English `bn_name` values
  beyond the defaults above — `national_admin` can adjust via
  `PATCH /versions/{version}` after the fact if the translation needs
  tuning; not a blocker for this spec.

## Definition of done

- `versions` table exists with exactly two rows seeded at migration time;
  no `store`/`destroy` route exists for it.
- `class_versions` has no `name`/`bn_name` columns; has `version_id` FK;
  unique on `(class_id, school_id, version_id)`.
- `ClassVersion` exposes `name`/`bn_name` as derived accessors with no
  N+1 regression (`$with = ['version']`).
- `ClassVersionController::store()`/`update()` require and validate
  `version_id` against the active rows in `versions`.
- `versions.view`/`versions.update` permissions exist and are mapped to
  the roles listed above.
- `SectionBackfillSeeder` and the full `DatabaseSeeder` chain run cleanly
  end to end.
- The audit pass is complete with every one of the 118+22 referencing
  files classified and, where needed, fixed.
- `ClassVersionsPage.tsx` uses the Bangla/English picker; no other
  frontend page required a change.
- Full backend test suite passes against MySQL.
