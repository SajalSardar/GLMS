# Design: Question Bank (Types, Bulk Import/Export, Permissions)

**Status:** Approved, ready for implementation planning
**Sub-project:** 3 of 6 (see `docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`
"Program decomposition" for the full six-part breakdown; this sub-project
depends on sub-project 1, which gave `Chapter` its tenant-scoped
`book_id`/`school_id`/`district_id` shape)

## Scope of this sub-project

Add a `Question`/`QuestionOption` model pair hanging off `Chapter`, with
CRUD, reordering, CSV bulk import/export, and permissions. No exam
configuration, no student attempt-taking, no auto/manual grading, no
AI-assisted question drafting — those are sub-projects 4 and 5 (exam
management, student exam-taking + grading). AI drafting is a
**🆕 NEW RECOMMENDATION** in `inspiration/AI_Teacher_Training_Proposal_v2.md`
§4.4, not part of the original scope, and is explicitly excluded from
Phase 1's foundation per `docs/PROJECT_ANALYSIS.md` §6 ("no AI
integration") — this sub-project only builds manually-authored content,
matching how sub-projects 1-2 built Book/Chapter/video/material content
with no AI involvement.

## Question types (this sub-project)

- `mcq_single` — exactly one correct option among N.
- `mcq_multiple` — one or more correct options among N.
- `true_false` — exactly two options ("True"/"False"), exactly one
  correct. Modeled as a `QuestionOption` pair, not a separate schema —
  it's a constrained case of `mcq_single`.

Short-answer/descriptive and other open-ended types are deferred: they
need manual grading (sub-project 5), not bulk import/export or simple
CRUD, and are out of scope here.

## Schema

### New `questions` table

```
id
chapter_id         FK -> chapters, cascade delete
school_id          denormalized from chapter at creation (never taken from the request)
district_id        denormalized from chapter at creation
question_type      enum: mcq_single, mcq_multiple, true_false
question_text      text
bn_question_text    nullable text
image_path          nullable string
image_disk          nullable string
marks               unsigned smallint, default 1
difficulty          enum: easy, medium, hard, default medium
order_index          unsigned smallint, default 0
is_active            boolean, default true
timestamps
soft deletes
```

`Question` model: `TenantScoped` (same trait as `Book`/`Chapter`/
`ChapterVideo`/`ChapterMaterial`); `belongsTo(Chapter)`,
`hasMany(QuestionOption)` ordered by `order_index`.

`Chapter` model gains a `questions(): HasMany` relation
(`hasMany(Question::class)->orderBy('order_index')`), alongside its
existing `materials()`/`videos()`.

**Why `district_id` is kept** (flagged and confirmed during design):
`App\Models\Scopes\TenantScope::apply()` queries `district_id` directly
for any district-scoped actor (`$user->school_id === null && $user->district_id !== null`,
e.g. `district_officer`). Omitting the column would throw a missing-column
SQL error the first time such a user's `questions.view` permission is
exercised. Every other tenant-scoped table in this codebase denormalizes
both columns for this reason; `questions` follows the same rule.

### New `question_options` table

```
id
question_id       FK -> questions, cascade delete
option_text
bn_option_text    nullable
is_correct        boolean, default false
order_index        unsigned smallint, default 0
timestamps
```

`QuestionOption` model: **not** `TenantScoped` and **no** denormalized
`school_id`/`district_id` — it mirrors `ChapterVideoProgress`, the one
existing child table in this codebase that is always reached through its
tenant-scoped parent (`question->options()`) and never queried standalone,
so it needs no independent scope. `belongsTo(Question)`.

### Images

Question/option images use a **public** disk
(`config('content.question_image_disk')`, default `public`), unlike
`Book`'s private signed-URL PDFs — an exam-taking student needs the image
to stay visible for the whole attempt, which can outlast the 5-minute
signed-link expiry `Book` uses, and a diagram illustrating a question
isn't confidential the way a licensed textbook PDF is. New
`config/content.php` keys:

```
'question_image_disk' => env('CONTENT_QUESTION_IMAGE_DISK', 'public'),
'max_image_size_kb'    => (int) env('MAX_IMAGE_SIZE_KB', 2048),
```

Upload stores at `questions/{question_id}/{uuid}.{ext}`, same
`Str::uuid()` naming convention as `BookController::uploadPdf()`.

## Permissions

New permissions in `PermissionSeeder`: `questions.view`, `questions.create`,
`questions.update`, `questions.delete`.

`RolePermissionSeeder` mapping:

- `national_admin` — all (via `$allPermissions`, unchanged mechanism).
- `trainer` and `teacher` — `questions.view`, `questions.create`,
  `questions.update` (teachers author their own assessments directly,
  unlike `chapters.*`/`books.*` where `teacher` is view-only — confirmed
  during design as a deliberate departure from that pattern).
- Every other existing role that currently holds `chapters.view` (
  `district_officer`, `school_admin`, `student`, `office_staff`,
  `parent_guardian`, `it_admin`, `auditor`) additionally gets
  `questions.view`, alongside the existing `chapters.view` entries on the
  same line.
- `questions.delete` is granted to **no** role except `national_admin` —
  matches the existing `chapters.delete`/`books.delete` pattern, where
  `trainer` gets create/update but not delete.

## API

Routes follow the existing `chapter-videos` split in `routes/api.php`
exactly (public `index`/`show` outside `auth:sanctum`, mutating verbs
inside it):

```
GET    /questions                      (public, ?chapter_id= filter)
GET    /questions/{question}           (public)
POST   /questions                      (auth, questions.create)
PUT    /questions/{question}           (auth, questions.update)
DELETE /questions/{question}           (auth, questions.delete)
PATCH  /questions/reorder              (auth, QuestionPolicy::reorder — no-instance ability)
POST   /questions/import               (auth, questions.create)
GET    /questions/export               (auth, questions.view, ?chapter_id= filter)
```

- `QuestionController` — `store`/`update` accept a nested `options` array
  (`[{option_text, bn_option_text, is_correct, order_index}]`) and replace
  the full option set transactionally (delete-and-recreate inside one DB
  transaction — correct and simple for option lists this small; no
  diffing needed).
- `QuestionPolicy` — single-parameter `create(User $user): bool` (same
  collapsed-policy shape as `BookPolicy`); a dedicated no-instance
  `reorder(User $user): bool` ability (checks `questions.update`), never
  `can('update', Question::class)` against the instance-typed `update()`
  method — this exact `ArgumentCountError` trip-wire has recurred three
  times in sub-projects 1-2 and must be avoided here the same way
  `ChapterVideoPolicy::reorder()` avoids it.
- `StoreQuestionRequest`/`UpdateQuestionRequest` — `chapter_id` exists
  (`chapters`, `withoutTrashed()`); `question_type` in enum; `options`
  array validated by type (`mcq_single`/`true_false`: exactly one
  `is_correct: true`; `true_false`: exactly two options; `mcq_multiple`:
  at least two options, at least one `is_correct: true`); `image` optional
  uploaded file (`jpg`/`jpeg`/`png`, size-limited by
  `content.max_image_size_kb`).
- `QuestionResource`/`QuestionOptionResource` — standard field exposure,
  plus `image_url` (a plain public URL, not a signed link, consistent
  with the public-disk decision above). **`is_correct` is stripped from
  `QuestionOptionResource` for any viewer without `questions.update`** —
  this matters once sub-project 5 lets students fetch exam questions
  through the same resource class; a student must never see which option
  is flagged correct.
- `ReorderQuestionsRequest` — `chapter_id` plus an ordered array of
  `question_id`s belonging to that chapter; mirrors
  `ReorderChapterVideosRequest`'s validation shape.

### Bulk import (`QuestionController::import`)

CSV upload, synchronous (no Horizon/queue — the project has no queued
Job classes anywhere yet, `QUEUE_CONNECTION=database` sits unused; adding
the project's first background job is out of scope for this sub-project).
Columns:

```
chapter_id, question_type, question_text, bn_question_text, marks,
difficulty, option_1, option_2, option_3, option_4, option_5, option_6,
correct_options
```

`correct_options` is a `;`-separated list of 1-based option-column
indexes that are correct (e.g. `"1"` for `mcq_single`/`true_false`,
`"1;3"` for `mcq_multiple`). Blank `option_N` cells are skipped.

Each row is validated and persisted **independently inside its own DB
transaction** — one bad row rolls back only itself, not the whole file,
per the proposal's explicit "row-by-row error report, not whole-file
reject" recommendation (CSV imports from messy paper registers are
expected to have occasional bad rows). The response is a per-row report:

```json
{
  "imported": 18,
  "failed": 2,
  "rows": [
    {"row": 2, "status": "imported", "question_id": 103},
    {"row": 5, "status": "error", "message": "chapter_id does not exist"}
  ]
}
```

### Export (`QuestionController::export`)

Streamed CSV of a chapter's questions in the exact same column shape as
import (round-trippable: export a chapter, edit the file, re-import it).
Requires `questions.view` — same permission as reading questions
on-screen, per the "every export goes through the same permission-scoped
path as on-screen data" principle already followed by `Book`'s signed
downloads.

## Testing

- `QuestionControllerTest` (new) — CRUD for all three question types,
  option-count/correctness validation per type (reject a `true_false`
  question with 3 options, reject an `mcq_single`/`true_false` with 0 or
  2+ correct options, reject an `mcq_multiple` with 0 correct options),
  tenant-scoping (School A admin/teacher cannot see/edit School B's
  questions; `national_admin` sees both), policy denial for a user
  without `questions.*`, `reorder` endpoint (including the no-instance
  policy ability — matching the regression class already caught twice in
  sub-projects 1-2), `is_correct` stripped from the response for a viewer
  without `questions.update`.
- `QuestionImportExportTest` (new) — valid multi-row CSV imports
  correctly; a CSV with one bad row (e.g. a non-existent `chapter_id`)
  still imports the good rows and reports the bad one; export produces a
  CSV that re-imports cleanly (round-trip); import respects tenant
  scoping (cannot import against another tenant's `chapter_id`).
- Denormalization check — `Question`'s `school_id`/`district_id` always
  match its parent `Chapter`'s, same pattern as the existing `Book`/
  `Chapter` factory-level assertion from sub-project 1.
- `DatabaseSeederTest` — assert a `questions.view` permission seed exists,
  following the Carry-into-every-future-sub-project rule from the
  sub-project-2 seeder-chain-breakage incident (nothing in this
  sub-project touches seeders destructively the way `ChapterSeeder` did,
  but the assertion itself costs nothing and keeps the net wide).

## Out of scope for this sub-project

- Exam configuration, question selection (manual/bank/auto-random),
  attempt limits, time limits, passing scores — sub-project 4.
- Student-facing exam-taking, auto/manual grading, results — sub-project 5.
- AI-assisted question drafting/review-queue workflow — a separate future
  piece per `AI_Teacher_Training_Proposal_v2.md` §4.4's 🆕 recommendation,
  not part of the original scope and excluded from this phase of work.
- Short-answer/descriptive/matching/scenario-based/rubric-based question
  types — need manual grading machinery that doesn't exist until
  sub-project 5.
- Background/queued import processing — no Job infrastructure exists yet
  in this codebase; synchronous import is sufficient at this scale.

## Definition of done

- `questions`/`question_options` tables and models exist as specified
  above; `Question` is `TenantScoped`; `Chapter::questions()` relation
  added.
- `QuestionController`/`QuestionPolicy`/`Store`/`UpdateQuestionRequest`/
  `ReorderQuestionsRequest`/`QuestionResource`/`QuestionOptionResource`
  exist, following the exact conventions of `ChapterVideo`'s equivalents;
  routes registered in `routes/api.php`.
- `questions.*` permissions seeded and mapped per the "Permissions"
  section above.
- Bulk CSV import (`POST /questions/import`) and export
  (`GET /questions/export`) work as specified, row-by-row error reporting
  on import.
- `is_correct` never appears in a `QuestionOptionResource` response for a
  viewer without `questions.update`.
- All tests in "Testing" above pass.
