# Design: Public Student Frontend

**Status:** Approved, ready for implementation planning
**Sub-project:** 5 of 5 (final sub-project in this program)

## Program decomposition (context for this spec)

Carried from `docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md`'s
own program decomposition (sub-projects 1-4, already merged):

1. De-scope national content + remove video progress — **done, merged**.
2. Restructure `school_classes` into a national list + per-school
   assignment (`class_schools`) — **done, merged**.
3. Public/guest exam-taking — **done, merged**.
4. Management frontend class-wise filter fixes — **done, merged**.
5. **New public student frontend** (this spec) — depends on (2) and (3).

This is the final sub-project in the program.

## Scope of this spec

Build out `frontend/apps/student` into a working public learning site:
browse and download class/subject-wise books, watch chapter videos, and
take exams — all usable by an anonymous guest, with results tracked only
when the visitor is a logged-in student. The app already has a working,
Tailwind-capable landing/login page and nothing past login
(`DashboardPlaceholder` is a trivial health-check stub). This sub-project
replaces that stub with the actual product and rewrites the existing
landing page to use Tailwind utility classes instead of its current
hand-written CSS, for consistency with everything built from here on.

## Current state vs. target

**Already in place (confirmed by reading the code, not assumed):**
- `frontend/apps/student/vite.config.ts` already has the `@tailwindcss/vite`
  plugin configured — Tailwind's build-time wiring is done. What's missing
  is actually writing components with Tailwind utility classes; the one
  existing page (`LandingLogin.tsx`) instead uses its own
  `LandingLogin.css` (BEM-style classes), predating this plugin's use.
- `LandingLogin.tsx`/`.css` is a complete, polished page (hero, login
  card, feature badges, primary/secondary/SSC/HSC class cards, subjects
  grid, stats, footer, all in Bangla via `getCommonStrings('bn')`) that
  already closely matches `inspiration/login&frontend.jpeg`'s structure
  and content. **Decision confirmed during brainstorming:** rewrite it in
  Tailwind utility classes, same visual output, same copy, same
  structure — not a redesign, a technology swap for consistency with
  every page this spec adds.
- `@ttla/ui`'s `AppShell`/`Sidebar` components exist and are already
  Tailwind-styled, but are a sidebar-dashboard pattern (used by the
  management app) — **not reused here**, per the brainstorming decision
  below on site structure.
- The backend already has everything else this spec needs, fully public,
  already merged: `GET /books`, `/chapters`, `/chapter-videos`,
  `/subjects`, `/classes`, `/class-versions` (all `index`/`show`, no
  auth), `GET /exams` (degrades to published+available for any viewer
  without `exams.view`), `POST /exams/{exam}/random-draw` and
  `POST /exams/{exam}/guest-submissions` (fully stateless, sub-project
  3). The authenticated exam-attempt flow (`POST /exams/{exam}/attempts`,
  `PUT /exam-attempts/{attempt}/answers/{question}`,
  `POST /exam-attempts/{attempt}/submit`) is also already in place,
  unchanged by this spec.
- `frontend/packages/api-client/src/index.ts` has **zero** existing
  functions/types for `Book`, `Chapter`, `ChapterVideo`, `Exam`,
  `ExamAttempt`, or guest exam attempts — confirmed by grep. All of this
  is new.

**One real backend gap, found while researching this spec (not obvious
from the request alone):** generating a book's PDF download link
(`POST /books/{book}/pdf/download-link`) and preview link currently sits
inside the `auth:sanctum` middleware group — confirmed in
`backend/routes/api.php:115-121`. The signed URL it produces is itself
public (`GET /books/{book}/pdf/download`/`preview` only require the
`signed` middleware, confirmed at `routes/api.php:169-174`), but a guest
can never *obtain* that signed URL today. This contradicts "download a
book without login." **Decision confirmed during brainstorming:** fix
this with a small, scoped backend change — make `pdfDownloadLink`
public, degrading the same way the already-public content-browsing
endpoints do (no `auth:sanctum` wrapper; the method already checks
`hasAvailablePdf()`/the book's own published state before issuing a
link, so no new authorization logic is needed, only removing the route's
auth requirement). `ChapterMaterialController::downloadLink` is NOT
touched — the original request only asks for book downloads, and
chapter materials are a separate, not-requested feature.

**Second finding worth documenting precisely, since it drives the exam
page's design:** the authenticated `ExamAttemptController::start()`
(confirmed by reading the full method) enforces that the logged-in
student's own enrollment — `$studentProfile->section->shift->class_version_id`
and `academic_year_id` — must match the exam's `class_version_id`/
`academic_year_id`, or it 422s with "You are not enrolled in the class
version/academic year this exam belongs to." This means a logged-in
student can browse and start-as-tracked ONLY exams belonging to their
own class; for every other exam (including the common case of a
logged-in student exploring other classes' exams out of curiosity), the
authenticated flow is unusable even though they're logged in. **Decision
for this spec:** the frontend transparently falls back to the guest flow
whenever the authenticated `start()` call fails specifically with this
enrollment-mismatch validation error — so "take any exam" always works
for everyone, and tracking happens automatically whenever it can
(matching the original request's "take the exam... but if login student
then track exam result" exactly: tracking is a bonus when eligible, not
a requirement to gate the feature on).

**Detection mechanism, stated precisely since it's inherently a bit
fragile:** `start()`'s enrollment check is the only one of its several
`ValidationException` branches that should trigger the guest-flow
fallback (the others — exam not available, max attempts used, random
pool too small — are real errors a fallback would wrongly paper over).
All of them share the same `exam` field key, so distinguishing requires
matching the exact message text: "You are not enrolled in the class
version/academic year this exam belongs to." (verbatim from
`ExamAttemptController::start()` at the time of writing). The
implementation plan must pull this string from the actual backend
source at plan-writing time, not retype it from this spec, and should
leave a comment at the frontend match site pointing back at the exact
backend line — so a future wording change to that message is at least
visible as a grep hit, even though it can't be made compile-time safe
without a backend change this spec doesn't otherwise need.

## Decisions confirmed during brainstorming

- **Landing page rewritten in Tailwind**, not left as hand-written CSS (see above).
- **One shared public site shell, top nav** — not a sidebar dashboard.
  Books/Videos/Exams become top-level routes (`/books`, `/videos`,
  `/exams`) inside one consistent top-nav layout matching the reference
  image's visual style, usable identically before and after login. The
  existing landing page becomes the `/` route within this same shell
  (its hero/login section stays, its nav becomes the shared site nav).
  Logging in adds a profile/logout control to the nav and silently
  upgrades exam attempts to tracked ones where enrollment allows; it
  does not switch the visitor into a different UI.
- **Backend fix included in this sub-project**, not deferred (see above).

## Information architecture

```
/                    — home: hero + login card (rewritten LandingLogin), feature sections
/books               — class/subject-wise book search & list
/books/:id           — book detail: chapter list, PDF preview/download
/videos              — class/subject-wise chapter video browsing (filter by class/subject,
                        list of chapters each with their videos)
/videos/:chapterId    — a chapter's video playlist (list of its videos + an inline player
                        for the selected one)
/exams                — exam list (published + available; the same list a guest or a
                        logged-in student sees — no separate "my exams" view, since
                        start() itself degrades to the guest flow where enrollment
                        doesn't match)
/exams/:id            — take-exam flow: question set → answer → submit → result,
                        all on one route (state machine within the page, not sub-routes,
                        since there's no bookmarkable mid-exam state worth a URL)
```

All five top-level routes are public (no `RequireAuth` wrapper). The
existing `/dashboard` route and `RequireAuth`/`DashboardPlaceholder` are
removed entirely — there is no separate authenticated-only area; being
logged in only changes behavior within the same public pages (nav
contents, exam tracking).

## Shared site shell

A new `SiteShell` component (top nav + footer, replacing `AppShell` for
this app) wraps all five routes via a layout route. Nav links: Home,
Books, Videos, Exams — plus, on the right, either a "Sign in" link
(`→ /` and scroll to the login card, matching the existing landing
page's own `#login` anchor convention) or the student's name + a logout
button, depending on `useAuth()`'s `token`. Visual language (colors,
icon style, spacing) carries over from the rewritten landing page's
Tailwind palette, so the whole site reads as one product, not a landing
page bolted onto a separate app shell.

## Content source across schools

**Decision confirmed during brainstorming, resolving a finding made
while researching this spec:** `Book.class_version_id` still points at
a `ClassVersion`, and `ClassVersion` is still per-school (`TenantScoped`,
unchanged by sub-project 1 or 2 — sub-project 1's own brainstorming
explicitly chose to keep this parent key rather than restructure it).
There is no single national "the" book per class+subject — each school
that has authored content has its own separate books/chapters/videos
under its own class-versions, and no seed data designates a reference
school. The public site shows content from **every** school, merged by
Class + Subject, with no school picker in the UI. The fetch shape this
implies, since the backend has no single endpoint for "all books for
this class across every school": resolve every `ClassVersion` matching
the chosen `class_id` via `GET /class-versions?class_id=` (already
returns every school's version of that class — confirmed it filters by
`class_id` alone, not school-scoped), then fetch
`GET /books?class_version_id=&subject_id=` once per returned version ID
and merge the results client-side. This is a real N+1 from the
frontend's perspective, not a mistake to optimize away in this spec —
it's the direct, honest consequence of the chosen cross-school merge
semantics against the backend's current shape, and the typical case (a
handful of schools' versions per class) keeps it cheap. When two merged
results share the same book name (the common case — most schools
author similarly-named content for a given class+subject), show both
with a school label so the duplication is visible rather than silently
collapsed.

## Books feature

- `/books`: a filter bar (class, subject — populated from the already-
  public `GET /classes`/`GET /subjects`) plus a search input, using the
  cross-school resolve-and-merge strategy above. Each result card shows
  the book's name, subject, owning school's name (so visible duplicates
  across schools are distinguishable), and whether a PDF is available
  (`has_available_pdf`).
- `/books/:id`: fetches `GET /books/{id}` (confirm during planning
  whether `BookController::show()`'s `['classVersion', 'subject']`
  eager-load already includes chapters, or whether a separate
  `GET /chapters?book_id=` call is needed — the controller code should
  settle this exactly, not a guess here), lists its chapters in order,
  and offers "Preview" / "Download" actions when `has_available_pdf` is
  true. Both actions first call the (now-public, per the backend fix)
  `POST /books/{book}/pdf/download-link` to get a signed URL, then
  navigate to it (preview) or trigger a browser download (download) —
  no separate "download" button logic beyond that, since the signed URL
  itself handles the actual file serving.

## Videos feature

- `/videos`: same class/subject filter and cross-school merge pattern as
  Books, but one level deeper — confirmed `GET /chapters` only filters
  by `book_id` (no direct class/subject param) and `GET /chapter-videos`
  only filters by `chapter_id`, so there is no shortcut: resolve
  class-versions → books (as above) → `GET /chapters?book_id=` per
  resolved book → keep only chapters that have at least one video. A
  chapter with videos shows up as a card (labeled with its book's name
  and owning school, same reasoning as Books); clicking one goes to
  `/videos/:chapterId`.
- `/videos/:chapterId`: `GET /chapter-videos?chapter_id={id}`, ordered by
  `order_index`. A playlist sidebar/list (title, duration, instructor)
  next to a main area showing the selected video's `embed_url` in an
  `<iframe>` (YouTube embed — confirmed this is already how the backend
  represents video content, no custom player needed). Clicking another
  item in the list swaps the iframe's `src`; no video-progress tracking
  of any kind (sub-project 1 already removed `chapter_video_progress`
  entirely — this spec does not reintroduce it).

## Exams feature

- `/exams`: `GET /exams` (already degrades correctly for a guest or any
  viewer without `exams.view`), rendered as a list with name, subject,
  class, and availability window. No filter bar is required by the
  original request, but the same class/subject filter pattern used
  elsewhere in this spec (Books/Videos) should be offered for
  consistency if the plan finds it cheap to add — not a hard requirement.
- `/exams/:id` — the take-exam flow, as one page with three states:
  1. **Setup**: fetch `GET /exams/{id}` for `manual`/`fixed_random`
     (question set already included, `is_correct` already stripped) OR
     call `POST /exams/{id}/random-draw` for `per_attempt_random`
     (fresh guest-safe draw). Separately, if `token` is present, attempt
     `POST /exams/{id}/attempts` (the authenticated, tracked start) —
     on success, use ITS returned question set instead (works for all
     three selection modes uniformly, since the backend's `start()`
     already handles the random draw server-side) and remember the
     returned `attempt.id` for later. On a 422 specifically matching the
     enrollment-mismatch message, treat this exactly like the
     not-logged-in case (fall through to the guest-safe fetch above) —
     this is the fallback decided during brainstorming.
  2. **In progress**: render each question (text, options, single/multi
     select per `question_type`) and keep answers in local component
     state. If an authenticated attempt exists (`attempt.id` was
     captured above), call `PUT /exam-attempts/{attempt.id}/answers/{question_id}`
     on each answer change (fire-and-forget is acceptable — this
     mirrors the authenticated flow's own existing save-as-you-go
     design) so a refresh doesn't lose progress; if there's no
     authenticated attempt (guest path), answers only ever live in
     local state until the final submit.
  3. **Submitted**: on the user's final submit action, call
     `POST /exam-attempts/{attempt.id}/submit` (authenticated path) or
     `POST /exams/{id}/guest-submissions` with the full locally-held
     answers array (guest path) — both return a result shape (score,
     total possible marks, pass/fail, per-question correctness) that
     this state renders identically regardless of which path produced
     it. Map the two response shapes to one shared local type in the
     component (the plan should give this type's exact shape once both
     response bodies are confirmed against the current backend code —
     they are structurally similar but not byte-identical, per
     sub-project 3's `GuestExamAttemptController::submit()` vs.
     `ExamAttemptResource`).

## `api-client` additions

New types and functions needed (none currently exist, confirmed by
grep): `Book`/`BookPayload`-equivalent read types, `Chapter`,
`ChapterVideo`, `Exam`, `ExamAttempt`, plus the two guest-exam
functions. Since this app never creates/updates/deletes any of these
(read-only browsing + the exam-attempt actions), only `list`/`get`-style
functions are needed for Book/Chapter/ChapterVideo/Exam — no
`create`/`update`/`delete` exports, unlike every existing `api-client`
domain built so far for the management app. The exam-attempt actions
(`start`, `saveAnswer`, `submit`, `randomDraw`, `guestSubmit`) are the
only mutating calls this app makes.

## Testing

Same situation as sub-project 4: no component test runner exists for
any frontend app in this monorepo today — `api-client`'s own
`index.test.ts` (Vitest) is the only frontend test suite. This
sub-project's testing is:
- Extend `index.test.ts` for every new `api-client` function added
  (Book/Chapter/ChapterVideo/Exam list/get, the exam-attempt actions),
  matching that file's established `vi.stubGlobal('fetch', ...)`
  pattern exactly.
- `npx tsc -b apps/student/tsconfig.json` (or whatever the student app's
  actual tsconfig path turns out to be — confirm before the plan assumes
  it mirrors `apps/management`'s exactly) must pass clean.
- Manual verification in a running dev server (`npm run dev:student`)
  for every page's golden path, plus the specific edge cases this spec
  calls out: a guest completing an exam with nothing persisted
  (`exam_attempts` row count unchanged — this can be checked against
  the backend's database directly, the same way sub-project 3 verified
  it), a logged-in student's own-class exam producing a tracked attempt,
  a logged-in student's other-class exam falling back to the untracked
  guest path without any visible error, and a book download/preview
  actually working anonymously after the backend fix.
- Backend: add a feature test (or extend an existing one) proving
  `POST /books/{book}/pdf/download-link` no longer requires
  authentication, following whatever test file already covers
  `BookController`'s PDF actions.

## Out of scope for this sub-project

- Any change to the management or teacher frontends.
- Any schema/migration change (the one backend fix is a route/middleware
  change only, not a schema change).
- `chapter_video_progress`-style tracking of any kind — removed in
  sub-project 1, not reintroduced here, consistent with the original
  request's "no store exam result data... but if login student then
  track exam result" applying to EXAMS specifically, not video-watch
  progress.
- `ChapterMaterialController`'s own download/preview auth gap, if one
  exists — not requested, not investigated, left alone.
- PWA/offline behavior beyond whatever `vite-plugin-pwa` already does by
  default in `apps/student/vite.config.ts` — no new offline-specific
  work.
- A "my results" or attempt-history view for logged-in students — the
  original request only asks that a result be tracked when logged in,
  not that a history page exist to browse past attempts. If wanted,
  that's a follow-up, not part of this spec.

## Definition of done

- `frontend/apps/student`'s `LandingLogin` is rewritten in Tailwind
  utility classes with the same visual output, copy, and structure.
- A shared top-nav `SiteShell` wraps five public routes: `/`, `/books`,
  `/books/:id`, `/videos`, `/videos/:chapterId`, `/exams`, `/exams/:id`
  — all reachable without login; `/dashboard`/`RequireAuth`/
  `DashboardPlaceholder` are removed.
- Books: searchable/filterable by class+subject, each book's chapters
  listed, PDF preview/download works for an anonymous visitor.
- Videos: browsable by class+subject, each chapter's videos playable via
  YouTube embed, no progress tracking.
- Exams: browsable without login; taking one works for a guest
  (nothing persisted, verified against the database) and for a
  logged-in student (tracked when their enrollment matches the exam's
  class/academic year, gracefully falling back to the untracked guest
  flow otherwise); result shown identically either way.
- `POST /books/{book}/pdf/download-link` (and its preview-link
  equivalent, if a separate route exists for chapters — confirm against
  the actual route list during planning) no longer requires
  authentication; the signed-URL endpoints it produces are unchanged.
- `api-client/src/index.test.ts` covers every new function.
- `tsc -b` passes clean for the student app.
- Every page manually verified in a running dev server, golden path and
  the named edge cases.
