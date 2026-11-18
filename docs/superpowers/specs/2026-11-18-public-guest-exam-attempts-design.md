# Design: Public/Guest Exam-Taking

**Status:** Approved, ready for implementation planning
**Sub-project:** 3 of 5 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

Context carried from `docs/superpowers/specs/2026-11-18-national-content-descoping-design.md`'s
own program decomposition. Five sub-projects:

1. De-scope national content + remove video progress — **done, merged**.
2. Restructure `school_classes` — **done, merged**.
3. **Public/guest exam-taking** (this spec) — anonymous users can take an
   exam and see a result; nothing persists unless the user is logged in.
4. Management frontend class-wise filter fixes.
5. New public student frontend — depends on (2) and (3).

Each sub-project gets its own spec → plan → implementation cycle.

## Scope of this spec

Let anyone — authenticated or not — browse published exams and attempt
one, entirely without creating any database row, and see a graded result
immediately. The existing authenticated flow (`ExamAttemptController`'s
`start`/`saveAnswer`/`submit`, which persists `ExamAttempt`/
`ExamAttemptQuestion`/`ExamAttemptAnswer` rows and is resumable/timed) is
**unchanged** — that is the "if logged in, track it" path the brief asks
for, and it already works today. Backend only — no frontend work (same
precedent as sub-projects 1-2; the public browsing UI is sub-project 5's
job, consuming the API this spec adds).

## Current state vs. target

Today, `exams` has **no public route at all** — `Route::apiResource('exams',
ExamController::class)` sits entirely inside the `auth:sanctum` group, so
even listing or viewing a single exam requires `exams.view`. The attempt
flow is deeply tied to a `StudentUser` identity: `ExamAttemptController::
start()` calls `$request->user()->profile` directly with no null-safety for
a guest, `ExamAttemptPolicy`/`SaveExamAttemptAnswerRequest` both assert
`$user instanceof StudentUser`, and every write persists immediately
(`saveAnswer()` upserts an `ExamAttemptAnswer` per question as the student
answers, not in one batch at the end).

**Decisions confirmed during brainstorming:**

- Exam browsing becomes public too (`GET /exams`, `GET /exams/{exam}`),
  matching the precedent already set for books/chapters/videos in
  sub-project 1 — filtered to `status = 'published'` and within the
  `available_from`/`available_until` window for any viewer without
  `exams.view`; unchanged (everything visible) for a viewer who has it.
- For `per_attempt_random` exams (the one mode with no fixed question
  list — `manual` and `fixed_random` both have a populated `exam_questions`
  pivot already), the server draws a fresh random set and returns it; the
  client echoes the same question IDs back at submission time so the
  server can re-fetch and grade exactly those questions. No signed token,
  no server-side state of any kind. A guest tampering with the echoed IDs
  in devtools only cheats themselves — nothing is ever recorded, so there
  is no official result to falsify. (This is the same trust model already
  implicit in a client-side-only "practice quiz.")

Target: three new things, nothing else touches the authenticated flow:
1. `exams.view` is no longer required for `index`/`show` — it degrades to
   a status/availability filter instead of a hard 403/empty result.
2. `POST /exams/{exam}/random-draw` — draws and returns a fresh random
   question set for a `per_attempt_random` exam (no persistence).
3. `POST /exams/{exam}/guest-submissions` — grades a submitted answer set
   against real correctness data and returns a result object (no
   persistence, no `ExamAttempt` row, nothing written anywhere).

## API surface

### `ExamController::index()`/`show()` — degrade, don't gate

Remove the blanket `$this->authorize('viewAny', Exam::class)` /
`$this->authorize('view', $exam)` calls (a `null` actor can never pass an
`authorize()` call, so these must go, not be conditioned). Replace with:

- `index()`: add `->when(! $request->user()?->hasPermission('exams.view'),
  fn ($query) => $query->where('status', 'published')->where(fn ($q) =>
  $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
  ->where(fn ($q) => $q->whereNull('available_until')->orWhere(
  'available_until', '>=', now())))` to the existing query chain. Keep the
  existing `?class_version_id=`/`?subject_id=`/`?academic_year_id=` filters
  unchanged.
- `show()`: `abort_unless($request->user()?->hasPermission('exams.view')
  || $exam->isPubliclyTakeable(), 404);` before building the response.

New method on the `Exam` model, `isPubliclyTakeable(): bool` — extracted
from the three checks `ExamAttemptController::start()` already does
inline (`status !== 'published'`, `available_from` in the future,
`available_until` in the past), so there is exactly one place this rule
lives, reused by `show()`, the two new endpoints below, AND
`ExamAttemptController::start()` (replace its three `if` blocks with one
call to this method, same validation messages/behavior, pure refactor —
no behavior change for the authenticated flow).

`TenantScope` requires no change: it already returns unrestricted for an
unauthenticated request (per its own docblock), so opening these routes
means a guest sees every school's published exams nationally — consistent
with how books/chapters/videos already behave for a guest.

### New `GuestExamAttemptController`

A new, separate controller — this is a conceptually distinct, fully
stateless flow, not a branch inside the existing stateful
`ExamAttemptController`.

**`POST /exams/{exam}/random-draw` → `randomDraw(Exam $exam)`**

```
abort_unless($exam->isPubliclyTakeable(), 404);

if ($exam->selection_mode !== 'per_attempt_random') {
    422 ValidationException: "This exam does not use random question selection."
}

$eligible = $exam->eligibleRandomQuestionIds();
if ($eligible->count() < $exam->random_question_count) {
    422 ValidationException (same message shape as ExamAttemptController::start()'s
    identical check — "Only N eligible question(s) are available, but this
    exam requires M.")
}

$questionIds = $eligible->shuffle()->take($exam->random_question_count)->values();
$questions = Question::with('options')->whereIn('id', $questionIds)->get();

return QuestionResource::collection($questions);
```

`QuestionResource`/`QuestionOptionResource` need no changes — `is_correct`
is already hidden from any viewer without `questions.update`
(`$request->user()?->hasPermission('questions.update') ?? false`), which
is automatically `false` for a null (guest) user. This same protection is
why `manual`/`fixed_random` exams need no equivalent endpoint: their
question list is already reachable through the now-public
`show()` → `ExamResource` → `ExamQuestionResource` → `QuestionResource`
chain, with the same automatic protection.

**`POST /exams/{exam}/guest-submissions` → `submit(Exam $exam, SubmitGuestExamAttemptRequest $request)`**

```
abort_unless($exam->isPubliclyTakeable(), 404);

$answers = collect($request->validated('answers'))->keyBy('question_id');
$submittedIds = $answers->keys()->unique()->values();

if ($exam->selection_mode === 'per_attempt_random') {
    $eligible = $exam->eligibleRandomQuestionIds();

    if ($submittedIds->diff($eligible)->isNotEmpty()) {
        422: "One or more submitted questions do not belong to this exam."
    }
    if ($submittedIds->count() > $exam->random_question_count) {
        422: "This exam only allows {random_question_count} question(s) per attempt."
    }

    $gradeIds = $submittedIds; // only what was actually shown/echoed back
} else {
    $allowed = $exam->questions()->pluck('questions.id');

    if ($submittedIds->diff($allowed)->isNotEmpty()) {
        422: "One or more submitted questions do not belong to this exam."
    }

    $gradeIds = $allowed; // the exam's full static list — grade every
                          // question whether answered or not, same as
                          // the authenticated finalize() does
}

$questions = Question::with('options')->whereIn('id', $gradeIds)->get()->keyBy('id');

$score = 0; $total = 0; $graded = [];
foreach ($gradeIds as $questionId) {
    $question = $questions[$questionId];
    $marks = $exam->selection_mode === 'per_attempt_random'
        ? $exam->marks_per_question
        : $question->marks;
    $selectedOptionIds = $answers[$questionId]['selected_option_ids'] ?? [];
    $isCorrect = ExamGrading::isCorrect($question, $selectedOptionIds);
    $marksAwarded = $isCorrect ? $marks : 0;

    $total += $marks;
    $score += $marksAwarded;

    $graded[] = [
        'question_id' => $question->id,
        'selected_option_ids' => $selectedOptionIds,
        'is_correct' => $isCorrect,
        'marks_awarded' => $marksAwarded,
        'marks' => $marks,
        'correct_option_ids' => $question->options->where('is_correct', true)->pluck('id')->values()->all(),
    ];
}

$passed = ($exam->passing_score_percent !== null && $total > 0)
    ? (($score / $total) * 100) >= $exam->passing_score_percent
    : null;

return response()->json([
    'exam_id' => $exam->id,
    'score' => $score,
    'total_possible_marks' => $total,
    'passed' => $passed,
    'answers' => $graded,
]);
```

The `per_attempt_random` vs. other-modes split in choosing `$gradeIds`
matters: a `per_attempt_random` guest is only ever graded on the specific
N questions it echoes back (the ones it was actually shown), never the
full eligible pool (which could be far larger than N) — that split exists
specifically to prevent a guest inflating `total_possible_marks` by
submitting more question IDs than were ever drawn for it. A
`manual`/`fixed_random` guest, by contrast, has one fixed list every time,
so every question in it is graded whether answered or not (an omitted
question is wrong but still counts toward `total_possible_marks`) — this
matches `ExamAttempt::finalize()`'s existing behavior for the authenticated
path exactly.

**`SubmitGuestExamAttemptRequest`** (new):
```php
public function authorize(): bool { return true; } // public endpoint, no identity to check

public function rules(): array
{
    return [
        'answers' => ['required', 'array'],
        'answers.*.question_id' => ['required', 'integer'],
        'answers.*.selected_option_ids' => ['present', 'array'],
        'answers.*.selected_option_ids.*' => ['integer'],
    ];
}
```

Revealing `correct_option_ids` in the response is deliberate: once a
submission is graded, nothing is protecting the answer anymore (the guest
already committed their choices), and showing what was actually correct
is the expected, useful behavior of a practice quiz — this is not a
leak, it's the point of the feature.

### Shared grading logic: `app/Support/ExamGrading.php` (new)

Extracted from `ExamAttempt::answerIsCorrect()`'s private logic, which
becomes a one-line delegation to this:

```php
class ExamGrading
{
    /**
     * @param  array<int, int>  $selectedOptionIds
     */
    public static function isCorrect(Question $question, array $selectedOptionIds): bool
    {
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selectedIds = collect($selectedOptionIds)->sort()->values()->all();

        return $correctIds === $selectedIds;
    }
}
```

`ExamAttempt::answerIsCorrect()` becomes:
```php
private function answerIsCorrect(ExamAttemptQuestion $attemptQuestion, ExamAttemptAnswer $answer): bool
{
    return ExamGrading::isCorrect($attemptQuestion->question, $answer->selected_option_ids ?? []);
}
```
Same inputs, same outputs, pure refactor — the authenticated flow's
behavior does not change. This is the one correctness rule both the
tracked and untracked paths share, and it must not be allowed to drift
into two implementations.

## Routes

```
# Public group (no auth:sanctum)
Route::apiResource('exams', ExamController::class)->only(['index', 'show']);
Route::post('/exams/{exam}/random-draw', [GuestExamAttemptController::class, 'randomDraw']);
Route::post('/exams/{exam}/guest-submissions', [GuestExamAttemptController::class, 'submit']);

# auth:sanctum group (unchanged mutating verbs, moved from the old single apiResource call)
Route::apiResource('exams', ExamController::class)->only(['store', 'update', 'destroy']);
Route::patch('/exams/{exam}/questions', [ExamController::class, 'setQuestions']);
Route::patch('/exams/{exam}/random-chapters', [ExamController::class, 'setRandomChapters']);
Route::post('/exams/{exam}/randomize', [ExamController::class, 'randomize']);

# Existing tracked-attempt routes — entirely unchanged, still inside auth:sanctum
Route::post('/exams/{exam}/attempts', [ExamAttemptController::class, 'start']);
Route::get('/exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
Route::get('/exam-attempts/{exam_attempt}', [ExamAttemptController::class, 'show']);
Route::put('/exam-attempts/{exam_attempt}/answers/{question}', [ExamAttemptController::class, 'saveAnswer']);
Route::post('/exam-attempts/{exam_attempt}/submit', [ExamAttemptController::class, 'submit']);
```

## Permissions

None added, none removed. The two new endpoints are intentionally
unauthenticated with no policy check (`SubmitGuestExamAttemptRequest::
authorize()` returns `true` unconditionally, matching the "public,
no identity" shape — there is nothing to check an identity against).
`exams.view`'s existing role grants are unchanged; it now means "see
unpublished/not-yet-available/expired exams too" rather than "see exams
at all."

## Testing

- `ExamControllerTest` (existing, updated) — add: an unauthenticated
  request can list/view a published, currently-available exam; cannot see
  a draft, a not-yet-available, or an expired one; an authenticated actor
  with `exams.view` still sees everything (regression).
- `GuestExamAttemptControllerTest` (new):
  - `random-draw` on a `per_attempt_random` exam returns exactly
    `random_question_count` questions, `is_correct` absent/hidden from
    every option, two consecutive calls can return different sets
    (random, not cached).
  - `random-draw` on a `manual`/`fixed_random` exam is rejected (422).
  - `guest-submissions` on a `manual` exam: submitting correct/incorrect
    answers returns the right `score`/`total_possible_marks`/`passed`
    and the right `correct_option_ids` per question; an omitted question
    counts as wrong but still contributes to `total_possible_marks`;
    **no `ExamAttempt`/`ExamAttemptQuestion`/`ExamAttemptAnswer` row exists
    in the database afterward** (`assertDatabaseCount` on all three, 0).
  - `guest-submissions` on a `per_attempt_random` exam: submitting a
    question ID outside the eligible pool is rejected (422); submitting
    more distinct question IDs than `random_question_count` is rejected
    (422); a legitimate submission grades correctly and, again, persists
    nothing.
  - `guest-submissions`/`random-draw` on a draft/not-yet-available/expired
    exam both 404.
  - A request with a valid `Authorization` header (an authenticated
    student) can also hit both endpoints successfully and nothing is
    persisted either — these routes don't care about auth state one way
    or the other, they just never write.
- `ExamAttemptControllerTest`/`ExamAttemptGradingTest`/`ExamAttemptTenantScopeTest`
  (existing, run unmodified) — confirm the authenticated flow's behavior
  is byte-for-byte unchanged after the `isPubliclyTakeable()`/
  `ExamGrading::isCorrect()` refactors (pure extraction, same logic).
- A unit test for `ExamGrading::isCorrect()` directly (single-correct,
  multi-correct, true/false, no selection, extra/wrong selection) — this
  rule is now shared by two call sites and deserves its own direct
  coverage rather than only being exercised indirectly through each.

## Out of scope for this sub-project

- Any frontend change (management or student) — deferred to sub-project 5.
- Rate limiting / abuse throttling on the new public endpoints — this app
  has no existing `throttle` middleware convention anywhere to extend
  consistently; worth a follow-up once a broader rate-limiting policy
  exists for this app's public endpoints generally (books/chapters/videos
  already share this same unaddressed exposure), not something to
  introduce ad hoc for just these two routes.
- Any change to `exams`'/`exam_attempts`' schema, or to `question_options`,
  `exam_questions`, `exam_random_chapters` — none are touched.
- Guest result persistence of any kind (local storage, cookies, etc.) —
  explicitly out of scope per the brief ("no store exam result data");
  anything client-side is sub-project 5's concern, not this API's.

## Definition of done

- `GET /exams`/`GET /exams/{exam}` work without authentication, filtered
  to published + currently-available exams for any viewer without
  `exams.view`; unchanged for a viewer who has it.
- `Exam::isPubliclyTakeable()` exists and is the single place this rule
  lives; `ExamAttemptController::start()` uses it instead of its three
  inline checks, with no behavior change.
- `POST /exams/{exam}/random-draw` and `POST /exams/{exam}/guest-submissions`
  exist, are public, and create zero database rows under any
  circumstance, verified by tests that explicitly assert zero rows in all
  three attempt-related tables after a guest submission.
- `app/Support/ExamGrading.php` exists; `ExamAttempt::answerIsCorrect()`
  delegates to it; the authenticated exam-attempt test suite passes
  unmodified.
- Full backend test suite passes against MySQL (per this project's
  documented SQLite/MySQL migration-behavior gaps — though this
  sub-project adds no migrations, the final verification task still runs
  the full suite against MySQL for consistency with sub-projects 1-2).
