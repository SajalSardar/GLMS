# Design: Exam Management (Admin/Teacher Side)

**Status:** Approved, ready for implementation planning
**Sub-project:** 4 of 6 (see `docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`
"Program decomposition" for the full six-part breakdown; this sub-project
depends on sub-project 3, which gave `Question`/`QuestionOption` their
`mcq_single`/`mcq_multiple`/`true_false` shape)

## Scope of this sub-project

Add an `Exam` model that assembles questions from the bank into a
configured assessment: question selection (manual, a one-time server-side
random draw, or per-attempt-random criteria), time/attempt limits, a
passing score, a scheduling window, and a draft/published lifecycle. This
sub-project is **admin/teacher configuration only** — no student-facing
attempt-taking, no grading, and no actual per-attempt random draw (that
draw happens when a student starts an attempt, which is sub-project 5's
job; this sub-project only stores the criteria that draw will use).

**Not to be confused with** the original proposal's §5.5 "Exam Timetable
and Admit Cards" (`inspiration/AI_Teacher_Training_Proposal_v2.md`) — that
is a physical-exam-scheduling/printing feature (Word-compatible
timetables, seat-level admit cards), entirely unrelated to this LMS
program's question-bank-driven assessments, and is not implemented by
this or any of the six LMS sub-projects. This sub-project implements
§4.4's "Question bank, randomized questions, time limits, attempt limits,
passing scores... retake rules" instead.

AI-assisted question drafting remains out of scope, consistent with
sub-project 3's decision — this sub-project only assembles
already-authored questions into an exam.

## Decisions confirmed during brainstorming

- **Exam scope:** an `Exam` belongs to one `(ClassVersion, Subject)` pair
  — the same granularity as `Book` — and can draw questions from any
  chapter reachable through any `Book` under that pair (a subject can have
  multiple books per sub-project 1's decision).
- **Year linkage:** unlike `Book`/`Chapter`/`Question` (deliberately
  year-independent), an `Exam` belongs to one `AcademicYear`. A real
  midterm/final happens once per year even when built from the
  year-independent question bank.
- **Selection modes**, all three configured by this sub-project:
  - `manual` — an admin/teacher hand-picks an explicit, ordered list of
    questions.
  - `fixed_random` — an admin specifies criteria (count, optional
    chapter/difficulty filters) and the system draws a fixed set **once**,
    server-side, when triggered. Every student who takes this exam later
    sees the same questions. Internally this resolves into the exact same
    storage shape as `manual` — a `fixed_random` draw is just a one-time
    algorithmic population of the same ordered question list.
  - `per_attempt_random` — an admin configures count + optional
    chapter/difficulty filters, but **no question is resolved by this
    sub-project**. Each student's attempt (sub-project 5) draws its own
    random set from the configured criteria at attempt-start time, so
    different students may see different questions.
- **Total marks** are never independently declared — always computed from
  the underlying questions: for `manual`/`fixed_random`, the sum of the
  selected questions' own `marks` values; for `per_attempt_random`, a
  dedicated `marks_per_question` value (since the actual marks of whichever
  questions get drawn per-attempt may vary, and grading needs a
  predictable total regardless of the draw). **This is computed on read,
  never persisted** — a stored/cached total would go stale the moment the
  question list or `marks_per_question` changes, and nothing in this
  sub-project needs to query by total marks.
- **Who manages:** `trainer`, `teacher`, and `school_admin` get
  `exams.create`/`exams.update` (school_admin is included here, unlike the
  chapter/question content-authoring permissions, because assembling exams
  is a more administratively significant action). `exams.delete` stays
  `national_admin`-only, matching `questions.delete`'s conservative
  precedent.
- **Who views — a deliberate break from every prior content permission in
  this codebase:** `exams.view` is restricted to oversight/management
  roles only — `national_admin`, `district_officer`, `school_admin`,
  `trainer`, `teacher`. **`student`, `parent_guardian`, `office_staff`,
  `it_admin`, and `auditor` get no `exams.*` permission at all.** Every
  prior content permission (`books.view`, `chapters.view`,
  `chapter_videos.view`, `chapter_materials.view`, `questions.view`) was
  granted broadly because reading that content has no integrity cost. An
  exam's configured question list is different: knowing in advance exactly
  which questions will appear (even without seeing `is_correct`) lets a
  student prepare answers ahead of time in a way the rest of the content
  model doesn't need to guard against. Sub-project 5 will expose whatever
  subset of exam metadata a student legitimately needs (time limit, total
  marks, pass threshold) through its own student-facing endpoint, not
  through this admin-facing one.

## Schema

### New `exams` table

```
id
class_version_id       FK -> class_versions, cascade delete
subject_id              FK -> subjects, cascade delete
academic_year_id        FK -> academic_years, cascade delete
school_id               denormalized from class_version at creation (never taken from the request)
district_id             denormalized from class_version at creation
name
bn_name                  nullable
selection_mode            string: manual | fixed_random | per_attempt_random (immutable after creation)
time_limit_minutes        nullable unsigned smallint
max_attempts               nullable unsigned tinyint (null = unlimited)
passing_score_percent       nullable unsigned tinyint (0-100)
available_from               nullable datetime
available_until               nullable datetime
status                         string: draft | published, default draft
random_question_count           nullable unsigned smallint (fixed_random / per_attempt_random only)
random_difficulty                 nullable string: easy | medium | hard (optional filter, random modes only; null = any)
marks_per_question                  nullable unsigned smallint (per_attempt_random only)
timestamps
soft deletes

unique(class_version_id, subject_id, academic_year_id, name)
```

`Exam` model: `TenantScoped`; `belongsTo(ClassVersion)`, `belongsTo(Subject)`,
`belongsTo(AcademicYear)`; `belongsToMany(Question::class, 'exam_questions')
->withPivot('order_index')->orderBy('exam_questions.order_index')` (named
`questions()`); `belongsToMany(Chapter::class, 'exam_random_chapters')`
(named `randomChapters()`, used only by `per_attempt_random`'s optional
narrowing — empty means "any chapter under this exam's subject+class
version is eligible").

**`totalMarks` is a computed accessor, not a column:**

```php
protected function totalMarks(): Attribute
{
    return Attribute::make(get: function () {
        if ($this->selection_mode === 'per_attempt_random') {
            return ($this->random_question_count ?? 0) * ($this->marks_per_question ?? 0);
        }

        return $this->questions->sum('marks');
    });
}
```

(Requires `questions` to be loaded for the `manual`/`fixed_random` branch;
`ExamResource` always eager-loads it.)

### New `exam_questions` table (pivot; used by `manual` and `fixed_random` only)

```
id
exam_id         FK -> exams, cascade delete
question_id      FK -> questions, cascade delete
order_index        unsigned smallint, default 0
timestamps

unique(exam_id, question_id)
```

No tenant columns — same reasoning as `question_options` (sub-project 3):
always reached through `exam->questions()`, never queried standalone.

### New `exam_random_chapters` table (pivot; optional, `per_attempt_random` only)

```
id
exam_id       FK -> exams, cascade delete
chapter_id     FK -> chapters, cascade delete
timestamps

unique(exam_id, chapter_id)
```

Also no tenant columns, same reasoning.

## API

**Every `exams` route requires `auth:sanctum` — there is no public
`index`/`show`**, unlike every other resource in `routes/api.php`. This
is deliberate (see "Who views" above): no role grants `exams.view` to an
unauthenticated caller, and exam-integrity sensitivity means there's no
anonymous-read case to support here at all.

```
GET    /exams                       (auth, exams.view, ?class_version_id=&subject_id=&academic_year_id= filters)
GET    /exams/{exam}                (auth, exams.view)
POST   /exams                       (auth, exams.create)
PUT    /exams/{exam}                (auth, exams.update)
DELETE /exams/{exam}                (auth, exams.delete)
PATCH  /exams/{exam}/questions       (auth, exams.update — instance-based, no ArgumentCountError risk since {exam} exists)
POST   /exams/{exam}/randomize        (auth, exams.update)
```

- `ExamController` — `store`/`update` follow the `ChapterVideo`/`Question`
  shape exactly. `update` folds `status` (draft→published) into the normal
  update flow, never a dedicated publish endpoint — same "one flag, no
  second code path" principle used for `ChapterVideo.is_enabled`
  (sub-project 2). `class_version_id`/`subject_id`/`academic_year_id`/
  `selection_mode` are immutable after creation (like `chapter_id` on
  `ChapterVideo`).
- `ExamPolicy` — `viewAny`/`view`/`create`/`update`/`delete`, standard
  shape (no new no-instance ability needed: `questions` and `randomize`
  both act on an existing `{exam}` instance, so they authorize via the
  existing instance-typed `update(User $user, Exam $exam): bool`, not a
  `reorder()`-style no-instance ability).
- `StoreExamRequest` — `class_version_id`/`subject_id`/`academic_year_id`
  exist (`withoutTrashed()`); `name` unique per
  `(class_version_id, subject_id, academic_year_id)` excluding trashed
  (same soft-delete restore-on-recreate behavior as `Book`/`Chapter`);
  `selection_mode` required, `in:manual,fixed_random,per_attempt_random`;
  `random_question_count` required when `selection_mode` is
  `fixed_random` or `per_attempt_random`; `marks_per_question` required
  when `selection_mode` is `per_attempt_random`; `available_until` after
  `available_from` when both present.
- `UpdateExamRequest` — everything except
  `class_version_id`/`subject_id`/`academic_year_id`/`selection_mode` is
  editable. A `withValidator` check: transitioning `status` to
  `published` requires completeness —
  `manual`/`fixed_random` needs at least one row in `exam_questions`;
  `per_attempt_random` needs `random_question_count` set and the eligible
  question pool (chapters under this exam's subject+class-version, narrowed
  by `exam_random_chapters` and `random_difficulty` if set) to contain at
  least that many questions. This guards against sub-project 5 failing to
  draw enough questions later.
- `SetExamQuestionsRequest` (`PATCH /exams/{exam}/questions`) — rejects
  if the exam's `selection_mode` is `per_attempt_random` (that mode never
  uses `exam_questions`); `question_ids` required array of existing,
  non-trashed question IDs, each belonging to a chapter reachable through
  a book under this exam's `(class_version_id, subject_id)` (same
  relational-constraint-via-`withValidator` pattern as
  `ReorderQuestionsRequest`). The controller action replaces the full
  `exam_questions` set transactionally (delete-and-recreate, same pattern
  as `Question::syncOptions()`).
- `POST /exams/{exam}/randomize` — rejects if `selection_mode` isn't
  `fixed_random`. Queries eligible questions (chapters under this exam's
  subject+class-version, narrowed by `exam_random_chapters` and
  `random_difficulty` if set), shuffles, takes `random_question_count`;
  if the eligible pool is smaller than `random_question_count`, returns
  422 instead of drawing a short set. Replaces `exam_questions`
  transactionally, same as `SetExamQuestionsRequest`'s action.
- `ExamResource` — `id`, `class_version_id`, `subject_id`,
  `academic_year_id`, `name`, `bn_name`, `selection_mode`,
  `time_limit_minutes`, `max_attempts`, `passing_score_percent`,
  `available_from`, `available_until`, `status`, `random_question_count`,
  `random_difficulty`, `marks_per_question`, `total_marks` (the computed
  accessor), plus `questions` (`ExamQuestionResource::collection`, ordered,
  only meaningful for `manual`/`fixed_random`) and `random_chapters`
  (`ChapterResource::collection`) when loaded.
- `ExamQuestionResource` — `id` (the pivot row's id), `order_index`,
  `question` (`QuestionResource`, which already nests
  `QuestionOptionResource` and already strips `is_correct` from any
  viewer without `questions.update`). **This composes correctly without
  extra work**: `district_officer` has `exams.view` but not
  `questions.update`, so a `district_officer` viewing an exam still can't
  see which options are correct — the existing sub-project 3 gate applies
  automatically through the nested resource.

## Testing

- `ExamControllerTest` (new) — CRUD for all three `selection_mode` values;
  tenant-scoping (School A actor cannot see/edit School B's exam);
  `auth:sanctum` required on `index`/`show` too (no public access, unlike
  every other resource); policy denial for every role without
  `exams.*` (explicitly assert `student`/`parent_guardian`/`office_staff`/
  `it_admin`/`auditor` are forbidden even with every `questions.*`
  permission granted); `selection_mode`/`class_version_id`/`subject_id`/
  `academic_year_id` immutability on update; publish-validation guard
  (reject publishing an empty `manual` exam; reject publishing a
  `per_attempt_random` exam whose eligible pool is smaller than
  `random_question_count`).
- `ExamQuestionsControllerTest` (new, or folded into `ExamControllerTest`)
  — `PATCH /exams/{exam}/questions` sets the ordered list and rejects a
  question from an unrelated subject/class-version; rejected for
  `per_attempt_random` exams; `POST /exams/{exam}/randomize` draws exactly
  `random_question_count` questions respecting `random_difficulty`/
  `exam_random_chapters` filters, rejected when the pool is too small,
  rejected for non-`fixed_random` exams.
- `total_marks` accessor test — a `manual` exam with questions of marks
  `[2, 3, 5]` reports `total_marks = 10`; a `per_attempt_random` exam with
  `random_question_count = 5`, `marks_per_question = 2` reports
  `total_marks = 10`.
- Denormalization check — `Exam`'s `school_id`/`district_id` always match
  its parent `ClassVersion`'s, same pattern as `Book`'s factory-level
  assertion.
- `DatabaseSeederTest` — assert an `exams.view` permission seed exists.

## Out of scope for this sub-project

- Student-facing exam listing/attempt-taking, auto/manual grading,
  results — sub-project 5.
- The actual `per_attempt_random` draw at attempt time — sub-project 5
  consumes this sub-project's stored criteria (`random_question_count`,
  `random_difficulty`, `exam_random_chapters`) to perform it.
- AI-assisted question/exam drafting.
- The unrelated §5.5 "Exam Timetable and Admit Cards" feature (physical
  scheduling, Word-format timetables, printed admit cards) — not part of
  this LMS program.
- Bulk import/export of exams — no stated need, unlike the question
  bank's CSV requirement.

## Definition of done

- `exams`, `exam_questions`, `exam_random_chapters` tables and models
  exist as specified; `Exam` is `TenantScoped`; `totalMarks` is a
  non-persisted accessor.
- `ExamController`/`ExamPolicy`/`StoreExamRequest`/`UpdateExamRequest`/
  `SetExamQuestionsRequest`/`ExamResource`/`ExamQuestionResource` exist;
  routes registered in `routes/api.php` with **no public branch**.
- `exams.*` permissions seeded and mapped exactly per the "Who
  manages"/"Who views" sections above — explicitly excluding `student`,
  `parent_guardian`, `office_staff`, `it_admin`, `auditor`.
- `PATCH /exams/{exam}/questions` and `POST /exams/{exam}/randomize` work
  as specified, including the eligible-pool-too-small rejection.
- Publishing an incomplete exam (empty question list, or an insufficient
  random pool) is rejected.
- `ExamQuestionResource`'s nested `QuestionResource` correctly keeps
  `is_correct` hidden from any exam viewer without `questions.update`
  (e.g. `district_officer`).
- All tests in "Testing" above pass.
