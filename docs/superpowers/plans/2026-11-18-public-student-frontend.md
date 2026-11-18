# Public Student Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `frontend/apps/student` from a landing/login page with a stub dashboard into a working public learning site — browse and download books, watch chapter videos, and take exams — all usable by an anonymous guest, with exam results tracked only when the visitor is a logged-in student.

**Architecture:** One small, scoped backend fix (book PDF download-link generation becomes public) to unblock guest downloads. New read-only `api-client` coverage for Book/Chapter/ChapterVideo/Exam/ExamAttempt, since none exists today. A new shared top-nav `SiteShell` replaces the removed sidebar-dashboard scaffold; the existing landing page is rewritten in Tailwind (same visual output) as the shell's home route. Three new feature areas (Books, Videos, Exams) are built on top, each following the cross-school resolve-and-merge fetch pattern the spec establishes for content that has no single national catalog.

**Tech Stack:** React 19 + TypeScript, React Router 7, Tailwind CSS v4 (`@tailwindcss/vite`, already configured), Vitest (for `api-client`'s own tests — no component test runner exists for any frontend app in this monorepo).

**Spec:** `docs/superpowers/specs/2026-11-18-public-student-frontend-design.md`

## Global Constraints

- No schema/migration changes anywhere in this plan. The one backend change is a route/middleware/controller-body change only.
- `ChapterMaterialController`'s own download/preview auth is NOT touched — only `BookController::pdfDownloadLink()`.
- Every new `api-client` function needs a test in `index.test.ts`, matching that file's existing `vi.stubGlobal('fetch', ...)` mocking pattern exactly.
- No `chapter_video_progress`-style tracking is reintroduced — sub-project 1 removed it deliberately.
- No changes to the management or teacher frontends.
- After every task that touches `.tsx`/`.ts` files in the student app, run `npx tsc -b apps/student/tsconfig.json` (from `frontend/`) — confirm this exact path before the first use in Task 3, since it's unverified against this specific app (unlike `apps/management/tsconfig.json`, already confirmed working in sub-project 4).
- Manually verify every page in a running dev server (`npm run dev:student` from `frontend/`) before considering its task done, per this project's standing convention for UI work. State in the report what was actually checked. A backend server must be running and reachable at the URL `apps/student/.env`'s `VITE_API_BASE_URL` points to — confirm this file exists and has a sensible value before relying on it (create it analogous to `apps/management/.env` if missing).
- `vendor/bin/pint --dirty --format agent` after any backend PHP change.

---

## Task 1: Backend — make book PDF download-link generation public

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/BookPdfControllerTest.php`

**Interfaces:**
- Produces: `POST /books/{book}/pdf/download-link` reachable without authentication, returning `{download_url, preview_url}` exactly as today — every later task's Books feature depends on this being public.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/BookPdfControllerTest.php`, add two new tests after `test_download_link_returns_a_working_signed_url_that_logs_access`:

```php
    public function test_download_link_works_for_an_unauthenticated_guest(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response = $this->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $url = $response->json('download_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();

        $log = ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
    }

    public function test_download_link_still_requires_an_available_pdf_for_a_guest(): void
    {
        $this->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnprocessable();
    }
```

(Check `ContentAccessLog`'s actual nullable actor column name before using `$log->user_id` — confirmed in the migration at `backend/database/migrations/2026_11_12_100400_make_content_log_and_video_progress_polymorphic.php` as a polymorphic `user_type`/`user_id` pair; read that migration to confirm the exact column names before writing this assertion, since this plan's own research only confirmed the pair exists, not which one of `user_type`/`user_id` to assert `null` on specifically — assert both are null to be safe regardless.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BookPdfControllerTest --compact`
Expected: FAIL — the route still requires `auth:sanctum`, so an unauthenticated request gets a 401, not the 200/422 these new tests expect.

- [ ] **Step 3: Move the route out of `auth:sanctum`, fix the controller's guard resolution**

In `backend/routes/api.php`, find (inside the `auth:sanctum` group, currently alongside `uploadPdf`/`updatePdfStatus`/`deletePdf`):
```php
Route::post('/books/{book}/pdf/download-link', [BookController::class, 'pdfDownloadLink']);
```
Remove it from there. Add it to the public route group instead (alongside the other public book/chapter/content routes), e.g. immediately after `Route::apiResource('books', BookController::class)->only(['index', 'show']);`:
```php
Route::post('/books/{book}/pdf/download-link', [BookController::class, 'pdfDownloadLink']);
```

In `backend/app/Http/Controllers/Api/V1/BookController.php`, replace `pdfDownloadLink()`:

```php
    public function pdfDownloadLink(Request $request, Book $book): JsonResponse
    {
        if (! $book->hasAvailablePdf()) {
            throw ValidationException::withMessages([
                'pdf' => ['This book has no available PDF to download.'],
            ]);
        }

        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous requests), so the user is resolved explicitly
        // from the sanctum guard rather than via $request->user() —
        // same pattern as TimetableEntryController's public routes.
        // A guest produces no actor_type/actor params at all, which
        // downloadPdf()/previewPdf() already handle gracefully (their
        // existing `?: null` fallback), matching books/chapters/other
        // public content endpoints' already-established "no permission
        // gate on read access" precedent — this method never called
        // $this->authorize() for any other reason either; the removed
        // `$this->authorize('view', $book)` call was the only gate, and
        // it's dropped here to match index()/show()'s existing shape,
        // not reintroduced with a null-guard workaround.
        $actor = $request->user('sanctum');

        $expiry = now()->addMinutes(config('content.signed_download_expiry_minutes'));
        $params = ['book' => $book->id];

        if ($actor !== null) {
            $params['actor_type'] = get_class($actor);
            $params['actor'] = $actor->id;
        }

        return response()->json([
            'download_url' => URL::temporarySignedRoute('books.pdf.download', $expiry, $params),
            'preview_url' => URL::temporarySignedRoute('books.pdf.preview', $expiry, $params),
        ]);
    }
```

(This drops the `$this->authorize('view', $book)` line entirely — `index()`/`show()` on this same controller never gate read access either, confirmed by reading them; this method's only real gate was ever `hasAvailablePdf()`.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=BookPdfControllerTest --compact`
Expected: PASS — including the two pre-existing authenticated tests (`test_download_link_requires_an_available_pdf`, `test_download_link_returns_a_working_signed_url_that_logs_access`), which must still pass unchanged (they use `actingAs(..., 'sanctum')`, which resolves correctly through `$request->user('sanctum')` the same way it always has).

- [ ] **Step 5: Run the full backend suite, format, and commit**

Run: `php artisan test --compact` — expect everything green.

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/BookController.php routes/api.php tests/Feature/BookPdfControllerTest.php
git commit -m "Make book PDF download-link generation public, matching this app's other content-browsing endpoints"
```

---

## Task 2: `api-client` — Book, Chapter, ChapterVideo, Exam, ExamAttempt

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`
- Modify: `frontend/packages/api-client/src/index.test.ts`

**Interfaces:**
- Produces: `Book`, `Chapter`, `ChapterVideo`, `Exam`, `ExamAttempt`, `GuestExamSubmissionResult` types and `listBooks`, `getBook`, `listChapters`, `listChapterVideos`, `getBookPdfLinks`, `listExams`, `getExam`, `startExamAttempt`, `saveExamAttemptAnswer`, `submitExamAttempt`, `randomDrawExamQuestions`, `submitGuestExamAttempt` functions — every page task in this plan (3-6) consumes these.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/packages/api-client/src/index.test.ts` (new `describe` blocks; add the new functions/types to the existing `import { ... } from './index'` block at the top, alphabetized among the existing entries):

```ts
describe('books', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists books filtered by class_version_id and subject_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listBooks({ classVersionId: 5, subjectId: 2 })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/books?class_version_id=5&subject_id=2`,
    )
  })

  it('gets a single book', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getBook(1)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1`)
  })

  it('requests a book\'s pdf download/preview links with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ download_url: 'https://x/d', preview_url: 'https://x/p' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getBookPdfLinks(1)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1/pdf/download-link`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'POST' })
  })
})

describe('chapters', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists chapters filtered by book_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listChapters({ bookId: 3 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapters?book_id=3`)
  })
})

describe('chapter videos', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists chapter videos filtered by chapter_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listChapterVideos({ chapterId: 9 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-videos?chapter_id=9`)
  })
})

describe('exams (public browsing)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists exams', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listExams()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams`)
  })

  it('gets a single exam', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 4 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getExam(4)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4`)
  })
})

describe('exam attempts (authenticated)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('starts an attempt with a token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await startExamAttempt(4, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/attempts`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('saves an answer', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ question_id: 7, selected_option_ids: [1], saved_at: '2026-01-01' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await saveExamAttemptAnswer(10, 7, [1, 2], 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exam-attempts/10/answers/7`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PUT',
      body: JSON.stringify({ selected_option_ids: [1, 2] }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('submits an attempt', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 10, status: 'submitted' } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await submitExamAttempt(10, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exam-attempts/10/submit`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('guest exam attempts', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('draws random questions with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await randomDrawExamQuestions(4)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/random-draw`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'POST' })
  })

  it('submits a guest attempt with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({ exam_id: 4, score: 2, total_possible_marks: 4, passed: null, answers: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const answers = [{ question_id: 1, selected_option_ids: [3] }]
    await submitGuestExamAttempt(4, answers)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/guest-submissions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ answers }),
    })
  })
})
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run packages/api-client/src/index.test.ts` (from `frontend/`)
Expected: FAIL — none of the new functions/types exist yet.

- [ ] **Step 3: Add the types and functions**

In `frontend/packages/api-client/src/index.ts`, add a new section after the existing "Class versions" section (before "Shifts"), matching this file's established section-comment convention:

```ts
// ---------- Books ----------

export interface Book {
  id: number
  class_version_id: number
  subject_id: number
  name: string
  bn_name: string | null
  order_index: number | null
  description: string | null
  is_active: boolean
  pdf_original_filename: string | null
  pdf_mime_type: string | null
  pdf_size_bytes: number | null
  pdf_uploaded_at: string | null
  pdf_status: string | null
  has_available_pdf: boolean
  class_version?: ClassVersion | null
  subject?: Subject | null
  chapters?: Chapter[] | null
}

export function listBooks(
  params: { classVersionId?: number; subjectId?: number } = {},
): Promise<{ data: Book[] }> {
  const query: Record<string, string | number | undefined> = {
    class_version_id: params.classVersionId,
    subject_id: params.subjectId,
  }

  return apiFetch<{ data: Book[] }>(`/books${buildQuery(query)}`)
}

export function getBook(id: number): Promise<{ data: Book }> {
  return apiFetch<{ data: Book }>(`/books/${id}`)
}

export interface BookPdfLinks {
  download_url: string
  preview_url: string
}

export function getBookPdfLinks(bookId: number): Promise<BookPdfLinks> {
  return apiFetch<BookPdfLinks>(`/books/${bookId}/pdf/download-link`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })
}

// ---------- Chapters ----------

export interface Chapter {
  id: number
  book_id: number
  name: string
  bn_name: string | null
  order_index: number | null
  chapter_number: number | null
  bn_chapter_number: string | null
  description: string | null
  book?: Book | null
}

export function listChapters(params: { bookId?: number } = {}): Promise<{ data: Chapter[] }> {
  const query: Record<string, string | number | undefined> = { book_id: params.bookId }

  return apiFetch<{ data: Chapter[] }>(`/chapters${buildQuery(query)}`)
}

// ---------- Chapter videos ----------

export interface ChapterVideo {
  id: number
  chapter_id: number
  youtube_video_id: string
  embed_url: string
  title: string
  bn_title: string | null
  description: string | null
  instructor_name: string | null
  duration_seconds: number | null
  learning_objectives: string | null
  completion_threshold_percent: number | null
  order_index: number | null
  is_enabled: boolean
  chapter?: Chapter | null
}

export function listChapterVideos(
  params: { chapterId?: number } = {},
): Promise<{ data: ChapterVideo[] }> {
  const query: Record<string, string | number | undefined> = { chapter_id: params.chapterId }

  return apiFetch<{ data: ChapterVideo[] }>(`/chapter-videos${buildQuery(query)}`)
}

// ---------- Exams (public browsing + attempts) ----------

export interface ExamQuestionOption {
  id: number
  option_text: string
  is_correct: boolean | null
}

export interface ExamQuestion {
  id: number
  question_type: string
  question_text: string
  bn_question_text?: string | null
  marks: number
  order_index?: number
  options: ExamQuestionOption[]
}

export interface Exam {
  id: number
  class_version_id: number
  subject_id: number
  academic_year_id: number
  name: string
  selection_mode: 'manual' | 'fixed_random' | 'per_attempt_random'
  status: string
  available_from: string | null
  available_until: string | null
  random_question_count: number | null
  marks_per_question: number | null
  passing_score_percent: number | null
  max_attempts: number | null
  questions?: ExamQuestion[] | null
}

export function listExams(): Promise<{ data: Exam[] }> {
  return apiFetch<{ data: Exam[] }>('/exams')
}

export function getExam(id: number): Promise<{ data: Exam }> {
  return apiFetch<{ data: Exam }>(`/exams/${id}`)
}

export interface ExamAttemptQuestionResult {
  question_id: number
  question_type: string
  question_text: string
  marks: number
  order_index: number
  options: Array<{ id: number; option_text: string; is_correct: boolean | null }>
  selected_option_ids: number[] | null
  is_correct: boolean | null
  marks_awarded: number | null
}

export interface ExamAttempt {
  id: number
  exam_id: number
  user_id: number
  attempt_number: number
  status: 'in_progress' | 'submitted'
  started_at: string
  submitted_at: string | null
  score: number | null
  total_possible_marks: number | null
  passed: boolean | null
  questions?: ExamAttemptQuestionResult[] | null
}

export function startExamAttempt(examId: number, token: string): Promise<{ data: ExamAttempt }> {
  return apiFetch<{ data: ExamAttempt }>(`/exams/${examId}/attempts`, {
    method: 'POST',
    headers: authHeaders(token),
  })
}

export function saveExamAttemptAnswer(
  attemptId: number,
  questionId: number,
  selectedOptionIds: number[],
  token: string,
): Promise<{ question_id: number; selected_option_ids: number[]; saved_at: string }> {
  return apiFetch(`/exam-attempts/${attemptId}/answers/${questionId}`, {
    method: 'PUT',
    headers: authHeaders(token),
    body: JSON.stringify({ selected_option_ids: selectedOptionIds }),
  })
}

export function submitExamAttempt(attemptId: number, token: string): Promise<{ data: ExamAttempt }> {
  return apiFetch<{ data: ExamAttempt }>(`/exam-attempts/${attemptId}/submit`, {
    method: 'POST',
    headers: authHeaders(token),
  })
}

export function randomDrawExamQuestions(examId: number): Promise<{ data: ExamQuestion[] }> {
  return apiFetch<{ data: ExamQuestion[] }>(`/exams/${examId}/random-draw`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })
}

export interface GuestExamAnswerInput {
  question_id: number
  selected_option_ids: number[]
}

export interface GuestExamAnswerResult extends GuestExamAnswerInput {
  is_correct: boolean
  marks_awarded: number
  marks: number
  correct_option_ids: number[]
}

export interface GuestExamSubmissionResult {
  exam_id: number
  score: number
  total_possible_marks: number
  passed: boolean | null
  answers: GuestExamAnswerResult[]
}

export function submitGuestExamAttempt(
  examId: number,
  answers: GuestExamAnswerInput[],
): Promise<GuestExamSubmissionResult> {
  return apiFetch<GuestExamSubmissionResult>(`/exams/${examId}/guest-submissions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ answers }),
  })
}
```

(`buildQuery`/`authHeaders`/`apiFetch` are this file's existing private helpers, already confirmed in sub-project 4's own Task 1 — reuse them exactly, don't redefine. `ClassVersion`/`Subject` are this file's existing exported types, already defined above this new section.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run packages/api-client/src/index.test.ts` (from `frontend/`)
Expected: PASS

- [ ] **Step 5: Type-check and commit**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`) — confirm this still passes clean, since `api-client` is shared and the management app is the one project already known to type-check cleanly against it; this catches any accidental breaking change to existing exports before Task 3 even starts.

```bash
cd frontend
git add packages/api-client/src/index.ts packages/api-client/src/index.test.ts
git commit -m "Add read-only api-client coverage for books, chapters, chapter videos, exams, and exam attempts"
```

---

## Task 3: Site shell, routing restructure, and Tailwind rewrite of the landing page

**Files:**
- Modify: `frontend/apps/student/src/index.css`
- Modify: `frontend/apps/student/src/App.tsx`
- Create: `frontend/apps/student/src/components/SiteShell.tsx`
- Modify: `frontend/apps/student/src/pages/LandingLogin.tsx`
- Delete: `frontend/apps/student/src/pages/LandingLogin.css`
- Delete: `frontend/apps/student/src/pages/DashboardPlaceholder.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Produces: `SiteShell` (the layout component every later route renders inside), a green-palette Tailwind theme (`--color-primary-*` + named tone colors) every later task's cards/badges reuse, and the confirmed working `npx tsc -b apps/student/tsconfig.json` command every later task relies on.

- [ ] **Step 1: Confirm the student app's tsconfig path**

Run: `ls apps/student/tsconfig*.json` (from `frontend/`). Confirm whether it's a single `apps/student/tsconfig.json` (matching the pattern already confirmed for `apps/management`) or split into `tsconfig.app.json`/`tsconfig.node.json` with its own root `tsconfig.json` referencing both (also already confirmed as `apps/management`'s actual shape in sub-project 4). Use whichever file this app's actual root tsconfig is for every `npx tsc -b` command in this and every later task — do not assume it's identical to management's without checking.

- [ ] **Step 2: Rework the Tailwind theme to match the reference design's green palette**

Read `frontend/apps/student/src/pages/LandingLogin.css` in full first (1066 lines — this defines every color/spacing/layout rule the current page uses; you need to understand it before replacing it). Note its `:root` block's custom properties (`--color-primary: #0f6b4c`, `--color-primary-dark`, `--color-primary-tint`, `--color-accent`, `--color-accent-dark`, `--color-ink`, `--color-muted`, `--color-surface`, `--color-border`, `--font-display`) and its `.landing` block's `--tone-*` variables (violet/green/amber/blue/rose/teal/red/muted) — these drive every badge/card/icon color across the whole page.

In `frontend/apps/student/src/index.css`, the existing `@theme` block currently defines a `--color-primary-*` scale that is an unrelated indigo/purple palette (confirmed — `--color-primary-500: #605dff` — this doesn't match this app's actual green branding at all; it was carried over from the management app's own theme setup and never adjusted for the student app). Replace that scale with one derived from the green palette above, so Tailwind's generated `bg-primary-500`/`text-primary-600`/etc. utilities actually render the right green (pick specific shades in the scale yourself, bracketing `#0f6b4c` sensibly — e.g. `--color-primary-500: #0f6b4c`, lighter shades below, darker above, following the same kind of ramp the existing indigo scale already demonstrates). Add the `--tone-*` colors as named theme colors too (e.g. `--color-tone-violet: #7c3aed`, `--color-tone-green: #16a34a`, etc., copying the exact hex values from `LandingLogin.css`), so later tasks can use `bg-tone-violet-500`-style utilities (or `bg-[var(--color-tone-violet)]` if Tailwind v4's arbitrary-value syntax turns out cleaner once you're actually writing the utility classes — use your judgment on whichever reads cleaner once you see it rendered, since this is a presentation detail, not a behavioral one). Keep the existing `success`/`danger`/`warning` scales untouched (already correct, shared convention with the management app). Keep `--font-display`/the Hind Siliguri font stack from the old `:root` block — move it into `index.css` since it's no longer landing-page-specific (every page in this app is Bangla).

- [ ] **Step 3: Create `SiteShell`**

Create `frontend/apps/student/src/components/SiteShell.tsx` — a top-nav layout component wrapping an `<Outlet />`, replacing `AppShell`/`Sidebar` for this app (not reused, per the spec's decision). Structure:

```tsx
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

const NAV_ITEMS = [
  { to: '/', label: t['student.nav.home'], end: true },
  { to: '/books', label: t['student.nav.books'] },
  { to: '/videos', label: t['student.nav.videos'] },
  { to: '/exams', label: t['student.nav.exams'] },
]

export default function SiteShell() {
  const { token, clearToken } = useAuth()

  return (
    <div className="flex min-h-screen flex-col bg-white text-primary-900">
      <header className="sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-primary-100 bg-white px-4 py-3 lg:px-8">
        <Link to="/" className="text-lg font-bold text-primary-700">
          {t['landing.brand']}
        </Link>

        <nav className="hidden items-center gap-6 text-sm font-medium text-primary-800 md:flex" aria-label={t['student.nav.ariaLabel']}>
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => (isActive ? 'text-primary-600' : 'hover:text-primary-600')}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>

        {token ? (
          <button
            type="button"
            onClick={clearToken}
            className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50"
          >
            {t['student.nav.logout']}
          </button>
        ) : (
          <Link
            to="/#login"
            className="cursor-pointer rounded-md bg-primary-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-600"
          >
            {t['student.nav.signIn']}
          </Link>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <footer className="border-t border-primary-100 bg-primary-50 px-4 py-6 text-center text-sm text-primary-700 lg:px-8">
        © {new Date().getFullYear()} {t['landing.brand']}
      </footer>
    </div>
  )
}
```

Mobile nav (a hamburger menu for the `NAV_ITEMS` list below `md:`) is not included in this sketch — add one following whatever simple disclosure pattern reads cleanest to you (a `useState` toggle + a dropdown panel is enough; this doesn't need to match `GeoCrudTable`'s or any management-app component's exact mobile-menu implementation, since this app shares no component library with it). Mirror the *existing* `LandingLogin.tsx`'s own current mobile-nav toggle behavior (it already has one, confirmed in the file you're about to rewrite) rather than inventing a new pattern from scratch.

- [ ] **Step 4: Rewrite `LandingLogin.tsx` in Tailwind, delete `LandingLogin.css`**

Rewrite `frontend/apps/student/src/pages/LandingLogin.tsx` to produce the same visual page (hero + login card, feature badges, class-level cards, subjects grid, stats, quote, onboarding/audience sections) using Tailwind utility classes instead of importing `./LandingLogin.css`. Keep every existing Bangla string (`t['landing.*']` keys) and every existing data array (`NAV_LINKS`, `HERO_BADGES`, `FEATURES`, `CLASS_LEVELS`, `SUBJECTS`) exactly as they are — only the JSX's `className` props and the removed `<style>` import change. The page's own internal nav/login-card markup can now be simplified: since `SiteShell` (Task 3, Step 3) already provides the site-wide top nav, `LandingLogin`'s own `<header className="landing-nav">` block is no longer needed — this page becomes the `/` route's content *inside* `SiteShell`, not a full standalone page with its own header. Keep the `id="login"` anchor on the login-card section (the nav's sign-in link, and `SiteShell`'s, both depend on `/#login` working).

Use the `Icon`/`HeroIllustration`/`LoginCard` sub-components' existing logic unchanged (they don't render any of the removed CSS classes directly except via the `common`/`viewBox` SVG props, which aren't Tailwind-affected) — only their *wrapping* JSX's `className` attributes change from the old BEM names to Tailwind utilities. Use the theme tokens from Step 2 (`bg-primary-500`, `text-tone-violet`-equivalent, etc.) so the page's actual rendered colors match what `LandingLogin.css` currently produces — load the dev server (this task's Step 7) and visually compare against the current page before and after, not just against the reference image, since "same visual output" is the explicit bar here.

Delete `frontend/apps/student/src/pages/LandingLogin.css` once nothing imports it.

- [ ] **Step 5: Remove the dashboard/RequireAuth scaffold, wire up the new routes**

Delete `frontend/apps/student/src/pages/DashboardPlaceholder.tsx`.

Rewrite `frontend/apps/student/src/App.tsx`:

```tsx
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import SiteShell from './components/SiteShell'
import LandingLogin from './pages/LandingLogin'

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route element={<SiteShell />}>
            <Route index element={<LandingLogin />} />
            {/* Books/Videos/Exams routes are added by Tasks 4-6 */}
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
```

(Later tasks add their own `<Route>` entries inside this same `<Route element={<SiteShell />}>` block — don't add placeholder routes for them now.)

- [ ] **Step 6: Add the new i18n keys**

Add to `frontend/packages/i18n/src/common/en.json` and `bn.json` (English first, then the Bengali translation in the same position in `bn.json`):

```json
  "student.nav.home": "Home",
  "student.nav.books": "Books",
  "student.nav.videos": "Videos",
  "student.nav.exams": "Exams",
  "student.nav.signIn": "Sign in",
  "student.nav.logout": "Logout",
  "student.nav.ariaLabel": "Primary"
```

(Bengali equivalents in `bn.json`: adapt from the existing `landing.nav.*` keys' Bengali text already in that file for Home/features/etc. — e.g. reuse "হোম" for Home if `landing.nav.home` already has it, otherwise translate directly. Check the existing `landing.nav.*` Bengali strings first rather than guessing fresh translations where an equivalent already exists.)

- [ ] **Step 7: Type-check and manually verify**

Run: `npx tsc -b apps/student/tsconfig.json` (from `frontend/`, using whichever exact path Step 1 confirmed).
Expected: zero errors.

Run `npm run dev:student` (from `frontend/`). Visit `/`. Confirm: the page renders with the same visual structure/content as before (compare against a screenshot or your memory of the pre-rewrite page if you took one before starting — recommended, since "same visual output" is explicitly the bar), the login card still works end to end (log in with a real test student account if one exists in the connected backend's database, or at least confirm the form submits and shows the expected error/success behavior), the nav's Sign in/Logout toggle correctly based on token presence, and the `#login` anchor scrolls to the right place from the nav.

- [ ] **Step 8: Commit**

```bash
cd frontend
git add apps/student/src/index.css apps/student/src/App.tsx apps/student/src/components/SiteShell.tsx apps/student/src/pages/LandingLogin.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git rm apps/student/src/pages/LandingLogin.css apps/student/src/pages/DashboardPlaceholder.tsx
git commit -m "Replace the dashboard scaffold with a shared public SiteShell; rewrite the landing page in Tailwind"
```

---

## Task 4: Books feature

**Files:**
- Create: `frontend/apps/student/src/pages/BooksPage.tsx`
- Create: `frontend/apps/student/src/pages/BookDetailPage.tsx`
- Modify: `frontend/apps/student/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: Task 2's `listClasses`/`listSubjects` (already exist in `api-client` from the management work)/`listClassVersions`/`listBooks`/`getBook`/`getBookPdfLinks`; Task 3's `SiteShell`.

- [ ] **Step 1: Build `BooksPage`**

Create `frontend/apps/student/src/pages/BooksPage.tsx`. Filter bar: a Class select (`listClasses()`, the national list) and a Subject select (`listSubjects()`), both optional. On filter change (or on mount with no filter), resolve content using the cross-school merge strategy the spec establishes:

```tsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  listBooks,
  listClasses,
  listClassVersions,
  listSubjects,
  type Book,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

async function booksForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Book[]> {
  if (!classId) return []

  const versionsResult = await listClassVersions({ classId })
  const versions = versionsResult.data

  const bookLists = await Promise.all(
    versions.map((version) => listBooks({ classVersionId: version.id, subjectId: subjectId ?? undefined })),
  )

  return bookLists.flatMap((result) => result.data)
}

export default function BooksPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [classId, setClassId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [books, setBooks] = useState<Book[]>([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  useEffect(() => {
    let active = true
    setLoading(true)

    booksForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setBooks(result)
      })
      .catch(() => {
        if (active) setBooks([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.books.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.books.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={subjectId ?? ''}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.books.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.name}
            </option>
          ))}
        </select>
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.books.loading']}</p>}

      {!loading && !classId && <p className="mt-6 text-sm text-primary-600">{t['student.books.pickClass']}</p>}

      {!loading && classId && books.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.books.empty']}</p>
      )}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {books.map((book) => (
          <Link
            key={book.id}
            to={`/books/${book.id}`}
            className="rounded-lg border border-primary-100 p-4 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">{book.bn_name ?? book.name}</p>
            <p className="mt-1 text-sm text-primary-600">{book.subject?.name}</p>
            <p className="mt-1 text-xs text-primary-500">{book.class_version?.school?.name ?? ''}</p>
            {!book.has_available_pdf && (
              <p className="mt-2 text-xs text-warning-600">{t['student.books.noPdf']}</p>
            )}
          </Link>
        ))}
      </div>
    </section>
  )
}
```

Check whether `ClassVersion`'s type in `api-client` actually carries a `school` relation (`book.class_version?.school?.name` above assumes it does) — confirm against the actual `ClassVersion` interface in `index.ts` before relying on this; if it only has `school_id` (a bare number, as sub-project 4's own research found for the exact same type), resolve the school name by also fetching `listSchools()` once and mapping by id, the same pattern sub-project 4's `ClassVersionsPage.tsx` already established, instead of assuming a nested relation that may not exist.

- [ ] **Step 2: Build `BookDetailPage`**

Create `frontend/apps/student/src/pages/BookDetailPage.tsx`. Fetch `getBook(id)` on mount (from the route's `:id` param via `useParams`). Render the book's name/subject, its chapter list (from the book's own `chapters` field if `BookController::show()`'s eager-load already includes it — confirm this against the actual controller code before assuming; if not, fetch `listChapters({ bookId: id })` separately), and, when `has_available_pdf`, "Preview" and "Download" buttons that call `getBookPdfLinks(id)` and then `window.open(result.preview_url, '_blank')` / navigate the browser to `result.download_url` respectively.

- [ ] **Step 3: Wire up the routes**

In `frontend/apps/student/src/App.tsx`, inside the existing `<Route element={<SiteShell />}>` block, add:
```tsx
            <Route path="books" element={<BooksPage />} />
            <Route path="books/:id" element={<BookDetailPage />} />
```
with the corresponding imports added at the top of the file.

- [ ] **Step 4: Add i18n keys**

Add to both `en.json`/`bn.json`:
```json
  "student.books.title": "Books",
  "student.books.allClasses": "All classes",
  "student.books.allSubjects": "All subjects",
  "student.books.loading": "Loading books...",
  "student.books.pickClass": "Pick a class to see its books.",
  "student.books.empty": "No books found for this selection.",
  "student.books.noPdf": "No PDF available yet",
  "student.books.preview": "Preview",
  "student.books.download": "Download"
```

- [ ] **Step 5: Type-check and manually verify**

Run: `npx tsc -b apps/student/tsconfig.json` (from `frontend/`). Expected: zero errors.

Manually verify in the dev server: `/books` loads with no filter selected (shows the "pick a class" prompt, not an error); picking a class populates results (or the empty state, if the connected backend's database has no books yet — note this explicitly in your report either way); clicking a book goes to its detail page; preview/download work for a book with `has_available_pdf: true` (if one exists in the connected database) and are hidden/disabled otherwise.

- [ ] **Step 6: Commit**

```bash
cd frontend
git add apps/student/src/pages/BooksPage.tsx apps/student/src/pages/BookDetailPage.tsx apps/student/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add the public Books feature: class/subject browsing, chapter list, PDF preview/download"
```

---

## Task 5: Videos feature

**Files:**
- Create: `frontend/apps/student/src/pages/VideosPage.tsx`
- Create: `frontend/apps/student/src/pages/VideoPlaylistPage.tsx`
- Modify: `frontend/apps/student/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: Task 2's `listClasses`/`listSubjects`/`listClassVersions`/`listBooks`/`listChapters`/`listChapterVideos`; Task 3's `SiteShell`; Task 4's cross-school resolve pattern (same shape, one level deeper).

- [ ] **Step 1: Build `VideosPage`**

Create `frontend/apps/student/src/pages/VideosPage.tsx`, following `BooksPage.tsx`'s exact structure (same Class/Subject filter bar, same loading/empty states), but resolving one level deeper per the spec: for each book found via `booksForClassAndSubject`-equivalent logic, fetch `listChapters({ bookId: book.id })`, then keep only chapters that have at least one chapter-video — this requires either checking each chapter's video count via a `listChapterVideos({ chapterId })` call per chapter (an N+2 from the frontend, consistent with the spec's already-accepted N+1 pattern for Books) or, if that proves too slow once you see it running, caching/batching sensibly — use your judgment on the exact batching, but keep the *data shown* identical: only chapters with ≥1 video appear, each card labeled with its chapter name, book name, and owning school (same labeling convention as `BooksPage`). Extract the shared cross-school class→versions→books resolution logic (currently sketched inline in `BooksPage.tsx`'s `booksForClassAndSubject`) into a small shared helper — e.g. `frontend/apps/student/src/lib/resolveContent.ts` — rather than duplicating it, since both this page and `BooksPage` need the identical first two steps (class → versions → books) before diverging (Books stops there; Videos continues to chapters → videos). Refactor `BooksPage.tsx` to use this extracted helper in the same commit, so the duplication never actually lands in git history.

- [ ] **Step 2: Build `VideoPlaylistPage`**

Create `frontend/apps/student/src/pages/VideoPlaylistPage.tsx`. Fetch `listChapterVideos({ chapterId: id })` (from the route's `:chapterId` param) ordered by `order_index` (confirm the backend already returns them in that order via its own `orderBy('order_index')` — it does, per `ChapterVideoController::index()` — so no client-side re-sort is needed). Render a two-column layout: a playlist list (title, duration formatted as `mm:ss`, instructor name) on one side, and the currently-selected video's `embed_url` in an `<iframe>` (standard YouTube embed attributes: `allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"`, `allowFullScreen`) on the other. Clicking a playlist item swaps which video is selected (local `useState`, defaulting to the first video on load) — no route change, no video-progress tracking of any kind.

- [ ] **Step 3: Wire up the routes**

In `App.tsx`, inside the `SiteShell` route block:
```tsx
            <Route path="videos" element={<VideosPage />} />
            <Route path="videos/:chapterId" element={<VideoPlaylistPage />} />
```

- [ ] **Step 4: Add i18n keys**

```json
  "student.videos.title": "Video Lessons",
  "student.videos.allClasses": "All classes",
  "student.videos.allSubjects": "All subjects",
  "student.videos.loading": "Loading videos...",
  "student.videos.pickClass": "Pick a class to see its videos.",
  "student.videos.empty": "No videos found for this selection.",
  "student.videos.instructor": "Instructor"
```

- [ ] **Step 5: Type-check and manually verify**

Run: `npx tsc -b apps/student/tsconfig.json`. Expected: zero errors.

Manually verify: `/videos` behaves like `/books` structurally; a chapter with videos opens its playlist; clicking different playlist items swaps the embedded player; the embed actually loads a YouTube video (if real `youtube_video_id` data exists in the connected database — note explicitly if it doesn't and this can only be verified against the embed URL's shape, not an actual playable video).

- [ ] **Step 6: Commit**

```bash
cd frontend
git add apps/student/src/pages/VideosPage.tsx apps/student/src/pages/VideoPlaylistPage.tsx apps/student/src/pages/BooksPage.tsx apps/student/src/lib/resolveContent.ts apps/student/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add the public Videos feature: chapter browsing and YouTube-embed playlists"
```

---

## Task 6: Exams feature

**Files:**
- Create: `frontend/apps/student/src/pages/ExamsPage.tsx`
- Create: `frontend/apps/student/src/pages/ExamRunnerPage.tsx`
- Modify: `frontend/apps/student/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: Task 2's `listExams`/`getExam`/`startExamAttempt`/`saveExamAttemptAnswer`/`submitExamAttempt`/`randomDrawExamQuestions`/`submitGuestExamAttempt`; Task 3's `SiteShell`.

- [ ] **Step 1: Build `ExamsPage`**

Create `frontend/apps/student/src/pages/ExamsPage.tsx`. `listExams()` on mount (already correctly degrades to published+available for an anonymous visitor — no filtering needed client-side). Render each exam as a card (name, subject, class — resolve subject/class names the same way `BooksPage` resolves school names, by fetching `listSubjects()`/`listClasses()` once and mapping by id, since `Exam` only carries bare `subject_id`/`class_version_id`) linking to `/exams/:id`.

- [ ] **Step 2: Build `ExamRunnerPage` — setup state**

Create `frontend/apps/student/src/pages/ExamRunnerPage.tsx`. First read `backend/app/Http/Controllers/Api/V1/ExamAttemptController.php`'s current `start()` method to copy the exact enrollment-mismatch message string verbatim (the spec deliberately does not retype it, to force this check) — as of this plan's own research it is `"You are not enrolled in the class version/academic year this exam belongs to."`, but confirm against the live file since this plan may have been written before or after an unrelated edit to that string.

State machine: `'setup' | 'in_progress' | 'submitted'`. On mount (exam id from `useParams`):

```tsx
async function loadQuestionsAndMaybeStartAttempt(
  examId: number,
  token: string | null,
): Promise<{ questions: ExamQuestion[]; attemptId: number | null; selectionMode: Exam['selection_mode'] }> {
  const examResult = await getExam(examId)
  const exam = examResult.data

  if (token) {
    try {
      const attemptResult = await startExamAttempt(examId, token)
      const attempt = attemptResult.data
      return {
        questions: (attempt.questions ?? []).map(questionFromAttemptQuestion), // see note below
        attemptId: attempt.id,
        selectionMode: exam.selection_mode,
      }
    } catch (error) {
      if (!(error instanceof ApiError) || !isEnrollmentMismatch(error)) {
        throw error
      }
      // Falls through to the guest path below — this student is logged
      // in but not enrolled in this exam's class/year, so tracking
      // isn't possible; browsing/taking it still is.
    }
  }

  if (exam.selection_mode === 'per_attempt_random') {
    const drawResult = await randomDrawExamQuestions(examId)
    return { questions: drawResult.data, attemptId: null, selectionMode: exam.selection_mode }
  }

  return { questions: exam.questions ?? [], attemptId: null, selectionMode: exam.selection_mode }
}

function isEnrollmentMismatch(error: ApiError): boolean {
  // Verbatim string from ExamAttemptController::start() at the time this
  // was written — re-check backend/app/Http/Controllers/Api/V1/ExamAttemptController.php
  // if this stops matching; see the spec's "Detection mechanism" note.
  const message = error.errors?.exam?.[0] ?? error.message
  return message === 'You are not enrolled in the class version/academic year this exam belongs to.'
}
```

(`ApiError`'s `errors` field shape — `Record<string, string[]>` — is already defined in `api-client`; confirm `ExamAttemptController::start()`'s `ValidationException::withMessages(['exam' => [...]])` really does surface as `error.errors.exam[0]` through this app's existing `apiFetch` error-parsing path, the same way it does for every other `ValidationException` this codebase has already handled on the frontend — it should, since `apiFetch` builds `ApiError` from the response body's `errors` key uniformly.)

The attempt's returned `questions` field (`ExamAttemptQuestionResult[]`) and the guest/manual path's `ExamQuestion[]` have different shapes (confirmed in Task 2's types) — write a small `questionFromAttemptQuestion()` mapper (or just use two separate local types/branches in the component rather than forcing one shared shape prematurely) so the "in progress" rendering step can work from one consistent shape regardless of which path produced it. Don't over-engineer this into a generic abstraction — a single small mapping function is enough.

- [ ] **Step 3: Build `ExamRunnerPage` — in-progress and submitted states**

In-progress: render each question (radio buttons for `mcq_single`/`true_false`, checkboxes for `mcq_multiple` — confirm the actual `question_type` values against `Question` model/migration before assuming these three; sub-project 3's own test fixtures used `mcq_single`/`mcq_multiple`/`true_false`, confirm nothing else exists), keeping selected option IDs in local component state keyed by question ID. If `attemptId !== null` (the tracked, logged-in path), call `saveExamAttemptAnswer(attemptId, questionId, selectedIds, token)` on every change (fire-and-forget — don't block the UI on it, log a console warning on failure rather than surfacing an error for what's just a save-as-you-go convenience). If `attemptId === null` (guest path), answers only ever live in local state.

On the user's final "Submit" action: if `attemptId !== null`, call `submitExamAttempt(attemptId, token)` and use its returned `ExamAttempt` (status now `'submitted'`, `questions` now populated with `is_correct`/`marks_awarded`) as the result. If `attemptId === null`, call `submitGuestExamAttempt(examId, answers)` (building the `GuestExamAnswerInput[]` array from local state) and use its returned `GuestExamSubmissionResult` as the result. Map BOTH result shapes to one small local `ExamResultView` type the "submitted" render step consumes uniformly (score, total possible marks, passed, and a per-question list of `{questionText, selectedOptionIds, isCorrect, correctOptionIds}}` — note the guest result's `correct_option_ids` is directly available per-answer, while the tracked result's correctness lives in its `questions[].options[].is_correct` — both are confirmed-available, just shaped differently, so this mapping is straightforward, not a gap).

Render the submitted state: score/total, pass/fail if `passed !== null`, and each question with the student's answer marked correct/incorrect and the correct answer revealed.

- [ ] **Step 4: Wire up the routes**

```tsx
            <Route path="exams" element={<ExamsPage />} />
            <Route path="exams/:id" element={<ExamRunnerPage />} />
```

- [ ] **Step 5: Add i18n keys**

```json
  "student.exams.title": "Exams",
  "student.exams.loading": "Loading exam...",
  "student.exams.empty": "No exams available right now.",
  "student.exams.submit": "Submit",
  "student.exams.submitting": "Submitting...",
  "student.exams.score": "Score",
  "student.exams.passed": "Passed",
  "student.exams.failed": "Not passed",
  "student.exams.correctAnswer": "Correct answer",
  "student.exams.yourAnswer": "Your answer"
```

- [ ] **Step 6: Type-check and manually verify**

Run: `npx tsc -b apps/student/tsconfig.json`. Expected: zero errors.

Manually verify, against the connected backend's actual database (create a throwaway published exam via the management frontend first if none exists, same way earlier sub-projects set up test fixtures when needed):
- An anonymous visitor can open `/exams`, pick an exam, answer it, submit, and see a result — and this creates **zero** new rows in `exam_attempts`/`exam_attempt_questions`/`exam_attempt_answers` (check the database directly after submitting, the same verification method sub-project 3 used).
- A logged-in student whose class/academic-year matches the exam gets a tracked attempt (a real row appears in `exam_attempts`).
- A logged-in student whose class/academic-year does NOT match falls through to the untracked guest path with no visible error — confirm this specific case, since it's the one piece of this task's logic with no existing precedent anywhere else in the codebase to lean on.

- [ ] **Step 7: Commit**

```bash
cd frontend
git add apps/student/src/pages/ExamsPage.tsx apps/student/src/pages/ExamRunnerPage.tsx apps/student/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add the public Exams feature: browsing, taking an exam as guest or tracked student, and viewing the result"
```

---

## Task 7: Final verification

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1-6.

- [ ] **Step 1: Full backend test suite**

Run: `php artisan test --compact` (from `backend/`). Expected: all green, including Task 1's new tests.

- [ ] **Step 2: Full frontend type-check and test suite**

Run: `npx tsc -b apps/student/tsconfig.json` (from `frontend/`). Expected: zero errors.
Run: `npm test` (from `frontend/`, runs `vitest run` across the workspace). Expected: all passing, including every test added in Task 2.

- [ ] **Step 3: Full manual walkthrough**

With `npm run dev:student` running against a real backend, walk through every page in order: home (login works, nav works), Books (filter, detail, preview/download), Videos (filter, playlist, embed), Exams (browse, guest attempt verified stateless against the database, tracked attempt for a matching-enrollment student, graceful fallback for a mismatched one). Note any issue found and fix it before considering this task (and the plan) done.

- [ ] **Step 4: Commit (only if Step 3 required fixes)**

```bash
git add -A
git commit -m "Fix issues found during final manual verification"
```

(Skip this step entirely if Step 3 found nothing to fix.)
