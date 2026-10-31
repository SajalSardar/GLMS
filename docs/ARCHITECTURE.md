# Architecture

**Status:** Phase 1 foundation — complete and verified. Versions below were confirmed directly from `backend/composer.json` / `frontend/package.json` after setup, not assumed.

## 0. Verified Versions

| Component | Version | Source of truth |
|---|---|---|
| PHP | 8.5.12 | `php -v` |
| Laravel | ^13.17 | `backend/composer.json` |
| Laravel Sanctum | ^4.0 | `backend/composer.json` |
| Composer | 2.5.8 | `composer -V` |
| MySQL | 8.0.30 | `SELECT VERSION();` |
| Node.js | v24.11.0 | `node -v` |
| npm | 11.6.1 | `npm -v` |
| React | ^19.3.1 | `frontend/apps/teacher/package.json` (each app pins the same version) |
| Vite | ^8.3.4 | `frontend/package.json` |
| vite-plugin-pwa | ^2.0.0 | `frontend/package.json` |
| react-router-dom | ^7.18.4 | `frontend/apps/teacher/package.json` (each app pins the same version) |
| TypeScript | ~6.0.3 | `frontend/package.json` |

Laravel's own installer pulled in **Laravel Boost** (a dev-only AI-assistance package, `composer require laravel/boost --dev`) during backend setup, which generated `backend/CLAUDE.md` with Laravel-specific coding conventions (e.g., "use `artisan make:` generators," "don't change dependencies without approval," "don't create docs unless asked"). This is dev tooling only — it ships no runtime code and is not part of the application's architecture, but future AI-assisted sessions working inside `backend/` will pick up its guidelines automatically.

## 1. High-Level Layout

```
Training_Learning_Analytics_Platform/
├── backend/          Laravel (PHP) API — the ONLY backend framework
├── frontend/          npm-workspaces monorepo — three independent portal apps + shared packages
│   ├── apps/
│   │   ├── teacher/       Teacher Portal (teacher.management.gov.bd)
│   │   ├── student/       Student Portal (student.management.gov.bd)
│   │   └── management/    Management Portal (management.gov.bd)
│   └── packages/
│       ├── api-client/    Shared fetch wrapper, env-based API base URL
│       ├── auth/          Shared token storage + auth context/hook
│       ├── ui/            Shared layout primitives (AppShell, Sidebar)
│       └── i18n/          Shared en/bn string loader
├── inspiration/      Original proposal + research (read-only reference)
└── docs/              This documentation set
```

Backend and frontend are independently deployable; the three frontend apps are
**also** independently deployable from each other — each builds its own
`dist/` for its own subdomain. Neither frontend app imports another frontend
app's code; all three only import from `frontend/packages/*`. This supersedes
the earlier Phase 0 decision to ship a single PWA for all roles — see
`docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md`
for the full rationale and the five-sub-project program this is part of.

## 2. Backend Architecture

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel (PHP 8.5.12) | Sole backend framework — see `PROJECT_ANALYSIS.md` §5 |
| Database | MySQL 8.0.30, db `education_management` | Connection: `127.0.0.1:3306`, user `root`, empty password (local dev only — see `DATABASE_DESIGN.md`) |
| Auth | Laravel Sanctum, bearer tokens | Four login endpoints: generic `/auth/login` plus three portal-specific ones (`/auth/{teacher,student,management}/login`), each enforcing its own role allowlist via `config/portals.php`. Bearer tokens, not cookie sessions — the three portals are separate-subdomain SPAs with no shared cookie domain. MFA remains Phase 2 work |
| API shape | Versioned `/api/v1/...`, `App\Http\Controllers\Api\V1\*` namespace | Every response passes through an explicit Eloquent **API Resource** class — never a raw model dump — so role-based field visibility is enforced in code, not just hidden in the UI |
| Background jobs (planned, not built yet) | Laravel Queues → Horizon + Redis once AI/bulk-import jobs exist | Dedicated queues per job type (`ai`, `imports`, `reports`, `notifications`) is the target design from Phase 2 onward |
| Planned internal structure | **Modular monolith**: future domain modules (Training, Assessment, SchoolOps, Finance, Reporting, Notifications) each own their models/controllers; cross-module communication via Laravel events | Not yet created — Phase 1 ships a single default Laravel app skeleton plus the `/api/v1/health` endpoint only |
| Multi-tenancy | Shared MySQL DB, `school_id`-scoped rows + a mandatory Eloquent global scope (`App\Models\Scopes\TenantScope`, via the `TenantScoped` trait) on every tenant-owned model | **Implemented.** Applied to all 13 tenant-owned models (`School`, `User`, `TeacherProfile`, `StudentProfile`, `SchoolClass`, `Classroom`, `ClassRoutine`, `ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment`, `TimetableEntry`, `AcademicYear`); a foreign-tenant row 404s via implicit route-model-binding before any controller or Policy code runs |
| AI integration (planned) | A single `AiProviderInterface` behind which OpenAI (GPT-4o-mini/GPT-4o) is called from queued jobs only, never synchronously in a request | No AI code exists yet; interface to be introduced in Phase 2 so the provider can be swapped without touching business logic |

## 3. Frontend Architecture

| Aspect | Decision |
|---|---|
| Framework | React 19 + TypeScript, built with Vite |
| Structure | npm-workspaces monorepo: `apps/{teacher,student,management}` (each its own Vite app, port, and PWA manifest) + `packages/{api-client,auth,ui,i18n}` (shared, source-only, no build step) |
| API communication | Shared `packages/api-client` fetch wrapper (`apiFetch`/`getHealth`) reads `VITE_API_BASE_URL` from the environment — never hardcodes a host |
| PWA | `vite-plugin-pwa`, `registerType: 'autoUpdate'`, installable manifest |
| Responsiveness | CSS flexbox/grid, no fixed pixel layouts; verified at desktop/tablet/mobile breakpoints |
| i18n | Not wired to a library yet (Phase 1 ships placeholder `en.json`/`bn.json`); full Bangla/English runtime switching is Phase 2+ |

## 4. Authentication Design (implemented)

Laravel Sanctum, bearer-token auth — not cookie-based SPA sessions. Each of the three portal frontends (`teacher`/`student`/`management`) calls its own login endpoint (`POST /api/v1/auth/{portal}/login`), receives a plain-text personal access token, and sends it as `Authorization: Bearer <token>` on every subsequent request. This was chosen over Passport/OAuth2 because no third-party developer API access is in scope, and over cookie sessions because the three portals are separate-subdomain SPAs with no shared cookie domain to configure (see `docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md` "Architecture mismatches found and resolved" §1 for the full decision record).

`packages/auth`'s existing `localStorage` bearer-token storage (added in the frontend monorepo restructuring) is therefore the actual target, not a mismatch to resolve — each portal's own login-page sub-project stores the token this way.

A generic, portal-agnostic `POST /api/v1/auth/login` also exists (any role, no allowlist) and is retained for tooling/admin use; it predates the three portal-specific endpoints and is not part of any portal frontend's login flow.

## 5. Integration Points (planned, stubbed with interfaces only when built)

- **AI**: `AiProviderInterface` → OpenAI implementation (configurable, no hardcoded credentials — see `OPEN_QUESTIONS.md`).
- **SMS**: `SmsGatewayInterface` → a local BTRC-regulated aggregator implementation.
- **Payments**: gateway-abstraction layer → bKash/Nagad via `laravel-bdpaygate` or SSLCommerz, server-side confirmation required before any invoice is marked paid.
- **Video**: YouTube IFrame Player API (`enablejsapi=1`) for v1; Bunny Stream evaluated later only if stricter content protection becomes a real requirement.

None of the above integrations exist in code yet. They are documented here so Phase 2+ work builds toward a consistent shape rather than improvising per-feature.

## 6. Local Development URLs

| App | URL | How to start |
|---|---|---|
| Backend API | `http://127.0.0.1:8000` | `cd backend && php artisan serve` |
| Frontend dev server | `http://127.0.0.1:5173` | `cd frontend && npm run dev` |
| Frontend preview (production build) | `http://127.0.0.1:4173` | `cd frontend && npm run build && npm run preview` |

`frontend/vite.config.ts` explicitly pins `server.host`/`preview.host` to `127.0.0.1` — Vite on this Windows/Laragon setup otherwise binds to the IPv6 loopback (`[::1]`), which works fine in a browser via `localhost` but breaks any tooling (e.g. `curl http://127.0.0.1:5173`) that assumes IPv4.

Laragon's automatic virtual-host mapping (`<folder>.test`) is **not** used for the backend in Phase 1, because Laravel's public document root is `backend/public/`, one level below the project folder Laragon would auto-map. `php artisan serve` is simpler for local development; a custom Laragon vhost pointing its document root at `backend/public` can be configured later if a `.test` domain is preferred.

## 7. Security Baseline (carried from the original proposal, 🔹 ORIGINAL)

- Every API request is permission-checked server-side (Policies/Gates) — hiding UI elements is never treated as access control.
- `.env` files are never committed (see root `.gitignore`); no credentials are hardcoded anywhere in source.
- TLS/HTTPS, secure password hashing, rate limiting, input validation, audit logs, and backups are production requirements to be implemented starting Phase 2 — not yet applicable to a Phase 1 local-dev-only skeleton.
