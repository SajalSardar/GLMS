# Design: De-scope National Content Tables + Remove Video Progress Tracking

**Status:** Approved, ready for implementation planning
**Sub-project:** 1 of 5 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

The requirement (`inspiration/new_instructions.txt`) is a public, nationally
shared student-facing learning platform on top of the existing school-tenant
system: national content (books/chapters/videos/questions) stop being
per-school, `school_classes` becomes a national class list with a
per-school assignment pivot, exam-taking gets a public/guest mode, the
management frontend's class-wise filters need fixing to match, and a new
public Tailwind student frontend is built. This is too large for a single
spec and has been decomposed into five sub-projects:

1. **De-scope national content + remove video progress** (this spec) —
   drop `school_id`/`district_id` from `academic_years`, `books`,
   `chapters`, `chapter_materials`, `chapter_videos`, `questions`; remove
   `chapter_video_progress` entirely; restrict authoring of this content to
   `national_admin`.
2. **Restructure `school_classes`** — becomes a national class-name list
   with a new school-assignment pivot table (`district_id` dropped).
   Independent of (1); both are prerequisites for (4) and (5).
3. **Public/guest exam-taking** — anonymous users can take an exam and see
   a result; nothing persists unless the user is logged in.
4. **Management frontend class-wise filter fixes** — audit/fix once (2)
   lands.
5. **New public student frontend** — Tailwind, visual redesign matching
   `inspiration/login&frontend.jpeg`, class-wise Books search/download/PDF
   view, subject-wise video playlists, the exam flow from (3) — all
   working logged-out, with tracking when logged-in.

Each sub-project gets its own spec → plan → implementation cycle.

## Scope of this spec

Remove the school/district tenant columns from six national-content tables
and delete the video-progress-tracking feature. No change to any other
table's schema (in particular, `books.class_version_id` keeps pointing at
a school-owned `class_versions` row — see "Rejected alternative" below).
No frontend work.

## Current state vs. target

Today, `AcademicYear`, `Book`, `Chapter`, `ChapterMaterial`, `ChapterVideo`,
and `Question` all use the `TenantScoped` trait: each has `school_id`/
`district_id` denormalized from its parent at creation time, and
`TenantScope` auto-filters every query against those columns for an
authenticated school- or district-scoped actor. Per the brief, this content
is the same across all of Bangladesh (per subject/chapter), so there is no
tenant to scope by.

**Rejected alternative (confirmed during brainstorming):** `books` could
instead key off a national `class_id` + `subject_id` (dropping
`class_version_id`), which would make a `Book` row genuinely shared across
every school. This was rejected for this sub-project — `books` keeps
`class_version_id` unchanged, so a `Book` row is still only reachable
through one school's own `class_versions` row. The only change here is
removing the now-redundant `school_id`/`district_id` columns. True
cross-school book sharing is explicitly deferred.

Target: these six models have no tenant columns, no `TenantScoped` trait,
and are fully visible/queryable by any authenticated actor regardless of
their own `school_id`/`district_id` (an unauthenticated request was already
unrestricted by `TenantScope`, so public-read behavior for later
sub-projects needs no change here). Write access (create/update/delete) is
restricted to `national_admin` at the permission-seeding layer; `*.view`
permissions are unchanged for every role that already has them.

`chapter_video_progress` (and the `ChapterVideoProgress` model, its
controller actions, request, resource, and the "completed videos" stat it
feeds in the student dashboard) is deleted outright — no replacement, no
soft-deprecation.

## Schema

One migration per table (matches this repo's additive-migration
convention), all dropping exactly `school_id` and `district_id`:

- `academic_years` — also change `unique(['school_id', 'name'])` to
  `unique(['name'])`. There is now exactly one "current" academic year
  nationally, not one per school.
- `books`, `chapters`, `chapter_materials`, `chapter_videos`, `questions` —
  column drop only; none of their existing unique indexes reference
  `school_id`/`district_id`, so no index changes are needed.

A separate migration drops the `chapter_video_progress` table entirely
(`Schema::dropIfExists`), reversing cleanly is not required to restore data
(dev/test data only — confirmed during brainstorming, no production rows to
preserve for any of these six tables or for `chapter_video_progress`).

## Models

- `AcademicYear`, `Book`, `Chapter`, `ChapterMaterial`, `ChapterVideo`,
  `Question`: drop `TenantScoped` from the `use` list; remove `school_id`/
  `district_id` from `$fillable`. All other relations/fillable/casts stay
  as-is.
- Delete `app/Models/ChapterVideoProgress.php`.

## Controllers / Requests / Resources

- `AcademicYearController`:
  - `index()` — drop the `?school_id=`/`?district_id=` filters (no column
    to filter on); drop `->with('school')`.
  - `store()` — stop resolving `School::findOrFail($request->integer('school_id'))`;
    stop setting `school_id`/`district_id` on `$attributes`; the
    soft-deleted-restore lookup changes from `where('school_id', ...)
    ->where('name', ...)` to `where('name', ...)` alone.
  - `update()` — unchanged shape, but `unsetOtherCurrentYears()` drops its
    `$schoolId` parameter and the `where('school_id', ...)` clause — one
    current year, globally.
  - `setCurrent()` — same change, calls the now-global
    `unsetOtherCurrentYears()`.
  - `StoreAcademicYearRequest`/`UpdateAcademicYearRequest` — remove the
    `school_id` validation rule (`required|exists:schools,id`) entirely.
  - `AcademicYearResource` — remove `school_id`/`district_id`/`school`/
    `district` keys.
- `BookController`, `ChapterController`, `ChapterMaterialController`,
  `ChapterVideoController`, `QuestionController` — none of these take
  `school_id`/`district_id` from the request today (confirmed: no Store/
  Update request for these five validates those fields), so the only
  change is removing any `->where('school_id', ...)` filter-building and
  the denormalization-from-parent lines that currently set `school_id`/
  `district_id` on the attributes array before create/update.
- `BookResource`, `ChapterResource` — remove `school_id`/`district_id` keys
  (`ChapterMaterialResource`/`ChapterVideoResource`/`QuestionResource`
  don't expose them today — no change needed there).
- `ChapterVideoController` — remove the video-progress endpoints (whatever
  routes currently handle saving/reading `ChapterVideoProgress`).
- Delete `UpdateChapterVideoProgressRequest` and
  `ChapterVideoProgressResource`.
- `routes/api.php` — remove the chapter-video-progress routes.
- `StudentDashboardBuilder` — remove the `ChapterVideoProgress`-backed
  "completed videos" stat (and any other field derived from it); adjust
  its tests accordingly.

## Permissions

In `database/seeders/RolePermissionSeeder.php`, remove the following from
every role except `national_admin` (which keeps `$allPermissions`
unchanged):

- `trainer` loses `books.create/update`, `chapters.create/update`,
  `chapter_videos.create/update`, `chapter_materials.create/update`.
- `teacher` loses `questions.create/update`.
- `school_admin` loses `academic_years.create/update/delete`.

`*.view` permissions for `academic_years`/`books`/`chapters`/
`chapter_videos`/`chapter_materials`/`questions` are untouched on every
role that currently has them. No changes to `PermissionSeeder` itself (the
permission names/rows are unchanged — only the role→permission mapping for
the write verbs moves). `BookPolicy`/`ChapterPolicy`/
`ChapterMaterialPolicy`/`ChapterVideoPolicy`/`QuestionPolicy`/
`AcademicYearPolicy` need no code changes — they already just check
permission names, and the seeder change is what removes those permissions
from the non-`national_admin` roles.

## Testing

- Update every existing feature test for these six resources that
  currently asserts tenant scoping (e.g. "School A admin cannot see School
  B's book") — that behavior is removed; replace with (where meaningful) an
  assertion that any authenticated actor with `*.view` can see all rows
  regardless of school.
- Add/update authorization tests confirming `trainer`/`teacher`/
  `school_admin` now get `403` on the create/update/delete routes they
  used to be allowed on for these six resources, and `national_admin`
  still succeeds.
- `AcademicYearControllerTest` — update store/update/setCurrent tests to
  stop passing `school_id`; add a test that marking one year current
  unsets any other year nationally (not scoped to a school).
- Delete `ChapterVideoProgressControllerTest` and
  `ChapterVideoProgressModelTest`.
- Update `StudentDashboardBuilderTest` to remove assertions about the
  deleted "completed videos" stat.
- Update `database/seeders/LmsContentDemoSeeder.php` to stop setting
  tenant columns on these six models' rows.
- Run the full backend suite at the end — this touches shared seeders used
  by many unrelated tests.

## Out of scope for this sub-project

- Re-parenting `books` to a national class (rejected alternative above) —
  deferred indefinitely, not part of any currently planned sub-project.
- `school_classes` restructuring (sub-project 2).
- Any change to `exams`, `exam_attempts`, `exam_questions`,
  `exam_attempt_questions`, `exam_attempt_answers`, `exam_random_chapters`,
  `question_options`, or `content_access_logs` — all keep their current
  tenant columns and behavior; public/guest exam-taking is sub-project 3.
- Any frontend change (management or student).

## Definition of done

- `academic_years`, `books`, `chapters`, `chapter_materials`,
  `chapter_videos`, `questions` have no `school_id`/`district_id` columns;
  none of their models use `TenantScoped`.
- `chapter_video_progress` table, model, controller actions, request, and
  resource are deleted; the student dashboard no longer reports a
  completed-videos stat.
- `national_admin` is the only role that can create/update/delete
  academic years, books, chapters, chapter materials, chapter videos, and
  questions; every role's `*.view` access is unchanged.
- `AcademicYearController` no longer accepts or requires `school_id`; one
  academic year can be current at a time, nationally.
- Full backend test suite passes against MySQL (not just SQLite — per
  known SQLite/MySQL migration-behavior gaps in this project).
