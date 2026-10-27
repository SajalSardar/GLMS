# Phase 1 Project Initialization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the empty-but-working foundation (backend, frontend, DB, docs) for the AI-Powered Teacher Training, Learning Analytics, and School Management Platform — no business modules yet.

**Architecture:** Laravel 12 (PHP 8.5) modular-monolith backend with `/api/v1` versioned routes and Sanctum installed (not wired to full auth yet); React 19 + TypeScript + Vite PWA frontend; MySQL `education_management` database, shared-DB multi-tenancy model planned (not implemented yet) via a future `school_id` global scope.

**Tech Stack:** Laravel 12, PHP 8.5.12, MySQL 8.0.30, Laravel Sanctum, React 19 + TypeScript + Vite, vite-plugin-pwa, Node 24 / npm 11, Composer 2.5.8, Git.

**Spec:** `inspiration/Training_Learning_Analytics_Proposal.docx` (original, verified byte-for-byte against the text extracted from the docx), expanded and researched in `inspiration/AI_Teacher_Training_Proposal_v2.md` and `inspiration/Research_Notes_and_Sources.md` (moved here from the project root — see Task 0).

## Global Constraints

- Backend: Laravel (PHP) only — no NestJS, no second backend.
- Frontend: React + TypeScript + Vite (not Next.js) — justified by spec, no SSR requirement found.
- Database: MySQL, db=`education_management`, user=`root`, password=`` (empty — matches local Laragon install), host=`127.0.0.1`, port=`3306`. **Do not drop or recreate** — confirmed to already exist and be empty.
- API base path: `/api/v1`.
- Root folder structure limited to `backend/`, `frontend/`, `inspiration/`, `docs/`.
- Never commit `.env` files or secrets.
- No destructive DB commands. No business modules beyond a health-check endpoint and an app shell.
- Do not install both React/Vite and Next.js.

---

### Task 0: Restructure root, preserve inspiration materials, init git

**Files:**
- Move: `AI_Teacher_Training_Proposal_v2.md` → `inspiration/AI_Teacher_Training_Proposal_v2.md`
- Move: `Research_Notes_and_Sources.md` → `inspiration/Research_Notes_and_Sources.md`
- Create: `.gitignore`
- Create: git repository at project root

- [ ] Move the two stray root-level markdown research files into `inspiration/` (git not yet initialized, so plain `mv`/`Move-Item` is safe — nothing is tracked yet).
- [ ] Leave `~$acher_Training_Learning_Analytics_Proposal_with_mobile_app.docx` untouched (orphaned Word lock file, not data).
- [ ] `git init` at project root.
- [ ] Write `.gitignore` covering `backend/vendor/`, `backend/.env`, `backend/node_modules/`, `frontend/node_modules/`, `frontend/dist/`, `*.log`, OS/editor cruft.
- [ ] `git add` + initial commit once backend/frontend scaffolds exist (defer commit to end of Task 3).

---

### Task 1: Backend — Laravel application foundation

**Files:**
- Create: `backend/` (full Laravel app via Composer)
- Modify: `backend/.env`
- Create: `backend/routes/api.php` (v1 group)
- Create: `backend/app/Http/Controllers/Api/V1/HealthController.php`
- Create: `backend/app/Http/Resources/Api/V1/HealthResource.php`
- Test: `backend/tests/Feature/Api/V1/HealthEndpointTest.php`

**Interfaces:**
- Produces: `GET /api/v1/health` → `{ "data": { "status": "ok", "timestamp": "<ISO8601>" } }`, consumed by frontend's API base URL smoke-check in Task 2.

- [ ] Run `composer create-project laravel/laravel backend` inside the project root.
- [ ] Configure `backend/.env`: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=education_management`, `DB_USERNAME=root`, `DB_PASSWORD=` (empty), `APP_URL=http://127.0.0.1:8000`.
- [ ] Run `php artisan key:generate`.
- [ ] Verify DB connectivity: `php artisan migrate:status` must succeed without error (confirms connection; `education_management` is already empty so this is safe).
- [ ] Run `php artisan migrate` (Laravel's default framework migrations only — users, cache, jobs tables — no app tables yet).
- [ ] Require Sanctum: `composer require laravel/sanctum`, publish its config/migration, run `php artisan migrate` again.
- [ ] Write the failing feature test for the health endpoint:
  ```php
  <?php

  namespace Tests\Feature\Api\V1;

  use Tests\TestCase;

  class HealthEndpointTest extends TestCase
  {
      public function test_health_endpoint_returns_ok(): void
      {
          $response = $this->getJson('/api/v1/health');

          $response->assertOk()
              ->assertJsonStructure(['data' => ['status', 'timestamp']])
              ->assertJsonPath('data.status', 'ok');
      }
  }
  ```
- [ ] Run `php artisan test --filter=HealthEndpointTest`; expect FAIL (route/controller/resource not defined).
- [ ] Implement `HealthResource`:
  ```php
  <?php

  namespace App\Http\Resources\Api\V1;

  use Illuminate\Http\Resources\Json\JsonResource;

  class HealthResource extends JsonResource
  {
      public function toArray($request): array
      {
          return [
              'status' => 'ok',
              'timestamp' => now()->toIso8601String(),
          ];
      }
  }
  ```
- [ ] Implement `HealthController`:
  ```php
  <?php

  namespace App\Http\Controllers\Api\V1;

  use App\Http\Controllers\Controller;
  use App\Http\Resources\Api\V1\HealthResource;

  class HealthController extends Controller
  {
      public function __invoke()
      {
          return new HealthResource((object) []);
      }
  }
  ```
- [ ] Register the route in `backend/routes/api.php`:
  ```php
  use App\Http\Controllers\Api\V1\HealthController;
  use Illuminate\Support\Facades\Route;

  Route::prefix('v1')->group(function () {
      Route::get('/health', HealthController::class);
  });
  ```
- [ ] Run `php artisan test --filter=HealthEndpointTest`; expect PASS.
- [ ] Verify the app boots for real: `php artisan serve` → `curl http://127.0.0.1:8000/api/v1/health` returns the expected JSON; stop the server.

---

### Task 2: Frontend — React + TypeScript + Vite PWA shell

**Files:**
- Create: `frontend/` (via `npm create vite@latest`)
- Create: `frontend/.env` and `frontend/.env.example` (`VITE_API_BASE_URL`)
- Create: `frontend/src/components/layout/AppShell.tsx`
- Create: `frontend/src/components/layout/Sidebar.tsx`
- Create: `frontend/src/pages/DashboardPlaceholder.tsx`
- Modify: `frontend/vite.config.ts` (PWA plugin)
- Modify: `frontend/src/App.tsx`

**Interfaces:**
- Consumes: `GET {VITE_API_BASE_URL}/health` from Task 1, to prove end-to-end connectivity on the placeholder dashboard.

- [ ] Run `npm create vite@latest frontend -- --template react-ts`.
- [ ] `npm install` inside `frontend/`.
- [ ] `npm install vite-plugin-pwa react-router-dom`.
- [ ] Create `frontend/.env.example` and `frontend/.env` with `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1`.
- [ ] Build a minimal feature-based folder structure: `src/components/layout/`, `src/pages/`, `src/lib/api.ts` (fetch wrapper reading `import.meta.env.VITE_API_BASE_URL`), `src/locales/` placeholder for `en`/`bn` strings.
- [ ] Build `AppShell` with a responsive top nav + collapsible sidebar (flex/CSS, no heavy UI framework) and a React Router `<Outlet />`.
- [ ] Build `DashboardPlaceholder` page that calls `GET /health` via `src/lib/api.ts` on mount and renders connection status (proves frontend↔backend wiring works).
- [ ] Wire `vite-plugin-pwa` in `vite.config.ts` with `registerType: 'autoUpdate'`, a basic manifest (name, short_name, theme_color, icons placeholder).
- [ ] Verify dev server: `npm run dev`, confirm it serves on its printed port and the dashboard shows `status: ok` once the backend (Task 1) is running.
- [ ] Verify production build: `npm run build` completes with no errors; `npm run preview` serves the built output.

---

### Task 3: Documentation + final commit

**Files:**
- Create: `docs/PROJECT_ANALYSIS.md`
- Create: `docs/ARCHITECTURE.md`
- Create: `docs/DATABASE_DESIGN.md`
- Create: `docs/API_SPECIFICATION.md`
- Create: `docs/DEVELOPMENT_ROADMAP.md`
- Create: `docs/OPEN_QUESTIONS.md`

- [ ] Write all six docs, each explicitly distinguishing 🔹 ORIGINAL requirements (sourced from `inspiration/Training_Learning_Analytics_Proposal.docx`) from 🆕 recommendations (sourced from `inspiration/AI_Teacher_Training_Proposal_v2.md` / `Research_Notes_and_Sources.md`) and from decisions made during this initialization (e.g., exact `.env` values, verified versions, exact ports/URLs).
- [ ] `git add` everything except ignored paths; create the initial commit.
- [ ] Report final verification results (DB connection, backend boot, frontend build) to the user — no success claims without having actually run the corresponding command in this session.
