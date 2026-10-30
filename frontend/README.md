# Frontend (Multi-Portal Monorepo)

Three independent React + TypeScript + Vite PWA apps, sharing code through npm workspaces:

| App | Path | Dev command | Dev URL |
|---|---|---|---|
| Teacher Portal | `apps/teacher` | `npm run dev:teacher` | http://127.0.0.1:5173 |
| Student Portal | `apps/student` | `npm run dev:student` | http://127.0.0.1:5174 |
| Management Portal | `apps/management` | `npm run dev:management` | http://127.0.0.1:5175 |

Shared packages (`packages/api-client`, `packages/auth`, `packages/ui`, `packages/i18n`) are TypeScript source, consumed directly by each app via npm workspaces — no build step of their own.

## Common commands

- `npm install` — installs all apps and packages
- `npm run build` — builds every app independently
- `npm run lint` — lints the whole workspace with oxlint
- `npm run test` — runs the shared-package test suite with Vitest

Each app also has its own `build`/`preview` scripts, runnable with `--workspace=apps/<name>`.
