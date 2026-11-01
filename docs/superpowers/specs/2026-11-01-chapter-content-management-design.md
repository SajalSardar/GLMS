# Design: Chapter Content Management (Book/Chapter PDFs + YouTube Videos)

**Status:** Approved, ready for implementation planning
**Sub-project:** 2 of 6 (see "Program decomposition" in `docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`)

## Program decomposition recap

Sub-project 1 (done, merged to `master`) added `Book` between `Subject` and
`Chapter`, both tenant-scoped. This sub-project is the next one: PDF
management for `Book`/`Chapter` and YouTube tutorial video management for
`Chapter` — originally two separate sub-projects in the program
decomposition, merged into one at the user's explicit choice ("both are
'chapter content' admin screens").

## Scope of this spec

- A complete-book PDF, attached to `Book`.
- Chapter-wise PDFs (one primary PDF per chapter, plus unlimited
  supplementary PDFs), attached to `Chapter`.
- Private storage, signed temporary download URLs, and a basic access log
  for both.
- YouTube tutorial video management per `Chapter`: metadata, ordering,
  enable/disable, learning objectives.
- Video progress tracking (started/in-progress/completed) per student per
  video, using a configurable percentage-of-duration completion threshold.

**Frontend correction, same as sub-project 1 (recorded in
[[content_hierarchy_lms_program]]):** `frontend/apps/{management,teacher,
student}` have no real screens yet — only placeholder dashboards. This
sub-project is backend-API-only. Admin upload forms and the actual YouTube
embed/playback UI are built whenever the relevant frontend sub-project
(Management/Teacher/Student Portal, already decomposed in
`docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md`)
is executed, against the API this sub-project delivers.

## Decisions confirmed during brainstorming

- **Chapter PDFs use a single type-flagged child table** (`chapter_materials`,
  `type` enum `primary`/`supplementary`), not a dedicated "primary PDF"
  column on `Chapter` plus a separate supplementary-files table. One
  code path handles replace/archive for both.
- **Video completion is a percentage-of-duration threshold** (not a manual
  "mark as watched" self-report), derived from the student's reported
  playback position via the YouTube IFrame API, polled client-side and
  reported to the backend.
- **The brief's "Content Manager" role maps onto the existing `trainer`
  role** — no new role is seeded. `trainer` already holds
  `chapters.create`/`chapters.update`; it gains the equivalent new
  permissions below.
- **Book's complete PDF is flat columns on `books`, not a child table** —
  unlike `Chapter`, a `Book` has at most one complete PDF (no
  "supplementary" concept for books), so the multiplicity problem that
  justified `chapter_materials`'s child-table shape doesn't apply. Adding
  a table for a strict 1:1 relationship would be unjustified complexity
  (YAGNI).

## Schema

### `books` table additions (new additive migration)

```
pdf_original_filename   nullable string
pdf_stored_path         nullable string
pdf_disk                nullable string (filesystem disk name, e.g. "local")
pdf_mime_type           nullable string
pdf_size_bytes          nullable unsigned big integer
pdf_uploaded_by_id      nullable FK -> users, nullOnDelete
pdf_uploaded_at         nullable timestamp
pdf_status              nullable enum('active', 'archived')
```

All null until the first upload. "Replace" overwrites these columns in
place and deletes the old physical file from storage. "Archive" sets
`pdf_status = 'archived'` without touching the file or metadata — an
archived book PDF is excluded from the public-facing "available PDF"
signal (consumed by a later sub-project's dashboard) but the file and its
metadata are retained, matching "Archive" as a reversible state rather
than deletion. "Delete" clears all seven columns to null and removes the
physical file.

### New `chapter_materials` table

```
id
chapter_id              FK -> chapters, cascade delete
school_id               denormalized from the chapter at creation time
district_id             denormalized from the chapter at creation time
type                    enum('primary', 'supplementary')
title                   nullable string (label for supplementary files; null for primary)
original_filename       string
stored_path             string
disk                    string
mime_type               string
size_bytes              unsigned big integer
uploaded_by_id           FK -> users, nullOnDelete
order_index              nullable unsigned smallint (ordering among supplementary files; unused for primary)
status                   enum('active', 'archived'), default 'active'
timestamps
soft deletes
```

**Correction made during spec self-review:** an earlier draft of this
section omitted `school_id`/`district_id`, reasoning that tenant
isolation is "inherited through `chapter_id`." That reasoning only holds
if every access path goes through `Chapter`'s own scoped relation — but
`ChapterMaterialController`'s `show`/`update`/`destroy`/`download-link`
actions resolve a `ChapterMaterial` directly by ID via implicit
route-model-binding (`/api/v1/chapter-materials/{chapterMaterial}`), the
same way `BookController`/`ChapterController` resolve their own models
directly. Without its own `TenantScoped` trait, a bare
`ChapterMaterial::find($id)` for a foreign-tenant's material would
succeed — the automatic 404-by-scope every other directly-route-bound
resource in this codebase relies on (confirmed in the auth spec's
global-scope design) would simply not apply. So `chapter_materials` DOES
denormalize `school_id`/`district_id` from its parent `Chapter` at
creation time and DOES get `TenantScoped` — same convention as `Book`
and `Chapter`, not the `Guardian`/`StudentSectionTransfer` exception
(those two are excluded because nothing resolves them by bare ID through
a public route in the first place).

**Enforcing "exactly one non-archived primary row per chapter":** a
partial invariant, not a DB constraint (MySQL has no native partial
unique index) — enforced in `StoreChapterMaterialRequest`: a `type =
primary` upload is rejected with a validation error if a non-archived
primary row already exists for that chapter, with the error message
pointing the caller at the "replace" flow (`PATCH` the existing material)
instead.

### New `chapter_videos` table

```
id
chapter_id                     FK -> chapters, cascade delete
school_id                       denormalized from the chapter at creation time
district_id                      denormalized from the chapter at creation time
youtube_video_id                string (11-char YouTube ID, validated)
title                           string
bn_title                        nullable string
description                     nullable text
instructor_name                  nullable string
duration_seconds                 nullable unsigned integer
learning_objectives               nullable text
completion_threshold_percent      nullable unsigned tinyint (1-100; null = use config default)
order_index                      unsigned smallint
is_enabled                       boolean, default true
timestamps
soft deletes
```

Same correction and reasoning as `chapter_materials` above:
`ChapterVideoController`'s `show`/`update`/`destroy`/`enable`/`disable`
actions resolve a `ChapterVideo` directly by ID, so it denormalizes
`school_id`/`district_id` from its parent `Chapter` and gets
`TenantScoped` too.

### New `chapter_video_progress` table

```
id
chapter_video_id         FK -> chapter_videos, cascade delete
user_id                  FK -> users, cascade delete
status                   enum('not_started', 'in_progress', 'completed'), default 'not_started'
max_position_seconds     unsigned integer, default 0
started_at                nullable timestamp
completed_at               nullable timestamp
timestamps

unique(chapter_video_id, user_id)
```

`max_position_seconds` is a high-water mark (the furthest playback
position ever reported), not a sum of watched segments — resistant to a
student scrubbing back and forth to inflate "watched time," and avoids
ever claiming more precision than "got at least this far," which matches
the brief's own warning not to claim completion proves every second was
watched.

### New `content_access_logs` table

```
id
book_id                   nullable FK -> books, cascade delete
chapter_id                nullable FK -> chapters, cascade delete
chapter_material_id        nullable FK -> chapter_materials, cascade delete
user_id                    nullable FK -> users, nullOnDelete (null = unauthenticated access, if ever allowed)
action                     enum('downloaded', 'previewed')
created_at                 timestamp only (immutable log; no updated_at)
```

Exactly one of `book_id`/`chapter_material_id` is set per row (whichever
was accessed); `chapter_id` is set alongside `chapter_material_id` for
convenient chapter-scoped queries without a join. No polymorphic
relation — matches this codebase's existing all-explicit-FK convention
(confirmed in the auth spec: no polymorphic relations exist anywhere in
this codebase today).

## Storage and signed downloads

Both the book PDF and chapter materials are stored on the `local`
filesystem disk (already the project default — `storage/app/private`,
confirmed private by Laravel's own default `local` disk config). Laravel's
`Storage::temporaryUrl()` only works on cloud-style disks (S3-compatible);
for the `local` disk, "temporary authorized download URL" is implemented
via `URL::temporarySignedRoute()` pointing at a dedicated download
controller action:

```
GET /api/v1/books/{book}/pdf/download   (signed, short-lived)
GET /api/v1/chapter-materials/{chapterMaterial}/download   (signed, short-lived)
```

A caller first hits an authenticated, permission-checked endpoint (e.g.
`POST /api/v1/books/{book}/pdf/download-link`) that returns a signed URL
with a short expiry (default 5 minutes, configurable); the signed URL
itself requires no bearer token, so it can be handed to a `<a href>`/PDF
viewer directly. The download controller action validates the request
signature (via the `signed` middleware), writes one `content_access_logs`
row (`action = 'downloaded'`), and streams the file via
`Storage::disk($disk)->download($path, $originalFilename)`. A `preview`
flavor (`action = 'previewed'`) returns the file inline
(`Storage::disk($disk)->response()`) instead of forcing a download,
behind the same signed-URL flow.

## YouTube integration

A new support class, `App\Support\YoutubeUrl` (same role as the existing
`App\Support\Ordinal`):

- `extractVideoId(string $url): ?string` — recognizes `youtube.com/watch?v=`,
  `youtu.be/`, `youtube.com/embed/`, and `youtube.com/shorts/` URL forms;
  validates the extracted ID is exactly 11 characters from
  `[A-Za-z0-9_-]`; returns `null` for anything else (surfaced as a
  validation error by the request class, not an exception).
- `embedUrl(string $videoId): string` — returns
  `https://www.youtube.com/embed/{id}` with `rel=0` (no "recommended
  videos" from other channels at the end).

No calls to YouTube's Data API anywhere in this sub-project — `duration_seconds`
is either admin-entered at creation, or backfilled the first time a
`chapter_video_progress` report arrives with a `duration_seconds` value the
stored row doesn't have yet. Handling of unavailable/private/deleted videos
is a frontend concern (the IFrame Player API's `onError` event) with
nothing to build server-side beyond accepting whatever ID was supplied —
this sub-project cannot verify a video is reachable without calling
YouTube's own API, which is explicitly out of scope.

## API / permissions

Mirrors sub-project 1's established conventions (`BookController`/
`ChapterController` patterns): Policy + FormRequest + Resource +
Controller per new resource, soft-delete-restore-on-recreate where
applicable, `$this->authorize(...)` as the literal first statement of
every mutating controller action (enforced by the existing
`ControllerAuthorizationConventionTest`, extended with every new
controller's mutating actions).

- **Book PDF**: no new resource/permissions — these are just fields on
  `Book`. `POST /api/v1/books/{book}/pdf` (upload/replace, behind
  `books.update`), `DELETE /api/v1/books/{book}/pdf` (behind
  `books.delete`), `PATCH /api/v1/books/{book}/pdf/archive` and
  `/unarchive` (behind `books.update`), `POST
  /api/v1/books/{book}/pdf/download-link` (behind `books.view`).
- **`ChapterMaterialController`**: full CRUD (`chapter_materials.view/
  create/update/delete`), nested under `chapter_id` (index filters by
  `?chapter_id=`), plus `/download-link` per material (behind
  `chapter_materials.view`).
- **`ChapterVideoController`**: full CRUD (`chapter_videos.view/create/
  update/delete`), index filters by `?chapter_id=`, plus `PATCH
  /api/v1/chapter-videos/{chapterVideo}/reorder` accepting an ordered ID
  list (same "drag-and-drop reorder" shape the brief asks for — the
  endpoint accepts the new order, drag-and-drop itself is a frontend
  concern) and `PATCH .../enable` / `.../disable`.
- **`ChapterVideoProgressController`**: `PUT
  /api/v1/chapter-videos/{chapterVideo}/progress` — any authenticated
  user reports their OWN progress (`{ position_seconds, duration_seconds?
  }`); no new permission, the controller scopes strictly to
  `$request->user()->id` and never accepts a different user's ID. Updates
  `max_position_seconds` only if the new position exceeds the stored
  one, flips `status` to `in_progress`/`completed` by comparing the
  resulting percentage against `completion_threshold_percent` (or the
  config default), and sets `started_at`/`completed_at` the first time
  each threshold is crossed (never un-sets them once set).
- New permissions seeded in `PermissionSeeder`: `chapter_materials.view/
  create/update/delete`, `chapter_videos.view/create/update/delete`.
  Mapped in `RolePermissionSeeder` the same way `chapters.create/update`
  are mapped today (`trainer` gets create/update, every other role with
  `chapters.view` gets the `.view` equivalents, `national_admin` needs no
  edit).

## Testing

- Feature tests per controller: CRUD, policy denial, tenant-scoping
  (via the parent `Chapter`/`Book` — a cross-tenant material/video ID
  404s), soft-delete restore where applicable.
- A focused unit test for `YoutubeUrl::extractVideoId()` covering all four
  URL forms plus invalid input.
- A signed-URL test: a valid signed download link succeeds; an expired or
  tampered one is rejected (`403`).
- A `content_access_logs` test: downloading/previewing writes exactly one
  row with the right `action`.
- A video-progress test: position reports below/at/above the completion
  threshold produce the right `status` transitions, and a lower
  subsequent report never decreases `max_position_seconds`.
- Per the lesson recorded from sub-project 1's final review
  ([[content_hierarchy_lms_program]]): if any seeder is touched or added,
  extend `DatabaseSeederTest` rather than leaving it uncovered.

## Out of scope for this sub-project

- Any frontend work (admin upload forms, PDF preview UI, YouTube embedded
  player, drag-and-drop reorder UI) — see "Frontend correction" above.
- Calling YouTube's Data API for metadata/availability verification.
- File versioning/replace history (only current-state metadata is kept).
- Question bank, exams, student exam-taking, and the learning dashboard —
  sub-projects 3-6.
- Any change to `academic_year_id` scoping (content stays year-independent,
  per sub-project 1's decision).

## Implementation plan structure

Split into two sequential plans (decided during brainstorming, given the
combined scope):

- **Plan A**: `books` PDF columns, `chapter_materials` table/model/API,
  signed download/preview routes, `content_access_logs`.
- **Plan B**: `chapter_videos` table/model/API, `YoutubeUrl` support
  class, `chapter_video_progress` table/model/API.

## Definition of done

- `books` table has the seven new nullable PDF columns; upload/replace/
  archive/unarchive/delete/download-link endpoints exist and are
  permission-checked.
- `chapter_materials` table/model/API exist, enforcing exactly one
  non-archived `primary` row per chapter at the validation layer;
  download/preview endpoints exist.
- `chapter_videos` table/model/API exist with ordering, enable/disable,
  and the `YoutubeUrl` support class correctly extracts/validates IDs
  from all four documented URL forms.
- `chapter_video_progress` table/API exist; completion status derives
  from a configurable percentage-of-duration threshold; `max_position_seconds`
  never decreases.
- `content_access_logs` records exactly one row per download/preview.
- New permissions seeded and mapped to the same roles `chapters.*` uses
  today (`trainer` as the Content Manager stand-in).
- All new controllers' mutating actions are covered by
  `ControllerAuthorizationConventionTest`.
- Full backend test suite passes, including a seeder-chain smoke test if
  any seeder was touched.
