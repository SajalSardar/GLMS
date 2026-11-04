# Design: Student Exam-Taking + Grading

**Status:** Approved, ready for implementation planning
**Sub-project:** 5 of 6 (see `docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`
"Program decomposition" for the full six-part breakdown; this sub-project
depends on sub-project 4, which gave `Exam` its three selection modes and
admin-side CRUD)

## Scope of this sub-project

Let a student start, answer, and submit an attempt at a `published` exam,
with automatic grading, a time-limit lazy-expiry mechanism, and
result/feedback viewing scoped to the student themselves, their linked
guardian, or the exam's management/oversight roles.

**Grading is always fully automatic.** Sub-project 3 deliberately limited
the question bank to `mcq_single`/`mcq_multiple`/`true_false` — every
question type currently in the bank is deterministically gradable. There
is no manual-grading workflow in this sub-project, unlike the original
proposal's "auto and manual marking" language, because no open-ended
question type exists for a human to grade.

## Decisions confirmed during brainstorming

- **Answer submission is incremental**, not all-at-once: a per-question
  "save answer" endpoint the student calls as they progress (mirroring
  `ChapterVideoController::updateProgress`'s pattern — any authenticated
  user acts on their own record, scoped by `request->user()->id`, no flat
  permission needed), plus a separate "submit" call that finalizes and
  grades. This survives a dropped connection mid-attempt and gives the
  time-limit mechanism something to grade even if the student never
  explicitly submits.
- **Time limit enforcement is a hard server-side cutoff via lazy expiry,
  not a background job.** No queued `Job` class exists anywhere in this
  codebase yet (confirmed during the Question Bank sub-project). Instead:
  whenever an attempt is touched by any action (save-answer, submit, or a
  `GET`), if `time_limit_minutes` is set and
  `now() > started_at + time_limit_minutes` while the attempt is still
  `in_progress`, the system auto-finalizes it first — grading whatever
  answers already exist, with `submitted_at` set to the deadline instant,
  not the current instant — before processing whatever the actual request
  was. A save-answer call arriving after that point is rejected (422,
  "attempt already finalized due to time expiry"); an explicit submit
  call arriving late simply completes against what was already graded.
- **Feedback (per-question correctness + the correct answer) is returned
  immediately** on submission and on any later fetch of a submitted
  attempt — matching the original proposal's "feedback" requirement
  directly. The known risk (a retake on the same fixed question set lets
  a student who's already seen the answers prepare for attempt 2) is
  accepted as a known tradeoff, not mitigated in this sub-project.
- **Eligibility to start an attempt requires the student to actually
  belong to the exam's `(ClassVersion, AcademicYear)`,** checked via the
  existing `StudentProfile → section() → shift()` chain (`Shift` already
  carries both `class_version_id` and `academic_year_id` — confirmed,
  no new linkage needed). A student whose own enrollment doesn't match
  the exam's scope cannot start an attempt, even if otherwise
  authenticated.
- **`per_attempt_random`'s actual draw happens at attempt-start,
  frozen for that attempt** via a new `exam_attempt_questions` snapshot
  table — created for **every** selection mode, not just the random one,
  so grading and review always have one uniform reference regardless of
  how the exam picked its questions, and so a later edit to the exam's
  live question list/bank never retroactively changes what an
  already-started attempt is graded against.
- **`mcq_multiple` correctness is all-or-nothing** — the selected option
  set must exactly equal the question's correct-option set for any marks
  to be awarded. No partial credit model in this sub-project.
- **Result visibility** extends beyond the attempt's own student to:
  the exam's management/oversight roles (`national_admin`,
  `district_officer`, `school_admin`, `trainer`, `teacher` — the same
  roles holding `exams.view`), and the student's linked guardian (via the
  existing `User::guardian` → `Guardian::students()` relation, already
  used by `Guardian::isVisibleTo()`'s equivalent ownership check).
  Viewing your **own** attempt, or your **linked child's**, needs no flat
  permission grant — same precedent as `ChapterVideoController::updateProgress`.
  Viewing *any other* student's attempt needs the new `exam_attempts.view`
  permission (management/oversight roles only).

## Schema

### New `exam_attempts` table

```
id
exam_id                 FK -> exams, cascade delete
user_id                   FK -> users, cascade delete (the student; "any authenticated user acts on their own record" convention — not student_profile_id, matching ChapterVideoProgress's precedent)
school_id, district_id      denormalized from exam at creation (never taken from the request)
attempt_number                 unsigned tinyint, 1-based per (exam_id, user_id)
status                           string: in_progress | submitted, default in_progress
started_at                        datetime
submitted_at                        nullable datetime
score                                 nullable unsigned smallint
total_possible_marks                   nullable unsigned smallint
passed                                   nullable boolean
timestamps
soft deletes

unique(exam_id, user_id, attempt_number)
```

`ExamAttempt` model: `TenantScoped`; `belongsTo(Exam)`, `belongsTo(User)`,
`hasMany(ExamAttemptQuestion)` ordered by `order_index`,
`hasMany(ExamAttemptAnswer)`.

### New `exam_attempt_questions` table (snapshot; every selection mode)

```
id
exam_attempt_id      FK -> exam_attempts, cascade delete
question_id            FK -> questions, cascade delete
order_index               unsigned smallint, default 0
marks                       unsigned smallint (snapshotted from the question at attempt-start — decoupled from later question-bank edits)
timestamps

unique(exam_attempt_id, question_id)
```

No tenant columns — same reasoning as `exam_questions`/`question_options`:
always reached through `exam_attempt->questions()`, never queried
standalone.

### New `exam_attempt_answers` table

```
id
exam_attempt_id      FK -> exam_attempts, cascade delete
question_id            FK -> questions, cascade delete
selected_option_ids       JSON array of question_option ids (nullable — a question the student hasn't answered yet has no row, or an empty-array row; implementer's choice which, documented in the plan)
is_correct                  nullable boolean (set at grading time)
marks_awarded                 nullable unsigned smallint (set at grading time)
timestamps

unique(exam_attempt_id, question_id)
```

Also no tenant columns, same reasoning.

## Grading logic

At finalization (explicit submit, or lazy-expiry auto-finalize), for
every row in `exam_attempt_questions`:

- Look up the corresponding `exam_attempt_answers` row (if any — an
  unanswered question grades as incorrect/zero marks).
- `mcq_single`/`true_false`: correct iff `selected_option_ids` contains
  exactly one id, and that `QuestionOption.is_correct` is `true`.
- `mcq_multiple`: correct iff the set of `selected_option_ids` exactly
  equals the set of that question's correct `QuestionOption` ids (order
  doesn't matter, but no partial overlap counts).
- `marks_awarded` = the snapshotted `exam_attempt_questions.marks` if
  correct, else `0`.
- `exam_attempts.score` = sum of `marks_awarded` across all questions.
- `exam_attempts.total_possible_marks` = sum of
  `exam_attempt_questions.marks` (the snapshot, not the live exam).
- `exam_attempts.passed` = `null` if the exam has no
  `passing_score_percent`; otherwise
  `(score / total_possible_marks * 100) >= exam.passing_score_percent`.

## Permissions

New: `exam_attempts.create` (start/save-answer/submit — granted **only**
to `student`), `exam_attempts.view` (view **any** attempt, not just your
own — granted to `national_admin`, `district_officer`, `school_admin`,
`trainer`, `teacher`, matching the exact role list that already holds
`exams.view`).

No new permission is needed for a student viewing their own attempt or a
guardian viewing their linked child's — both are ownership/relationship
checks in `ExamAttemptPolicy`, not flat role grants.

## API

```
POST   /exams/{exam}/attempts                         start (auth, exam_attempts.create + eligibility: published, within window, under max_attempts, student belongs to exam's class_version+academic_year)
GET    /exam-attempts/{exam_attempt}                   view one attempt (auth; owner, or owner's linked guardian, or exam_attempts.view)
PUT    /exam-attempts/{exam_attempt}/answers/{question}    save one answer (auth, owner only; 404 if the question isn't in this attempt's snapshot; lazy-expiry check runs first)
POST   /exam-attempts/{exam_attempt}/submit               finalize + grade (auth, owner only; lazy-expiry check runs first — a call here after expiry still succeeds, grading against whatever was already auto-finalized)
GET    /exams/{exam}/attempts                            list every attempt for an exam (auth, exam_attempts.view — management/oversight only, not the student's own-attempts list)
```

- `ExamAttemptController::start()` — validates eligibility (in order:
  exam exists and is `published`; within `available_from`/`available_until`
  if set; `attempt_number` for this `(exam_id, user_id)` pair doesn't
  exceed `exam.max_attempts` if set; the student's
  `StudentProfile->section->shift` matches the exam's
  `class_version_id`/`academic_year_id`). Builds the `exam_attempt_questions`
  snapshot: for `manual`/`fixed_random`, copies the exam's live
  `exam_questions`; for `per_attempt_random`, performs the random draw
  using the exact same eligible-pool logic `ExamController::randomize()`
  already implements (chapter pool under the exam's subject+class-version,
  narrowed by `exam_random_chapters` if set, filtered by
  `random_difficulty` if set), erroring 422 if the pool is smaller than
  `random_question_count` — same guard `randomize()` already has.
- `ExamAttemptController::saveAnswer()` — upserts one
  `exam_attempt_answers` row (no partial grading happens here; `is_correct`/
  `marks_awarded` stay `null` until finalization). Runs the lazy-expiry
  check first; if the attempt just got auto-finalized by this check,
  rejects with 422 instead of saving.
- `ExamAttemptController::submit()` — runs the lazy-expiry check first
  (a no-op if it already finalized); if still `in_progress`, grades per
  the "Grading logic" section above and marks `submitted`.
- `ExamAttemptResource` — includes per-question feedback
  (`is_correct` per `exam_attempt_answers` row, plus each question's
  correct option(s)) **only when `status === 'submitted'`** — an
  `in_progress` attempt's resource never exposes correctness, since the
  student hasn't finished yet.
- `ExamAttemptPolicy::view(User $user, ExamAttempt $attempt): bool` —
  `true` if `$user->id === $attempt->user_id`; else `true` if
  `$user->hasPermission('exam_attempts.view')`; else `true` if
  `$user->guardian` exists and
  `$user->guardian->students()->where('user_id', $attempt->user_id)->exists()`;
  else `false`.

## Testing

- `ExamAttemptControllerTest` — start succeeds for an eligible student on
  a published exam; rejected for an ineligible student (wrong
  class_version/academic_year); rejected when the exam isn't published;
  rejected when `max_attempts` is exceeded; rejected outside the
  availability window; `per_attempt_random` start performs the draw and
  rejects (422) when the pool is too small, same as `randomize()`.
- Answer-saving and grading — save answers for `mcq_single`,
  `mcq_multiple` (including a partially-correct selection that scores 0,
  proving all-or-nothing), and `true_false`; submit computes `score`/
  `total_possible_marks`/`passed` correctly; an unanswered question grades
  as 0.
- Lazy expiry — an attempt whose `time_limit_minutes` has elapsed is
  auto-finalized on the next touch (both a save-answer call, which must
  be rejected, and a submit call, which must succeed against whatever was
  already there); an attempt within its time limit is unaffected.
- Visibility — the attempt's own student can view it; a linked guardian
  can view it; an unrelated student cannot (403/404); a `trainer`/`teacher`/
  `school_admin` with `exam_attempts.view` can view any attempt in their
  tenant; a `student`/`parent_guardian`/other role without a matching
  relationship cannot.
- Feedback gating — an `in_progress` attempt's resource never exposes
  `is_correct`/correct-option data; a `submitted` attempt's does.
- `DatabaseSeederTest` — assert an `exam_attempts.view` permission seed
  exists.

## Out of scope for this sub-project

- Manual grading of any kind (no gradable type requires it).
- Partial credit for `mcq_multiple`.
- Withholding feedback until all attempts are exhausted (accepted
  tradeoff, not mitigated).
- Notifications/reminders about upcoming or in-progress exams.
- Any background-job-based expiry (Horizon/queued `Job` infrastructure
  doesn't exist in this codebase yet) — expiry is purely reactive/lazy.
- Dashboards, aggregate reporting, or analytics over attempt results —
  sub-project 6 ("Student learning dashboard").

## Definition of done

- `exam_attempts`/`exam_attempt_questions`/`exam_attempt_answers` tables
  and models exist as specified; `ExamAttempt` is `TenantScoped`.
- Start/save-answer/submit endpoints work exactly as specified, including
  eligibility checks, snapshot creation (all three selection modes), and
  lazy time-limit expiry.
- Grading matches the "Grading logic" section exactly, including
  all-or-nothing `mcq_multiple` scoring and the snapshotted
  `total_possible_marks`.
- `ExamAttemptPolicy::view()` correctly scopes to owner, linked guardian,
  or `exam_attempts.view` holder — and no one else.
- `exam_attempts.create`/`exam_attempts.view` permissions seeded and
  mapped exactly per the "Permissions" section.
- An `in_progress` attempt's resource never leaks correctness data; a
  `submitted` one does.
- All tests in "Testing" above pass.
