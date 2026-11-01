# Design: Content Hierarchy Extension (Book Model + Chapter Restructure)

**Status:** Approved, ready for implementation planning
**Sub-project:** 1 of 6 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

The requirement is a complete chapter-wise Learning Management System on
top of the existing academic hierarchy (`School → AcademicYear → SchoolClass
→ ClassVersion → Subject → Chapter`), adding: book/PDF management, YouTube
tutorial management, a question bank, exam management, student exam-taking
with grading, and a student learning dashboard. This is too large for a
single spec and has been decomposed into six sub-projects, built in this
order:

1. **Content hierarchy extension** (this spec) — introduce `Book` between
   `Subject` and `Chapter`, so the hierarchy becomes `... → Subject → Book
   → Chapter`, matching the required `Class → Version → Subject → Book →
   Chapter` shape.
2. **Chapter content management** — book/chapter PDF uploads (private
   storage, signed URLs, access tracking) and YouTube tutorial video
   management (CRUD, ordering, IFrame progress tracking), both hanging off
   `Chapter` from sub-project 1. Built together since both are "chapter
   content" admin surfaces over the same parent resource.
3. **Question bank** — question types, bulk import/export, permissions.
   Depends on (1) for the chapter linkage.
4. **Exam management (admin/teacher)** — exam configuration, manual/bank/
   auto-random question selection. Depends on (3).
5. **Student exam-taking + grading** — attempt APIs/UI, auto and manual
   marking, results. Depends on (4).
6. **Student learning dashboard** — chapter/subject progress rollups
   across materials, video, and exam completion. Depends on (2) and (5).

Each sub-project gets its own spec → plan → implementation cycle.

## Scope of this spec

Add a `Book` model and move `Chapter` to belong to `Book` instead of
`Subject` directly. No PDF storage, no video/question/exam logic — this
sub-project only builds the empty `Book`/`Chapter` skeleton those later
sub-projects attach to.

## Current state vs. target

`Chapter` currently belongs directly to `Subject`. Both `Subject` and
`Chapter` are shared reference data today — no `school_id`/`district_id`
column, not `TenantScoped`, per the multi-portal-auth spec's "models that
do NOT get it" list (`docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md`).
`ClassVersion`, by contrast, already is `TenantScoped`, with
`school_id`/`district_id` denormalized from its parent `SchoolClass` at
creation time.

The target hierarchy requires chapter content (and, in later sub-projects,
PDFs/videos/questions/exams hanging off it) to differ **per class version**
— the brief's own example shows "Bangla Version → Mathematics → Mathematics
Book → Chapter 1" and "English Version → Mathematics → Mathematics Book →
Chapter 1" as distinct content trees, even though both versions link to the
same `Subject` row (`Mathematics`) via the existing `version_subjects`
pivot. `Subject` itself is not duplicated per version — only `Book` (and
below it, `Chapter`) is version-specific. This means `Book` must sit at the
tenant-scoped layer, inheriting tenant ownership from `ClassVersion`, and
`Chapter` moves with it.

**Decisions confirmed during brainstorming:**

- Book/Chapter content is **year-independent** — `Book`/`Chapter` key off
  `ClassVersion` + `Subject` only, not `AcademicYear`. The same book is
  reused every year until an admin explicitly edits it. `AcademicYear`
  continues to govern enrollment/exam scheduling in later sub-projects,
  not curriculum content.
- A single `(ClassVersion, Subject)` pair **may have multiple `Book`s**
  (e.g. a school could run both "Mathematics" and a supplementary
  "Mathematics Practice Book" under the same version+subject). `Book` is
  not a 1:1 wrapper; no `unique(class_version_id, subject_id)` constraint.

## Schema

### New `books` table

```
id
class_version_id   FK -> class_versions, cascade delete
subject_id          FK -> subjects, cascade delete
school_id            denormalized from class_version at creation (never taken from the request)
district_id          denormalized from class_version at creation
name
bn_name              nullable
order_index          nullable, unsigned smallint
description          nullable
is_active            boolean, default true
timestamps
soft deletes

unique(class_version_id, subject_id, name)
```

`Book` model: `TenantScoped`; `belongsTo(ClassVersion)`, `belongsTo(Subject)`,
`hasMany(Chapter)` ordered by `order_index`.

`Subject` model gains `books(): HasMany` (`hasMany(Book::class)`). Its
existing `chapters(): HasMany` relation is **removed** — it returns
`hasMany(Chapter::class)`, which breaks once `Chapter` no longer has a
`subject_id` column. Two call sites depend on it and must change:
`SubjectController::show()` currently does `$subject->load('chapters')`
(remove — a bare `Subject` no longer has a direct chapter list; its
`Book`s are reachable through `ClassVersion`/`BookController`, not through
`Subject` itself); `SubjectController::destroy()` currently blocks deletion
with `if ($subject->chapters()->exists())` (change to `if
($subject->books()->exists())`, message "This subject still has books and
cannot be deleted."). `SubjectResource`'s `'chapters' =>
ChapterResource::collection($this->whenLoaded('chapters'))` key is removed
(not replaced with a `books` key — out of scope here, consistent with
"Out of scope" below).

### `chapters` table changes (new additive migration, not editing the
original `2026_10_28_125244_create_chapters_table.php` — matches this
repo's existing convention of additive migrations, e.g.
`add_chapter_number_to_chapters_table`)

```
add book_id          FK -> books, cascade delete
add school_id         denormalized from book at creation
add district_id       denormalized from book at creation
drop subject_id
drop unique(subject_id, name); add unique(book_id, name)
```

`Chapter` model: `TenantScoped` added; `belongsTo(Book)` replaces
`belongsTo(Subject)`. Existing columns (`name`, `bn_name`, `order_index`,
`chapter_number`, `bn_chapter_number`, `description`) are unchanged.

No production data exists for either table yet (Phase 1 foundation, per
`docs/PROJECT_ANALYSIS.md` §6 — "no course/video/assessment logic" built
so far), so this is a clean structural change, not a data migration.

## API / permissions

Mirrors the existing `ChapterController`/`SubjectController` pattern
exactly:

- `BookController` — `index`/`show` public routes (tenant-filtered via the
  global scope when a user is authenticated, matching every other
  `TenantScoped` resource's route registration), `store`/`update`/`destroy`
  behind `auth:sanctum` + `BookPolicy`. `index` supports
  `?class_version_id=` and `?subject_id=` filters.
- `BookPolicy` — `books.view`/`books.create`/`books.update`/`books.delete`,
  single-parameter `create(User $user): bool` (no scope parameter, per the
  multi-portal-auth spec's collapsed-policy design — the global scope
  already prevents fetching a foreign-tenant `ClassVersion` to create
  against).
- `StoreBookRequest`/`UpdateBookRequest` — validate `class_version_id`
  exists (`class_versions` table, `withoutTrashed()`), `subject_id` exists
  (`subjects`, `withoutTrashed()`), `name` unique per
  `(class_version_id, subject_id)` excluding trashed (same soft-delete
  restore-on-recreate behavior as `ChapterController::store()`).
- `BookResource` — `id`, `class_version_id`, `subject_id`, `name`,
  `bn_name`, `order_index`, `description`, `is_active`, plus
  `class_version`/`subject` when loaded.
- New permissions seeded in `PermissionSeeder` (`books.view/create/update/
  delete`) and mapped in `RolePermissionSeeder` to the same roles that
  currently hold `chapters.*`.

`ChapterController`/`ChapterPolicy`/`StoreChapterRequest`/
`UpdateChapterRequest`/`ChapterResource` are updated to key off `book_id`
instead of `subject_id`:

- `index` filter changes from `?subject_id=` to `?book_id=`.
- `StoreChapterRequest` validates `book_id` exists (`books`,
  `withoutTrashed()`) instead of `subject_id`; uniqueness check moves to
  `(book_id, name)`.
- `ChapterResource` exposes `book_id` instead of `subject_id`, plus `book`
  when loaded (no longer `subject`).
- The soft-deleted-chapter restore-on-recreate logic in
  `ChapterController::store()` keys off `book_id` instead of `subject_id`.

Routes: add `Route::apiResource('books', BookController::class)` to
`routes/api.php` following the existing split (public `only(['index',
'show'])` outside the `auth:sanctum` group, mutating verbs inside it) —
same pattern as every other resource in that file.

## Frontend

**Correction (found while writing the implementation plan):** `frontend/apps/management/src` currently contains only a placeholder dashboard (`DashboardPlaceholder.tsx`) — there is no existing Subject/Chapter/ClassVersion admin screen to extend, contrary to this section's original assumption. Building real admin screens is the job of the separate "Management Portal" sub-project already decomposed in `docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md` (sub-project 3 of its own 5-part program), not a side effect of each backend content sub-project. **Decision: this sub-project is backend-API-only.** No frontend work is included; the Book/Chapter CRUD screens are built whenever that Management Portal sub-project is executed, against the API this sub-project delivers.

## Testing

- `BookControllerTest` (new) — CRUD, tenant-scoping (a School A admin
  cannot see/edit/delete a School B book; a `national_admin` sees both),
  policy denial for a user without `books.*` permissions, soft-delete
  restore-on-recreate, uniqueness validation.
- `ChapterControllerTest` (existing, updated) — every test that currently
  creates a chapter against a bare `subject_id` is updated to create
  against a `book_id` instead; add a tenant-scoping test matching the new
  `BookControllerTest` pattern (chapters didn't need one before — they
  weren't tenant-owned).
- A factory-level assertion (unit or feature) that a `Book`'s and a
  `Chapter`'s denormalized `school_id`/`district_id` always match their
  parent `ClassVersion`'s, confirming the denormalization-at-creation
  pattern was followed correctly (mirrors the same check the
  multi-portal-auth spec's test suite runs for `ClassVersion` itself).

## Out of scope for this sub-project

- PDF storage/upload, YouTube video management, question bank, exam
  management, student-facing APIs, dashboards — all later sub-projects.
- No `AcademicYear` linkage on `Book`/`Chapter` (confirmed year-independent
  above).
- No bulk-import/export tooling for books/chapters (the question bank
  sub-project introduces bulk import patterns; nothing here needs it).
- No change to `Subject`'s own tenant-scoping status — it remains shared
  reference data, untouched by this sub-project.

## Definition of done

- `books` table and `Book` model exist, `TenantScoped`, with
  `class_version_id`/`subject_id`/denormalized `school_id`/`district_id`,
  multiple books per `(class_version_id, subject_id)` allowed.
- `chapters` table has `book_id` (not `subject_id`) and denormalized
  `school_id`/`district_id`; `Chapter` is `TenantScoped`.
- `BookController`/`BookPolicy`/`StoreBookRequest`/`UpdateBookRequest`/
  `BookResource` exist, following the exact conventions of the existing
  `Chapter` equivalents; routes registered in `routes/api.php`.
- `ChapterController` and its policy/requests/resource are updated to key
  off `book_id` everywhere `subject_id` was used before.
- `Subject::chapters()` is removed and replaced by `Subject::books()`;
  `SubjectController::show()`/`destroy()` and `SubjectResource` are updated
  to match (see "Schema" above).
- `books.*` permissions seeded and mapped to the same roles as
  `chapters.*`.
- `BookControllerTest` passes (CRUD, tenant scoping, policy denial,
  soft-delete restore, uniqueness); `ChapterControllerTest` is updated and
  passes against the new `book_id` relationship.
- No frontend work is included in this sub-project (see "Frontend"
  correction above) — the Book/Chapter CRUD screens are deferred to the
  separate Management Portal sub-project.
