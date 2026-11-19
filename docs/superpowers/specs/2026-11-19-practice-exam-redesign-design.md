# Student Practice Exam Redesign — Design

## Goal

Replace the student frontend's "browse a list of pre-built exams" page
with a self-serve practice-exam builder: pick a class, a subject, and one
or more chapters (checkboxes), get a question set drawn from exactly
those chapters, take it, see a tiered result (failed / pass / good /
best), and — for the three passing tiers — view a short certificate on
its own page.

## Why this fits the existing backend almost entirely as-is

The backend already has a complete exam-taking engine:

- `Exam` (`selection_mode = 'per_attempt_random'`) + the
  `exam_random_chapters` pivot narrows a random draw to specific
  chapters.
- `ExamAttemptController` runs a *tracked* attempt for a logged-in
  student (creates `ExamAttempt`/`ExamAttemptQuestion`/
  `ExamAttemptAnswer` rows, grades on submit).
- `GuestExamAttemptController` runs the exact same kind of exam
  *statelessly* for an anonymous visitor (draws questions, grades a
  submission, persists nothing).
- `ExamGrading::isCorrect()` is the single shared correctness check
  both controllers use.
- The frontend's `ExamRunnerPage.tsx` (route `/exams/:id`) already
  drives both paths from one `examId`: it tries `startExamAttempt()`
  first when a token exists, falls back to the guest draw/submit path
  on an enrollment mismatch or a 403, and renders the same
  question-answering UI either way.

Almost none of that needs to change. The main addition is a way for a
**student** (not an admin) to create an `Exam` row on the fly from
their own chapter picks: one endpoint that provisions a hidden `Exam`
row wired to the student's selection, returning its id. The frontend
navigates to `/exams/:id` and the existing runner takes over unchanged.
The other change is a tightening, covered next.

## Guest access is now exclusive to practice exams

Today, `GuestExamAttemptController` lets anyone — no login — draw and
submit *any* published exam, including a teacher's own
manually-created ones. That's no longer correct: teacher-created exams
must require login, and only a student enrolled in that exam's own
school+class may attempt one. Practice exams (the new feature in this
spec) are the only exams that stay open to guests.

- `ExamAttemptController::start()` already enforces "same school+class"
  correctly today — `class_version_id` is itself school-specific, so
  matching the attempting student's own `class_version_id` against the
  exam's already rules out both a different school and a different
  class in one comparison. Nothing to change there.
- What's missing is blocking the *guest* path for non-practice exams.
  `GuestExamAttemptController::randomDraw()` and `submit()` each gain an
  `abort_unless($exam->is_practice, 403)` check (in addition to their
  existing `isPubliclyTakeable()` check) — a guest can no longer *take*
  (draw a random question set for) or *submit answers to* a
  teacher-created exam. This closes the attempt/grading path only: exam
  metadata and question text for a teacher-created exam remain reachable
  through the pre-existing, already-public `GET /exams/{exam}` and
  `GET /questions` endpoints, exactly as before this feature — that
  exposure predates this spec and is unchanged by it. `is_correct` on
  each option stays independently protected by `QuestionOptionResource`
  regardless of actor, so no answer key is exposed either way.
- `ExamResource` gains an `is_practice` field so the frontend can tell
  the difference up front. `ExamRunnerPage.tsx`'s setup screen checks
  `!token && !examPreview.is_practice` and shows a clear "this exam
  requires login" message instead of a Start button — rather than
  letting a guest hit Start and get a confusing 403 from the draw call.
- New test in `GuestExamAttemptControllerTest`: a published,
  non-practice exam's `randomDraw()`/`submit()` both reject a guest
  request (403), while the identical calls against a practice exam
  still succeed.
- Not a new restriction, just confirmed unaffected: teachers already
  hold `exams.create`/`exams.update` (verified in
  `RolePermissionSeeder`), so exam creation/publishing is untouched by
  any of this.

## Data flow

1. Student picks a class (reusing the existing `listClasses()`).
2. Student picks a subject (reusing the existing `subjectsForClass()`).
3. Student sees every chapter under that class+subject, pooled across
   every school's version of that class — the same cross-school
   pooling `VideoLecturePage`/`BooksPage` already use, via a new
   `chaptersForClassAndSubject()` resolver (see below). Each chapter is
   its own checkbox; if two schools both have a "Chapter 1", both
   appear as separate, separately-labeled entries (same precedent as
   `VideoLecturePage`'s chapter cards already showing a school name per
   entry) — no deduping, no merging.
4. Student checks 1+ chapters, sees a live "you'll get N questions"
   hint, clicks Submit.
5. Frontend calls `POST /api/v1/practice-exams` with
   `{ subject_id, chapter_ids }`. Backend creates a hidden `Exam` row
   (new `is_practice` flag) in `per_attempt_random` mode, with
   `exam_random_chapters` set to exactly those chapter ids, and
   `random_question_count` set from env-configurable settings: a
   different count for a single chapter vs. multiple. Returns
   `{ data: { id } }`.
6. Frontend navigates to `/exams/:id`. The existing `ExamRunnerPage`
   handles everything from here: start-or-guest-draw, answer, submit,
   grade — completely unmodified in that part of its logic.
7. `ExamRunnerPage`'s result screen is extended to compute a tier
   (failed/pass/good/best) from the score percentage, using
   env-configurable cutoffs fetched from a new public config endpoint,
   and — for the three passing tiers — show a "View certificate"
   button.
8. The certificate is a new page at `/exams/:id/certificate`, reading
   the result (score, tier, names, date) from React Router navigation
   state set when the "View certificate" button is clicked. No backend
   persistence for the certificate itself.

## Backend changes

### Migration: `is_practice` on `exams`

Nullable-free boolean, default `false`. `ExamController::index()` adds
an unconditional `->where('is_practice', false)` (both the privileged
and non-privileged branches) so practice exams never show up in any
exam listing, admin or student.

### Core-logic fix: `Exam::eligibleRandomQuestionIds()`

Current behavior: it computes the eligible chapter pool as "every
chapter reachable through a book under **this exam's own**
`(class_version_id, subject_id)`", then *intersects* that with
`exam_random_chapters` if any are set. For an admin-created exam this
is harmless — `SetExamRandomChaptersRequest` already requires every
chapter it accepts to belong to the exam's own class_version_id/
subject_id, so the intersection never removes anything. But a practice
exam's chapters are pooled **cross-school** (different chapters can
belong to different schools' class_versions), so the pre-filter would
silently empty the pool down to nothing.

Fix: when `exam_random_chapters` is non-empty, trust it directly and
skip the class_version_id/subject_id pre-filter entirely; only fall
back to that pre-filter when `exam_random_chapters` is empty (today's
"no narrowing configured" case, unchanged). This is the single shared
method `ExamController::randomize()` and `ExamAttemptController::start()`
both already call — fixing it here, not duplicating it, per its own
docblock. Behavior-preserving for every existing exam (verified above);
only *expands* what's reachable for new exams whose chapters span
multiple class_versions.

### New config: `config/practice_exams.php`

Reads from env, with these defaults:

| Config key | Env var | Default |
|---|---|---|
| `single_chapter_question_count` | `PRACTICE_EXAM_SINGLE_CHAPTER_QUESTIONS` | 20 |
| `multi_chapter_question_count` | `PRACTICE_EXAM_MULTI_CHAPTER_QUESTIONS` | 40 |
| `tier_pass_percent` | `PRACTICE_EXAM_TIER_PASS_PERCENT` | 40 |
| `tier_good_percent` | `PRACTICE_EXAM_TIER_GOOD_PERCENT` | 60 |
| `tier_best_percent` | `PRACTICE_EXAM_TIER_BEST_PERCENT` | 80 |

A score's tier: `< pass` → failed; `< good` → pass; `< best` → good;
else → best.

### New `PracticeExamController`

- `config(): JsonResponse` — public GET, returns the five values above
  as JSON (frontend fetches this once to show the question-count hint
  and compute result tiers).
- `store(StorePracticeExamRequest $request): JsonResponse` — public
  POST.
  1. Validates: `subject_id` exists; `chapter_ids` is a non-empty array
     of existing chapter ids; every one of those chapters' books has
     the submitted `subject_id` (mirrors the exact pattern
     `SetExamRandomChaptersRequest` already uses for its own
     same-subject check, adapted to not also require a single
     class_version_id).
  2. Resolves "owner" context for the new `Exam` row:
     - If `$request->user('sanctum')` is present and has a student
       profile with `section.shift` (giving a real
       class_version_id/academic_year_id/school_id/district_id) —
       its enrollment is used. This is what makes
       `ExamAttemptController::start()`'s later enrollment check pass:
       the practice exam's class_version_id now equals the attempting
       student's own.
     - Otherwise (guest, or an authenticated actor without a usable
       student profile — mirroring the frontend's existing
       403-falls-through-to-guest pattern) — uses the *first* selected
       chapter's own book's `class_version_id`/`school_id`/
       `district_id`, and the current academic year
       (`AcademicYear::where('is_current', true)->first()`). This
       value is cosmetic/reporting-only for a guest, since guests never
       hit the enrollment check at all.
  3. Creates the `Exam`: `is_practice = true`, `status = 'published'`,
     `selection_mode = 'per_attempt_random'`, `marks_per_question = 1`
     (flat, so score/total already equals the percentage),
     `random_question_count` from config (single vs. multi-chapter),
     `passing_score_percent` = config's `tier_pass_percent`, a
     generated unique `name` (e.g. `"Practice #{Str::random(8)}"` —
     uniqueness only matters within class_version+subject+
     academic_year, and collisions are astronomically unlikely but the
     random suffix removes even that), plus the resolved
     class_version_id/subject_id/academic_year_id/school_id/
     district_id.
  4. Attaches `exam_random_chapters` for every submitted chapter id.
  5. Returns `{ data: { id: $exam->id } }`, 201.
- No find-or-create/dedup across different students' identical chapter
  picks — every submission creates a fresh row. Accepted tradeoff: the
  `exams` table grows by one small config row per practice session,
  with no cleanup job. Not solved here.

### New routes

```php
Route::get('/practice-exams/config', [PracticeExamController::class, 'config']);
Route::post('/practice-exams', [PracticeExamController::class, 'store']);
```

Both outside `auth:sanctum` (must serve guests), alongside the other
public routes.

### Tests

- `PracticeExamControllerTest`: guest creation (no actor; picks
  single-chapter vs multi-chapter counts correctly), logged-in creation
  (uses the actor's own enrollment; verified by then calling the real
  `start()` endpoint and confirming it succeeds instead of throwing the
  enrollment-mismatch error), mixed-subject chapter rejection, config()
  returns the configured values.
- A unit or feature test on `Exam::eligibleRandomQuestionIds()` proving
  a cross-school chapter selection is no longer emptied out, and that
  an existing single-school exam's behavior is unchanged.
- `GuestExamAttemptControllerTest`: a new case proving a published,
  non-practice exam's `randomDraw()`/`submit()` reject a guest with
  403, while the same calls against a practice exam still succeed
  (covered in "Guest access is now exclusive to practice exams" above).

## Frontend changes

### `resolveContent.ts`

Extract `chaptersForClassAndSubject(classId, subjectId)` — the
books→chapters cross-school merge, with no "must have a video" filter.
`chaptersWithVideosForClassAndSubject()` is refactored to call it and
then apply its own video-count filter on top, removing the duplicated
merge logic.

### `api-client`

- `type PracticeExamConfig` + `getPracticeExamConfig()`.
- `createPracticeExam({ subjectId, chapterIds }, token?: string)` —
  token is optional; when present, attaches the `Authorization` header
  (mirrors how `logout()`/other dual guest-or-tracked calls already
  work), letting the backend resolve the acting student when logged in.

### `ExamsPage.tsx` — full rewrite

Class select → subject select → chapter checkboxes (each chapter
labeled with its book name and owning school, same as
`VideoLecturePage`'s chapter cards) → a line reading something like
"১টি অধ্যায় = ২০টি প্রশ্ন, একাধিক অধ্যায় = ৪০টি প্রশ্ন" sourced from
the fetched config, updating live with the checked count → Submit
button, disabled until at least one chapter is checked → on submit,
calls `createPracticeExam()` and navigates to `/exams/:id`.

### `ExamRunnerPage.tsx` — result screen only

Fetches the practice-exam config on mount (cheap, cached module-level
like `resolveContent.ts`'s academic-year memo). On the `submitted`
screen, **only for a practice exam** (`examPreview.is_practice`),
computes `percent = total > 0 ? (score / total) * 100 : 0` and a tier
from the config cutoffs, showing the tiered message instead of the
plain passed/failed line. A teacher-created exam always keeps the
original `result.passed`-based line and never shows a certificate
button — the tier scheme must never override an exam's own
`passing_score_percent` verdict. For `pass`/`good`/`best`, shows a
"View certificate" button that navigates to `/exams/:id/certificate`
with `state` carrying `{ score, total, percent, tier, date }`.

### New `CertificatePage.tsx` (`/exams/:id/certificate`)

Reads `location.state`. If present: a styled certificate card (border,
logo, "সনদপত্র" heading, the logged-in viewer's own name (resolved via
`useAuth()`, not passed through navigation state) or "অতিথি
শিক্ষার্থী" for a guest, score, tier-appropriate praise line, date)
with a print button (`window.print()` — no PDF generation, no new
dependency). Deliberately narrowed from an earlier draft of this spec:
class/subject/chapters-covered are NOT shown — plumbing that through
the full creation→running→certificate chain is a larger follow-up, not
built here. If `state` is missing (direct link, refresh, or shared
URL) — a graceful message plus a link back to `/exams`; this is an
accepted limitation, not a bug, consistent with guests never getting
persisted results today.

### i18n

New `student.practiceExam.*` keys for the picker (class/subject/
chapter labels, the question-count hint, submit button, validation/
error messages) and `student.exams.tier*`/`student.certificate.*` keys
for the tiered messages and certificate copy, in both `bn.json` and
`en.json`.

## Known tradeoffs (explicitly accepted, not solved here)

- Practice sessions accumulate indefinitely with no cleanup job and no
  operator-facing way to find or purge them: each one leaves behind an
  `exams` row, one `exam_random_chapters` row per selected chapter, and
  (for a logged-in attempt) an `exam_attempts` row plus its
  per-question/per-answer rows — all permanently excluded from every
  exam listing, including the admin/teacher-facing ones, by the same
  `is_practice` filter that hides them from students. The provisioning
  endpoint is unauthenticated and rate-limited only lightly
  (`throttle:30,1`) with a `max:50` cap on `chapter_ids` per request —
  sustained abuse can still grow these tables substantially faster than
  any real classroom usage would. A future `artisan` prune command (or
  at minimum a documented manual-SQL runbook) is the natural follow-up,
  not built here.
- The certificate page cannot be reloaded or reshared after the fact —
  it only exists for the single navigation right after finishing.
- Teacher-created exams are no longer reachable from student nav at
  all, and (per "Guest access is now exclusive to practice exams"
  above) now require login + same-school/class enrollment even via a
  direct link. The admin/teacher-facing exam-management backend itself
  is untouched — only the student-facing browse list and guest access
  are affected.
