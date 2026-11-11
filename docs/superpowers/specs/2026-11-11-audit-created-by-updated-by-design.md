# Design: `created_by` / `updated_by` Audit Columns

**Status:** Approved, ready for implementation planning

**Source:** Direct request — "backend all api DB add created_by and updated_by
field." Scoped down during brainstorming to the resources that already have
an authenticated actor at creation/update time.

## Scope of this project

Add `created_by` and `updated_by` columns (nullable FK to `users.id`) to the
25 tables that are created/updated through a dedicated `Store*Request` /
`Update*Request` pair, and populate them automatically from the
authenticated actor. No new endpoints, no new permissions, no change to any
existing validation rule or business logic.

**Tables in scope** (model → table), one per existing `Store*Request`:

| Model | Table |
|---|---|
| `Division` | `divisions` |
| `District` | `districts` |
| `Upazila` | `upazilas` |
| `School` | `schools` |
| `Subject` | `subjects` |
| `SchoolClass` | `school_classes` |
| `AcademicYear` | `academic_years` |
| `Classroom` | `classrooms` |
| `ClassRoutine` | `class_routines` |
| `ClassVersion` | `class_versions` |
| `Shift` | `shifts` |
| `Section` | `sections` |
| `SubjectTeacherAssignment` | `subject_teacher_assignments` |
| `TimetableEntry` | `timetable_entries` |
| `Book` | `books` |
| `Chapter` | `chapters` |
| `ChapterMaterial` | `chapter_materials` |
| `ChapterVideo` | `chapter_videos` |
| `Question` | `questions` |
| `Exam` | `exams` |
| `Role` | `roles` |
| `User` | `users` |
| `TeacherProfile` | `teacher_profiles` |
| `StudentProfile` | `student_profiles` |
| `Guardian` | `guardians` |

**Explicitly out of scope:**

- Framework tables (`cache`, `jobs`, `sessions`, `personal_access_tokens`).
- Pure pivot/junction tables with no standalone `Store*Request`
  (`class_subjects`, `version_subjects`, `role_permissions`,
  `student_guardian`, `exam_questions`, `exam_random_chapters`,
  `question_options`).
- System-generated rows with no human actor at creation time
  (`exam_attempts`, `exam_attempt_questions`, `exam_attempt_answers`,
  `chapter_video_progress`, `content_access_logs`,
  `student_section_transfers`).
- `Permission` — seeded only, no `Store*Request` exists for it.
- `deleted_by` — not requested; only `created_by`/`updated_by` are in scope.
- Any change to existing authorization rules (who *can* create/update a row
  is unchanged; this only records who *did*).

## Decisions confirmed during brainstorming

- **Nullable FK to `users.id`, `ON DELETE SET NULL`.** Both columns are
  `unsignedBigInteger` with a foreign key constraint, nullable because
  existing rows (and anything created outside an authenticated request —
  seeders, bare `Model::factory()->create()` in tests) have no actor to
  record. `users` itself gets a self-referential FK; both the production
  MySQL connection and the sqlite in-memory test connection
  (`foreign_key_constraints => true` in `config/database.php`) enforce it.
- **One combined migration, not 25 per-table files.** A single
  `add_created_by_and_updated_by_to_audited_tables` migration loops over the
  table list above, adding both columns + FKs to each in its `up()`, and
  drops them the same way in `down()`. The existing
  `add_deleted_at_to_X_table.php` one-file-per-table migrations predate this
  decision and aren't being retrofitted to match.
- **Population via a new `Auditable` trait, not controller code.** Mirrors
  the existing `App\Models\Concerns\TenantScoped` trait (a `boot{Trait}()`
  hook registered automatically by Eloquent). `Auditable::bootAuditable()`
  registers:
  - `static::creating()` → sets `created_by = Auth::id()` if not already
    set (defends against an explicit value sneaking in, though nothing sets
    one today).
  - `static::updating()` → sets `updated_by = Auth::id()` unconditionally,
    every save.

  `Auth::id()` returns `null` outside an authenticated request (seeders,
  bare factory creation in tests), which is fine — the columns are nullable.
  Added to the 25 models above; zero controller changes. A soft-deleted row
  that gets restored-then-updated (e.g. `SubjectController::store()`'s
  restore-on-conflict path) correctly gets a fresh `updated_by`, since
  `update()` fires the `updating` event same as any other save.
- **Never client-settable.** `created_by`/`updated_by` are *not* added to
  any `Store*Request`/`Update*Request` validation rules or `$fillable` —
  the trait sets them directly on the model, so mass-assignment from
  request input is never in play.
- **Exposed on every corresponding API Resource, as plain IDs.** Each of
  the 25 `*Resource` classes gets `'created_by' => $this->created_by` and
  `'updated_by' => $this->updated_by` added to `toArray()`, consistent with
  how other foreign keys (e.g. `school_id`) are already exposed as bare IDs
  with no eager-loaded relation.

## Data flow

1. Request hits a `store()`/`update()` action behind `auth:sanctum`, same as
   today.
2. `FormRequest::authorize()` / the controller's `$this->authorize(...)`
   call runs unchanged — no new authorization logic.
3. `Model::create($data)` or `$model->update($data)` runs; Eloquent fires
   `creating`/`updating`; `Auditable` sets the column(s) from `Auth::id()`
   before the row is written.
4. The `*Resource` response includes the two new ID fields alongside the
   rest of the payload, unchanged shape otherwise.

## Testing

- Existing feature/unit tests should be unaffected: the columns are
  nullable, the FK is `nullOnDelete`, and no existing test asserts an exact
  (closed) JSON key set for any of the 25 resources that would break by
  gaining two extra keys — this gets confirmed per-controller while
  implementing, and any test that does assert an exact shape gets updated
  in the same commit as the resource it covers.
- One new unit test for the `Auditable` trait itself (e.g. against
  `Division`, the simplest model that uses it): creating while
  `actingAs($user)` sets `created_by` to that user's ID; updating sets
  `updated_by`; creating/updating with no authenticated actor leaves the
  column `null`.
- For each of the 25 resources, extend (or add, where none exists) that
  resource's existing store/update feature test with an assertion that the
  response's `created_by`/`updated_by` matches the acting user — reusing
  the existing `actingAs`/`actorWithPermissions` helpers already used
  throughout `tests/Feature`, not a new test pattern.
- Full `php artisan test` run at the end, same bar as every prior
  sub-project this session.

## Rollout / backfill

Existing rows get `NULL` in both columns on migrate — there is no
historical actor data to recover, and `NULL` is the honest answer ("unknown
actor"), not a placeholder that implies false precision.
