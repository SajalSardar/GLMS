# Design: Student Learning Dashboard

**Status:** Approved, ready for implementation planning
**Sub-project:** 6 of 6 — the last sub-project in this program (see
`docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md`
"Program decomposition"; depends on sub-project 2's `ChapterVideoProgress`/
`ContentAccessLog` and sub-project 5's `ExamAttempt`)

## Scope of this sub-project

A read-only endpoint rolling up one student's progress — video-watching,
material access, and exam results — per chapter and per subject, for
their current class-version/academic-year enrollment.

**Explicitly narrower than the inspiration document's broader dashboard
material.** `inspiration/AI_Teacher_Training_Proposal_v2.md` §4.6/§4.7/§7
describe a multi-level (student/teacher/school/district/national),
Redis-cached, weighted-competency-scoring dashboard system for the whole
platform — none of that is this sub-project. The content-hierarchy
program's own decomposition scopes this sub-project to exactly one
thing: a **student-facing** rollup across the three content types this
program already built (materials, video, exams). No caching layer (no
Redis/background-job infrastructure exists anywhere in this codebase
yet, confirmed repeatedly across prior sub-projects), no multi-level
school/district/national aggregation, no weighted single-number
"competency score."

## Decisions confirmed during brainstorming

- **Raw independent signals, not a blended score.** Each chapter reports
  `video_total`/`video_completed` and `materials_total`/
  `materials_accessed` side by side. Each subject additionally reports
  its exam list. No invented weighting formula combines these into one
  number — the inspiration doc's weighted-competency model (§7) is for
  teacher assessment, not this student content-progress view, and has no
  basis for reuse here.
- **Structure matches the actual data model**, not a forced symmetry:
  video/materials nest per chapter (since `ChapterVideo`/`ChapterMaterial`
  are chapter-scoped); exams nest per subject (since `Exam` is scoped to
  `(ClassVersion, Subject, AcademicYear)` directly, pulling questions from
  across every chapter under that subject — sub-project 4's own design).
- **Computed on-the-fly, no new table.** A single student's dashboard is
  a bounded query over their own class-version's content tree, not a
  cross-school aggregate — nothing here needs the caching the inspiration
  doc recommends for district/national views.
- **Visibility is the same 3-way pattern sub-project 5 established for
  exam attempts**: the student's own dashboard, their linked guardian's
  (a real relationship check), or a new `student_progress.view` permission
  held by the same management/oversight role list as `exam_attempts.view`
  (`national_admin`, `district_officer`, `school_admin`, `trainer`,
  `teacher`).
- **A dedicated new Policy ability, not a reuse of the existing
  `StudentProfilePolicy::view()`.** `view()` is gated purely on the flat
  `student_profiles.view` permission — and the `student` role **already
  holds that permission** (confirmed in `RolePermissionSeeder.php`),
  meaning any student can currently view any other student's basic
  profile within their tenant via the existing `GET /student-profiles/{id}`
  route. Reusing `view()` for the dashboard would let a student see a
  classmate's exam scores and video-watching history — the exact
  permission-mismatch class the question-bank sub-project's CSV-export
  incident already taught this program to watch for. The dashboard gets
  its own `viewDashboard()` ability with the correct self/guardian/
  permission check, added to the existing `StudentProfilePolicy` class
  (not a new policy file — `StudentProfile` is already the right model to
  hang this ability off).
- **No `/me/dashboard` shortcut.** `GET /student-profiles/{student_profile}/dashboard`
  only — the student already needs their own profile context elsewhere in
  the app (same precedent as exam attempts: the student already knows
  their own attempt id from starting it, no convenience alias was added
  there either).
- **A missing section assignment degrades gracefully, not an error.**
  If `$profile->section` is null (never assigned to a section yet), the
  endpoint returns 200 with `class_version_id`/`academic_year_id` both
  `null` and an empty `subjects` array — this is a read-only informational
  view, not a mutating action with hard preconditions like sub-project 5's
  `start()`.
- **Every subject assigned to the student's class-version is included**,
  even one with zero chapters/exams yet — this reports what's assigned,
  not just what currently has content.

## Computation

All of the following scope to the student's current enrollment, resolved
via the same chain sub-project 5 established:
`StudentProfile->section->shift` gives `class_version_id`/`academic_year_id`.

For each `Subject` in `ClassVersion::subjects()` (the `version_subjects`
pivot, already existing):

**Chapters** — for every `Chapter` reachable through an **active**
`Book` under this `(class_version_id, subject_id)` pair:
- `video_total` = count of that chapter's `ChapterVideo` rows with
  `is_enabled = true`.
- `video_completed` = count of those videos with a `ChapterVideoProgress`
  row for this student where `status = 'completed'`.
- `materials_total` = count of that chapter's `ChapterMaterial` rows with
  `status = 'active'`.
- `materials_accessed` = count of **distinct** `chapter_material_id`
  values among those materials that appear in `ContentAccessLog` for
  this student (any `action` — `downloaded` or `previewed` — counts as
  "accessed"; no partial-read signal exists in this codebase, so a
  binary "has an access log row" is the honest signal available).

Bulk-query per subject (not per-chapter N+1): gather every chapter id
under the subject first, then one query each for videos, progress,
materials, and access logs, grouping the results back onto chapters in
memory.

**Exams** — for every `published` `Exam` matching this exact
`(class_version_id, subject_id, academic_year_id)`:
- `attempts_used` = count of ALL `ExamAttempt` rows for this student on
  this exam, `in_progress` or `submitted` alike (this is what counts
  against `max_attempts`).
- `best_score`/`total_possible_marks` = from the highest-`score`
  **`submitted`** attempt only — an `in_progress` attempt never
  contributes a score (it has none yet). `null`/`null` if no submitted
  attempt exists.
- `passed` = `true` if **any** submitted attempt has `passed = true`,
  `false` if at least one submitted attempt exists and none passed,
  `null` if no submitted attempt exists yet. (Not tied to whichever
  attempt has the best score — "has this student ever demonstrated
  passing competency on this exam" is the question, independent of which
  specific attempt scored highest.)

## API

```
GET /student-profiles/{student_profile}/dashboard
```

A new action on the **existing** `StudentProfileController` (not a new
controller) — same precedent as `attachGuardian`/`transferSection` already
living there as custom actions on this resource. Behind `auth:sanctum`
(the whole `student-profiles` resource already is).

Response shape:

```json
{
  "data": {
    "student_profile_id": 1,
    "class_version_id": 3,
    "academic_year_id": 2,
    "subjects": [
      {
        "subject_id": 5,
        "subject_name": "Mathematics",
        "chapters": [
          {
            "chapter_id": 10,
            "chapter_name": "Chapter 1",
            "video_total": 3,
            "video_completed": 2,
            "materials_total": 2,
            "materials_accessed": 1
          }
        ],
        "exams": [
          {
            "exam_id": 7,
            "exam_name": "Midterm",
            "attempts_used": 1,
            "max_attempts": 2,
            "best_score": 8,
            "total_possible_marks": 10,
            "passed": true
          }
        ]
      }
    ]
  }
}
```

- `App\Support\StudentDashboardBuilder` — a pure support class (no HTTP
  concerns), same precedent as `YoutubeUrl`/`QuestionCsvImporter`: one
  public method, `build(StudentProfile $profile): array`, returning
  exactly the `data` shape above (everything *inside* the `"data"` key —
  the controller wraps it). Independently unit-testable without any HTTP
  layer.
- `StudentProfileController::dashboard(StudentProfile $student_profile): JsonResponse`
  — `$this->authorize('viewDashboard', $student_profile)`, then
  `response()->json(['data' => $builder->build($student_profile)])`. No
  dedicated Resource class — the payload is a synthetic aggregate, not a
  1:1 transform of a single Eloquent model, so a hand-built array matches
  this codebase's existing convention better than forcing a `JsonResource`
  subclass around something that isn't really "a model."
- `StudentProfilePolicy::viewDashboard(User $user, StudentProfile $profile): bool`
  — `true` if `$user->id === $profile->user_id`; else `true` if
  `$user->hasPermission('student_progress.view')`; else `true` if
  `$user->guardian !== null && $user->guardian->students()->where('student_profiles.id', $profile->id)->exists()`;
  else `false`.

## Permissions

New: `student_progress.view` — granted to exactly the same role list as
`exam_attempts.view`: `national_admin`, `district_officer`,
`school_admin`, `trainer`, `teacher`. No role gets a `student_progress.*`
create/update/delete — this is a read-only rollup, there's nothing to
mutate.

## Testing

- `StudentDashboardBuilderTest` (unit, no HTTP) — a student with a mix of
  completed/incomplete videos, accessed/unaccessed materials, and
  submitted/in-progress/no exam attempts produces the exact expected
  counts; a subject with zero chapters/exams still appears with empty
  arrays; an inactive `Book`'s chapters are excluded; a disabled
  `ChapterVideo`/archived `ChapterMaterial` is excluded from its totals;
  an unpublished `Exam` is excluded.
- `StudentDashboardControllerTest` (feature) — the student's own
  dashboard is visible; a linked guardian's is visible; an unrelated
  student's is forbidden; a `trainer`/`teacher` with
  `student_progress.view` can view any student's dashboard in their
  tenant; the same roles' lack of `student_profiles.view` doesn't block
  them (proving `viewDashboard` is genuinely independent of `view`); a
  student with no section assigned gets a 200 with empty `subjects` and
  null `class_version_id`/`academic_year_id` rather than an error.
- `DatabaseSeederTest` — assert a `student_progress.view` permission seed
  exists.

## Out of scope for this sub-project

- Teacher/school/district/national-level dashboards (inspiration doc
  §4.6/§4.7) — a different, much larger feature than this program ever
  decomposed into one of its six sub-projects.
- Any blended/weighted competency score (§7's model) — belongs to a
  teacher-assessment feature this program never includes.
- Caching, scheduled refresh, or background recomputation — no
  infrastructure exists for it, and nothing here needs it at this scale.
- Historical trend charts, time-series progress, or period-over-period
  comparison — this is a current-snapshot rollup only.
- A `/me/dashboard` convenience alias.

## Definition of done

- `App\Support\StudentDashboardBuilder::build()` produces exactly the
  response shape above, computed on-the-fly with no new table.
- `GET /student-profiles/{student_profile}/dashboard` works as specified.
- `StudentProfilePolicy::viewDashboard()` is a genuinely new ability
  (self/guardian/`student_progress.view`), never delegating to the
  existing `view()` ability.
- `student_progress.view` permission seeded and mapped exactly to
  `national_admin`/`district_officer`/`school_admin`/`trainer`/`teacher`.
- Inactive books, disabled videos, archived materials, and unpublished
  exams are excluded from every count.
- A student with no section assignment gets a graceful empty response,
  not an error.
- All tests in "Testing" above pass.
