# Design: Multi-Portal Frontend Monorepo Restructuring

**Status:** Approved, ready for implementation planning
**Sub-project:** 1 of 5 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

The platform requirement is three separate, independently-deployable React
portals (Teacher, Student, Management) on three subdomains, sharing one
Laravel backend and one MySQL database. This is too large for a single
spec and has been decomposed into five sub-projects, built in this order:

1. **Frontend workspace restructuring** (this spec) — turn the existing
   single-app `frontend/` into a monorepo housing three apps + shared
   packages.
2. **Multi-portal auth & authorization in Laravel** — portal-aware login
   methods (Teacher ID/mobile/email, Student ID/PIN, management login),
   strict school-level isolation enforced via Policies/Gates (not just
   the existing `User::scopeVisibleTo` helper), permission model extended
   to cover all three portals.
3. **Management Portal** — UI for what's already substantially modeled
   in the backend (schools, classes, sections, shifts, subjects, routines,
   users, roles, teacher/student profiles, guardians).
4. **Teacher Portal** — UI + new backend domain modules (attendance,
   assignments, examinations, training, leave applications, performance
   reports).
5. **Student Portal** — UI + new backend domain modules (study
   materials, assignments, examinations, results, learning progress,
   AI Tutor).

Each sub-project gets its own spec → plan → implementation cycle.

## Architectural pivot

`docs/ARCHITECTURE.md` §1 currently documents `frontend/` as "the ONLY
frontend app" — a single PWA serving all roles via role-based dashboards.
This spec **supersedes that decision**: the platform now requires three
independently-deployable frontend applications. `docs/ARCHITECTURE.md`
is updated as part of this sub-project's implementation to reflect the
monorepo structure below and drop the "ONLY frontend app" language.

## Scope of this spec

Restructure `frontend/` into an npm-workspaces monorepo containing three
independently buildable/deployable portal apps and a small set of shared,
source-only packages. No real login flow, no real portal dashboards, no
deployment/DNS/CI config — see "Out of scope" below.

## Tooling choice: npm workspaces

Considered npm workspaces, pnpm workspaces, and Nx/Turborepo.

- **npm workspaces (chosen):** zero new tooling — the project already
  uses npm. Native support for linking local packages is sufficient for
  3 apps + 4 shared packages at this stage.
- pnpm workspaces: faster installs and stricter dependency isolation,
  but introduces a second package manager and a lockfile migration not
  justified at this size.
- Nx/Turborepo: real build-graph caching and generators, but meaningful
  added complexity for only 3 apps. Can be layered in later if build
  times become a real problem — YAGNI for now.

## Repo layout

```
frontend/                          (workspace root — no app code of its own)
├── package.json                  (workspaces: ["apps/*", "packages/*"])
├── tsconfig.base.json             (shared compiler options; apps/packages extend it)
├── .oxlintrc.json                 (existing root config, lints the whole workspace via globs)
├── apps/
│   ├── teacher/                  (own vite.config.ts, own dev port 5173, own PWA manifest, own package.json)
│   ├── student/                  (dev port 5174)
│   └── management/               (dev port 5175)
└── packages/
    ├── api-client/                (typed fetch wrapper; VITE_API_BASE_URL from env; per-resource functions added as backend grows)
    ├── auth/                      (token storage, auth context/hook, login-flow primitives shared across portals)
    ├── ui/                        (shared layout primitives: AppShell, Sidebar, buttons, form controls)
    └── i18n/                      (shared en/bn string-loading utility + common keys; portal-specific strings stay in each app)
```

Shared packages are **source-only** (no build step): Vite transpiles
their TypeScript directly, consumed via npm workspace symlinks. This
avoids a build pipeline for four small packages at this stage.

Each app is a fully standalone Vite project: its own `package.json`, its
own dev port, its own PWA manifest (installable per portal), its own
`npm run build` producing an independent `dist/` deployable to its own
subdomain. No app imports another app's code — only `packages/*`.

## Migrating existing code

The current `frontend/src/` is placeholder scaffolding for a single app
and gets distributed into the new structure:

| Current file | Destination |
|---|---|
| `lib/api.ts` | `packages/api-client/src/index.ts` (generalized fetch wrapper; portal apps add their own endpoint calls on top) |
| `components/layout/AppShell.tsx`, `Sidebar.tsx`, `.css` | `packages/ui/src/layout/` (generic primitives; each app supplies its own nav items/branding) |
| `locales/en.json`, `bn.json` | `packages/i18n/src/common/` (shared strings only — loading, logout, etc.); portal-specific strings live in each app |
| `pages/DashboardPlaceholder.tsx`, `App.tsx` | Duplicated as a minimal placeholder per app (`apps/teacher`, `apps/student`, `apps/management`) — each wires its own `BrowserRouter` + `AppShell` + placeholder page, replaced with real dashboards in sub-projects 3–5 |

Each app's `vite.config.ts` is copied from the current one, with its own
dev port and its own PWA manifest name (e.g. "Teacher Portal — TTLA").

## Testing

- Each app must boot independently (`npm run dev` inside `apps/<portal>`)
  on its own port with no cross-app dependency.
- `npm run build` must succeed independently for each app from the
  workspace root (e.g. `npm run build --workspace=apps/teacher`).
- Existing lint (`oxlint`) must pass across the whole workspace.
- No automated tests exist yet for the placeholder UI; this sub-project
  does not add any (nothing with real behavior to test yet).

## Out of scope for this sub-project

- No real login UI/flow — depends on sub-project 2 (multi-portal auth
  backend) landing first.
- No real portal dashboards/domain features — sub-projects 3–5.
- No CI/CD, subdomain DNS/reverse-proxy, or hosting config — deployment
  infra is separate follow-up work, not a blocker for local dev.
- No portal-specific visual branding/design system — deferred to each
  portal's own sub-project.

## Definition of done

- `frontend/` is an npm-workspaces monorepo matching the layout above.
- `apps/teacher`, `apps/student`, `apps/management` each boot
  independently on their own port and build independently.
- `packages/api-client`, `packages/auth`, `packages/ui`, `packages/i18n`
  exist, are consumed by all three apps via workspace imports, and
  contain the migrated code from the old single-app `frontend/src/`.
- `docs/ARCHITECTURE.md` updated to describe the three-app monorepo and
  the program decomposition above, replacing the "ONLY frontend app"
  language.
