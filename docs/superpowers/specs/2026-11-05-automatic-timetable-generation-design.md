# Design: Automatic Timetable Generation

**Status:** Approved, ready for implementation planning

**Source:** `docs/Multi-Version-Multi-Shift-Multi-Section-Smart Timetable
Management.md`, Phase 5.2 ("Automatic Routine Generation"). Phases 1-4 and
the rest of Phase 5 (5.1 configuration, 5.3 manual editor's backend CRUD,
5.4 draft/publish workflow) are already built — `SchoolClass`,
`ClassVersion`, `Shift`, `Section`, `Classroom`, `SubjectTeacherAssignment`,
and `TimetableEntryController`'s full CRUD + publish/unpublish confirmed
present before this spec was written. This is the one missing piece: an
action that fills a shift's sections with conflict-free periods
automatically instead of requiring every period to be entered by hand.

## Scope of this project

A new `POST /shifts/{shift}/timetable-entries/generate` action that, for
every section under one shift, places each assigned subject's full
`required_weekly_periods` into conflict-free day/period slots — reusing
the hard-constraint checks `TimetableEntryController` already enforces for
manual entries, run by a greedy-with-local-search algorithm, no new
dependency, no background job (none of that infrastructure exists in this
codebase, confirmed repeatedly across prior sub-projects this session).

**Explicitly narrower than the inspiration document's full Phase 5.3/5.4
scope.** This project does not include a frontend drag-and-drop editor
(5.3 — a frontend concern, no frontend work has been in scope for any
sub-project this session) or print/export to PDF/Excel (5.3). It also does
not add anything to the draft→publish workflow beyond what already exists
(5.4) — generated entries land as `draft`, exactly like a manually created
entry; publishing them is the existing `publish` action, unchanged. No
notification-on-change system is added (none exists in this codebase for
any other feature either).

## Decisions confirmed during brainstorming

- **Unit of generation: one Shift, every section under it, in a single
  call.** Teachers and classrooms are shared across sections in the same
  shift, so conflict-free generation only makes sense evaluated jointly
  across everything that can collide. Sections outside the chosen shift
  are untouched.
- **All-or-nothing per section, never a silently incomplete schedule.**
  Each section's attempt runs in its own DB transaction. If every
  required period for every assigned subject is placed, the transaction
  commits. If any requirement can't be fully placed without violating a
  hard constraint, the whole section's transaction rolls back — no
  entries are created or deleted for that section — and the shortfall is
  reported. Other sections in the same call succeed or fail
  independently. This is the document's own instruction: "Do not claim
  that a timetable is valid unless all hard constraints have been
  checked" — a half-filled draft would be exactly that false claim.
- **Published entries are never touched; draft entries are replaced.**
  Hard constraint #10 (no silent overwrite of approved entries) means a
  `published` `TimetableEntry` is always treated as a fixed, already-
  occupied slot the generator must route around, for every section in the
  shift — never a candidate for replacement. A section's own pre-existing
  `draft` entries ARE replaced: generating is a fresh attempt, and a
  draft (by definition) was never approved. If the section's attempt then
  fails, the transaction rollback restores exactly those pre-existing
  drafts (see "Draft replacement" below) — failure never leaves a section
  worse off than before the call.
- **Algorithm: greedy slot-filling + bounded local search, no new
  dependency.** No constraint-solver library or queue/job infrastructure
  exists in this codebase (`composer.json` has neither). A hand-rolled
  most-constrained-subject-first greedy placement, backed by the exact
  same conflict/validity checks `TimetableEntryController` already runs
  for a manual entry, finds a conflict-free schedule whenever one exists,
  synchronously, in one request. A short bounded local-search pass
  afterward improves the soft constraints (spread, consecutive-period
  avoidance) without needing a true metaheuristic.
- **One shared validator, not two copies.** `TimetableEntryController`'s
  `assertValid()`/`assertNoConflicts()` already implement every hard
  constraint needed (teacher double-book, classroom double-book,
  shift-hours/break overlap, weekly-period cap, room capacity, valid
  teacher-subject assignment). Both the controller and the new generator
  must call the *same* extracted service — this program's own
  "cross-sub-project derived-value drift" lesson (sub-project 5's
  `marks_per_question` mismatch, sub-project 6's `passed` tri-state
  mismatch) is exactly the failure mode of letting two call sites
  re-derive the same rule and drift apart.
- **Classroom is always the section's `default_classroom_id`.** The
  generator does not search across classrooms — it mirrors `store()`'s
  own default (`$section->default_classroom_id` when no override is
  given) and never overrides it. A section with no default classroom
  generates entries with `classroom_id = null` (allowed; the room-booking
  conflict check already skips null classrooms, same as today).
- **A subject with `required_weekly_periods = null` is skipped.**
  `assertValid()` already treats `null` as "no cap enforced" for manual
  entries; the generator has no target count to fill for such a subject,
  so it places nothing for it and does not report it as a shortfall.

## Period grid derivation (new)

A manual `TimetableEntry` today has `period_no`/`start_time`/`end_time`
supplied directly by the caller — there is no automatic mapping from a
Shift's configuration to a period grid. The generator needs one, and it
is built once per Shift, shared by every section under it (all sections
of a shift run the same daily period grid):

1. For each day in `shift->days_of_week`, start with the single interval
   `[shift->start_time, shift->end_time)`.
2. Subtract every interval in `shift->breaks` from it (a break is
   `{start_time, end_time}`, same shape the shift's own validation
   already consumes), leaving zero or more free sub-intervals in time
   order.
3. Chop each free sub-interval into `shift->period_duration_minutes`-sized
   chunks, in time order, numbering them `period_no` 1, 2, 3... across
   the whole day (numbering continues across sub-interval boundaries — a
   chunk immediately after a break gets the next sequential number, not a
   new sequence). A trailing remainder shorter than one full period is
   discarded.
4. Each chunk becomes a candidate slot: `(day_of_week, period_no,
   start_time, end_time)`.

This reuses break semantics consistently: a generated period's
`[start_time, end_time)` can never overlap a break, by construction,
which is exactly what `assertWithinShiftHours()` already checks for a
manual entry — the generator can never produce something the existing
validator would reject on this axis.

`App\Support\TimetablePeriodGrid` — a pure support class (precedent:
`StudentDashboardBuilder`, `QuestionCsvImporter`), one public method
`build(Shift $shift): array` returning a list of
`['day_of_week' => string, 'period_no' => int, 'start_time' => string,
'end_time' => string]` rows for every day in `days_of_week`.

## Shared conflict/validity service (extracted, not new)

`App\Support\TimetableConflictChecker` — extracted from
`TimetableEntryController::assertValid()`/`assertNoConflicts()` with no
behavior change for the controller (its existing tests must keep passing
unmodified against the extracted version; this is a refactor-and-reuse,
not a rewrite). Two public methods, both pure (no HTTP concerns, no
exceptions — the controller's `ValidationException` wrapping stays in the
controller; the generator needs a boolean/reason, not a thrown
exception):

```php
/**
 * @param  array{subject_id:int, teacher_id:int, classroom_id:?int,
 *     day_of_week:string, period_no:int, start_time:?string,
 *     end_time:?string}  $attributes
 * @return string|null  null if valid, else a human-readable reason
 */
public function invalidReason(Section $section, array $attributes, ?int $exceptId = null): ?string;

/**
 * @param  array{teacher_id:int, classroom_id:?int, day_of_week:string, period_no:int}  $attributes
 * @return string|null  null if no conflict, else a human-readable reason
 */
public function conflictReason(array $attributes, ?int $exceptId = null): ?string;
```

`TimetableEntryController::assertValid()`/`assertNoConflicts()` become
thin wrappers that call these and throw `ValidationException` with the
returned reason. The generator calls the same two methods directly and
uses a `null` return as "this slot works," skipping to the next candidate
on a non-null reason.

## Draft replacement (restore-or-create, matching `store()`'s own pattern)

`timetable_entries` has a DB-level unique index on `(section_id,
day_of_week, period_no)` that does **not** exclude soft-deleted rows
(confirmed in the migration) — this is why `store()` already restores a
matching trashed row instead of inserting fresh when one exists. The
generator must follow the identical pattern, extracted so both call sites
share it:

`TimetableEntry::createOrRestore(array $attributes): TimetableEntry` (new
static method on the model) — looks for a trashed row matching
`(section_id, day_of_week, period_no)`; if found, restores it and
`update()`s it with `$attributes` (plus resetting `published_at`/
`published_by` to `null` and `status` to `'draft'`, mirroring `store()`'s
existing restore branch exactly); otherwise creates a fresh row via
`TimetableEntry::create($attributes)`. `store()` is updated to call this
instead of duplicating the restore/create branch inline.

Per-section generation sequence, inside that section's transaction:

1. Soft-delete the section's existing rows where `status = 'draft'`
   (`TimetableEntry::where('section_id', $section->id)->where('status',
   'draft')->delete()`).
2. For each subject assigned to the section's class-version with a
   non-null `required_weekly_periods`, attempt to place that many
   periods (see Algorithm below).
3. If every subject's full requirement was placed: call
   `createOrRestore()` for each placed slot, commit.
4. If any subject's requirement could not be fully placed: roll back
   (the soft-deleted drafts from step 1 are restored by the rollback,
   since the `delete()` happened inside the same transaction) and record
   the shortfall.

## Algorithm

Per section, after step 1 above:

1. **Order subjects most-constrained-first.** For each subject with a
   non-null `required_weekly_periods`, compute the count of candidate
   slots (from the shift's period grid) that are not already occupied by
   this section's own in-progress placements, this section's published
   entries, or (via `conflictReason()`) the assigned teacher's or
   classroom's bookings anywhere else. Process subjects in ascending
   order of that count — the subject with the fewest options is placed
   first, so it never loses its only viable slots to a less-constrained
   subject placed earlier.
2. **For each period this subject still needs**, scan the shift's period
   grid for candidate `(day_of_week, period_no)` slots the subject
   hasn't already used this week, preferring (in this order):
   a. a day this subject has zero periods on yet this week (spread
      across the week);
   b. failing that, a day where placing here would not create 3 or more
      consecutive periods for this subject (avoid excessive consecutive
      periods);
   c. any remaining valid slot.
   For each candidate in that preference order, build the attributes
   (`subject_id`, `teacher_id` from the section's `SubjectTeacherAssignment`,
   `classroom_id` from the section's `default_classroom_id`, the slot's
   `day_of_week`/`period_no`/`start_time`/`end_time`) and check
   `invalidReason()` then `conflictReason()` (against real DB state —
   this section's own placements so far in this run are flushed to the
   database immediately on placement, inside the same open transaction,
   so the very next candidate's conflict check sees them exactly as it
   would see any other existing entry — no separate in-memory conflict
   model is needed). Take the first candidate with no reason from either
   check; if none exists, this subject is short by one period — continue
   trying to place its remaining periods (don't abort the whole subject
   on one shortfall) and record every unplaceable period as part of the
   shortfall.
3. **If every subject placed its full requirement**, run up to 50 local-
   search iterations: pick two of this section's newly placed entries at
   random, try swapping their `(day_of_week, period_no, start_time,
   end_time)` triplets, keep the swap only if both resulting placements
   still pass `invalidReason()`/`conflictReason()` and the swap reduces a
   simple soft-constraint score (count of same-subject slots on the same
   day beyond the first, summed across all subjects) — otherwise discard
   the swap and try another pair. This is a bounded best-effort polish,
   not a search for the optimum.

If any subject fell short, the whole section's attempt is a failure (per
"all-or-nothing per section" above) — the local-search step is skipped
and every shortfall is recorded for the response.

## API

```
POST /shifts/{shift}/timetable-entries/generate
```

Behind `auth:sanctum`, alongside the other `timetable-entries` mutating
routes. No request body — the shift in the URL and its current
configuration (sections, their `SubjectTeacherAssignment`s, their
class-version's `required_weekly_periods`) are the only inputs.

- `App\Http\Controllers\Api\V1\TimetableEntryController::generate(Request $request, Shift $shift): JsonResponse`
  — `$this->authorize('generate', TimetableEntry::class)`, then delegates
  to a new `App\Support\TimetableGenerator::generate(Shift $shift): array`
  support class (same precedent as `StudentDashboardBuilder`: one public
  method, pure business logic, independently unit-testable without HTTP).
  Wraps the result as `response()->json(['data' => $result])`.

Response shape (200 — this is a report of what happened, not a single
pass/fail; some sections can succeed while others fail in the same call):

```json
{
  "data": {
    "generated": [
      {"section_id": 12, "entries_created": 30}
    ],
    "failed": [
      {
        "section_id": 13,
        "shortfalls": [
          {
            "subject_id": 5,
            "subject_name": "Mathematics",
            "required": 6,
            "placed": 4,
            "reason": "no conflict-free slot remaining for the assigned teacher"
          }
        ]
      }
    ]
  }
}
```

`reason` is the specific `invalidReason()`/`conflictReason()` message from
the last attempted candidate for that unplaced period — enough for an
admin to act on (e.g. "add another teacher" vs. "this shift has no more
room on Mondays").

## Permissions

New: `timetable_entries.generate` — seeded to exactly the roles that
already hold `timetable_entries.create` (confirmed in
`RolePermissionSeeder.php`): currently `school_admin` (plus
`national_admin` via its all-permissions wildcard). No other role gets
it — generating is at least as sensitive as manually creating entries,
and it replaces existing drafts across every section in a shift at once.

`TimetableEntryPolicy::generate(User $user): bool` — a new no-instance
ability (same convention as `ClassVersionPolicy`/`ChapterVideoPolicy`'s
`reorder()`): `return $user->hasPermission('timetable_entries.generate');`

## Testing

- `TimetablePeriodGridTest` (unit) — a shift with no breaks produces the
  expected sequential period count; a shift with one break in the middle
  produces periods before and after it with period numbers continuing
  sequentially (not restarting); a trailing remainder shorter than one
  period is dropped; multiple days in `days_of_week` each get the full
  grid independently.
- `TimetableConflictCheckerTest` (unit) — every case
  `TimetableEntryControllerTest` already covers for `assertValid()`/
  `assertNoConflicts()` (teacher conflict, classroom conflict, shift-hours
  violation, break overlap, weekly-cap exceeded, capacity exceeded,
  invalid teacher assignment) reproduced against the extracted service
  directly, confirming the extraction changed nothing.
- `TimetableEntryControllerTest` — existing tests for `store()`/`update()`
  must still pass unmodified after the extraction (regression guard).
- `TimetableGeneratorTest` (unit) — a shift with two sections, each with
  one subject needing fewer periods than the grid offers, generates a
  full conflict-free schedule for both; a shift where two sections share
  one teacher assigned to both, whose combined required periods exceed
  the available non-overlapping slots, correctly reports one section (or
  both, depending on ordering) as a shortfall rather than double-booking
  that teacher; a section's existing `published` entries are never
  altered or deleted by a regenerate call; a section's pre-existing draft
  entries are deleted and replaced on success; on failure, the section's
  pre-existing draft entries are restored exactly as they were (rollback
  verified by re-querying after the failed call); a subject with
  `required_weekly_periods = null` is skipped, not reported as a
  shortfall.
- `TimetableEntryControllerGenerateTest` (feature) — `POST
  /shifts/{shift}/timetable-entries/generate` succeeds end-to-end for a
  realistic small shift; a user without `timetable_entries.generate` gets
  403; the response's `generated`/`failed` shape matches the spec exactly;
  a mixed call (one section succeeds, one fails) returns both in the same
  200 response.
- `ControllerAuthorizationConventionTest` — add a row asserting `generate`
  calls `$this->authorize('generate', ...)` as its first statement.
- `DatabaseSeederTest` — assert `timetable_entries.generate` is seeded and
  mapped to exactly `school_admin` (`national_admin` covered separately by
  the existing all-permissions assertion, if one exists — otherwise this
  test adds the same pattern already used for other
  `*.generate`/`*.reorder`-style abilities).

## Out of scope for this project

- Frontend drag-and-drop manual editor (Phase 5.3) — no frontend work has
  been in scope for any sub-project in this program.
- Print/export to PDF/Excel (Phase 5.3).
- Notification-on-publish-change (Phase 5.4) — no notification
  infrastructure exists anywhere in this codebase.
- A true constraint-solver / metaheuristic (simulated annealing, CP-SAT)
  — no such dependency exists, and the greedy-plus-bounded-local-search
  approach is sufficient for realistic school-sized inputs.
- Classroom search/reassignment — the generator always uses the section's
  `default_classroom_id`; choosing a *different* room per period remains
  a manual-editor-only capability.
- Any change to the existing draft→publish workflow itself — generated
  entries are plain drafts, published exactly like manually created ones.

## Definition of done

- `App\Support\TimetablePeriodGrid::build()` produces the period grid
  described above for any Shift configuration, including breaks.
- `App\Support\TimetableConflictChecker` is the single source of hard-
  constraint validity/conflict logic, used by both
  `TimetableEntryController` (unchanged behavior, existing tests pass)
  and the new generator.
- `TimetableEntry::createOrRestore()` is the single source of the
  restore-or-create pattern, used by both `store()` and the generator.
- `POST /shifts/{shift}/timetable-entries/generate` works exactly as
  specified: per-section all-or-nothing, published entries never touched,
  draft entries replaced on success and left untouched on failure,
  response shape matches exactly.
- `timetable_entries.generate` permission seeded to exactly
  `school_admin` (+ `national_admin` via wildcard).
- All tests in "Testing" above pass, with zero regressions in the full
  suite.
