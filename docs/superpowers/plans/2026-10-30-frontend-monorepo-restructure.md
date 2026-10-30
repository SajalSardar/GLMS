# Frontend Monorepo Restructuring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing single-app `frontend/` into an npm-workspaces monorepo containing three independently buildable/deployable portal apps (teacher, student, management) and four shared, source-only packages (api-client, auth, i18n, ui).

**Architecture:** npm workspaces with `apps/*` and `packages/*`. Each app is a standalone Vite + React + TS project (own `package.json`, own dev port, own PWA manifest, own `dist/`). Shared packages have no build step — Vite/`tsc` consume their TypeScript source directly through workspace symlinks. No app imports another app; apps only import `packages/*`.

**Tech Stack:** React 19, TypeScript ~6.0.3, Vite 8, react-router-dom 7, vite-plugin-pwa 2, oxlint, Vitest (new — added for the two packages with pure testable logic).

**Spec:** `docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md`

## Global Constraints

- Tooling is npm workspaces — no pnpm, no Nx/Turborepo.
- Shared packages (`packages/api-client`, `packages/auth`, `packages/ui`, `packages/i18n`) are source-only: no build step, consumed as TypeScript source via workspace symlinks.
- Each app (`apps/teacher`, `apps/student`, `apps/management`) is fully standalone: own `package.json`, own dev port (teacher 5173, student 5174, management 5175), own PWA manifest, own independent `npm run build` producing its own `dist/`. No app imports another app's code.
- No real login UI/flow in this plan (depends on the not-yet-built multi-portal auth backend).
- No real portal dashboards/domain features in this plan (future sub-projects).
- No CI/CD, subdomain DNS/reverse-proxy, or hosting config in this plan.
- No portal-specific visual branding/design system in this plan — all three apps share the same visual style for now.
- `docs/ARCHITECTURE.md` must be updated to drop the "ONLY frontend app" language and describe the three-app monorepo.

---

### Task 1: Workspace root scaffold

**Files:**
- Modify: `frontend/package.json` (replace entirely)
- Create: `frontend/tsconfig.base.json`
- Create: `frontend/vitest.config.ts`
- Keep unchanged: `frontend/.oxlintrc.json`, `frontend/.gitignore`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: root `package.json` with `workspaces: ["apps/*", "packages/*"]` and scripts `dev:teacher`, `dev:student`, `dev:management`, `build`, `lint`, `test`; `frontend/tsconfig.base.json` with the shared `compilerOptions` every app/package tsconfig extends; `frontend/vitest.config.ts` with `test.include: ['packages/*/src/**/*.test.ts']` — later package tasks rely on this include pattern to get picked up.

- [ ] **Step 1: Replace the root `package.json`**

```json
{
  "name": "frontend",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "workspaces": [
    "apps/*",
    "packages/*"
  ],
  "scripts": {
    "dev:teacher": "npm run dev --workspace=apps/teacher",
    "dev:student": "npm run dev --workspace=apps/student",
    "dev:management": "npm run dev --workspace=apps/management",
    "build": "npm run build --workspaces --if-present",
    "lint": "oxlint",
    "test": "vitest run"
  },
  "devDependencies": {
    "@types/node": "^24.19.2",
    "@types/react": "^19.3.0",
    "@types/react-dom": "^19.3.0",
    "@vitejs/plugin-react": "^6.1.2",
    "oxlint": "^1.88.0",
    "typescript": "~6.0.3",
    "vite": "^8.3.4",
    "vite-plugin-pwa": "^2.0.0",
    "vitest": "^3.2.4"
  }
}
```

- [ ] **Step 2: Create `frontend/tsconfig.base.json`**

```json
{
  "compilerOptions": {
    "target": "es2023",
    "lib": ["ES2023", "DOM"],
    "module": "esnext",
    "resolveJsonModule": true,
    "allowArbitraryExtensions": true,
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "verbatimModuleSyntax": true,
    "moduleDetection": "force",
    "noEmit": true,
    "jsx": "react-jsx",
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "erasableSyntaxOnly": true,
    "noFallthroughCasesInSwitch": true
  }
}
```

- [ ] **Step 3: Create `frontend/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    include: ['packages/*/src/**/*.test.ts'],
  },
})
```

- [ ] **Step 4: Install and verify**

Run (from `frontend/`):
```bash
npm install
npm run lint
```
Expected: `npm install` succeeds with no `apps/*`/`packages/*` workspaces yet (empty glob matches are fine). `npm run lint` passes (it still lints the old, unmoved `src/` from the previous single app, which is untouched so far).

- [ ] **Step 5: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/tsconfig.base.json frontend/vitest.config.ts
git commit -m "Set up npm workspaces root for the frontend monorepo"
```

---

### Task 2: `packages/api-client`

**Files:**
- Create: `frontend/packages/api-client/package.json`
- Create: `frontend/packages/api-client/tsconfig.json`
- Create: `frontend/packages/api-client/src/index.ts`
- Test: `frontend/packages/api-client/src/index.test.ts`

**Interfaces:**
- Consumes: `frontend/tsconfig.base.json` (Task 1)
- Produces: `apiFetch<T>(path: string, init?: RequestInit): Promise<T>`, `getHealth(): Promise<HealthResponse>`, and the `HealthResponse` type — all exported from `@ttla/api-client`. Later app tasks import `getHealth` from this package.

- [ ] **Step 1: Create `frontend/packages/api-client/package.json`**

```json
{
  "name": "@ttla/api-client",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "types": "src/index.ts"
}
```

- [ ] **Step 2: Create `frontend/packages/api-client/tsconfig.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "include": ["src"]
}
```

- [ ] **Step 3: Write the failing test** — create `frontend/packages/api-client/src/index.test.ts`

```ts
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, getHealth } from './index'

describe('apiFetch', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns parsed JSON when the response is ok', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: { status: 'ok', timestamp: '2026-10-30T00:00:00Z' },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getHealth()

    expect(result.data.status).toBe('ok')
    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toContain('/health')
  })

  it('throws when the response is not ok', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 503 }))

    await expect(apiFetch('/health')).rejects.toThrow('503')
  })
})
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd frontend && npx vitest run packages/api-client`
Expected: FAIL — `./index` cannot be found (no `src/index.ts` yet).

- [ ] **Step 5: Create `frontend/packages/api-client/src/index.ts`**

```ts
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL

export interface HealthResponse {
  data: {
    status: string
    timestamp: string
  }
}

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, init)

  if (!response.ok) {
    throw new Error(`Request to ${path} failed with status ${response.status}`)
  }

  return response.json() as Promise<T>
}

export function getHealth(): Promise<HealthResponse> {
  return apiFetch<HealthResponse>('/health')
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend && npm install && npx vitest run packages/api-client`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add frontend/packages/api-client
git commit -m "Add shared api-client package"
```

---

### Task 3: `packages/auth`

**Files:**
- Create: `frontend/packages/auth/package.json`
- Create: `frontend/packages/auth/tsconfig.json`
- Create: `frontend/packages/auth/src/index.tsx`
- Test: `frontend/packages/auth/src/index.test.ts`

**Interfaces:**
- Consumes: `frontend/tsconfig.base.json` (Task 1); `react` (resolved from the hoisted root `node_modules` once an app depends on it — until an app exists this package type-checks but isn't bundled by anything yet)
- Produces: `getStoredToken(): string | null`, `storeToken(token: string): void`, `clearStoredToken(): void`, `AuthProvider` (React component), `useAuth(): AuthContextValue` where `AuthContextValue = { token: string | null; setToken: (token: string) => void; clearToken: () => void }` — all exported from `@ttla/auth`. Future auth-flow sub-projects wrap each app in `AuthProvider` and call `useAuth()`.

- [ ] **Step 1: Create `frontend/packages/auth/package.json`**

```json
{
  "name": "@ttla/auth",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.tsx",
  "types": "src/index.tsx",
  "peerDependencies": {
    "react": "^19.3.1"
  }
}
```

- [ ] **Step 2: Create `frontend/packages/auth/tsconfig.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "include": ["src"]
}
```

- [ ] **Step 3: Write the failing test** — create `frontend/packages/auth/src/index.test.ts`

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearStoredToken, getStoredToken, storeToken } from './index'

class MemoryStorage implements Storage {
  private store = new Map<string, string>()

  get length() {
    return this.store.size
  }

  clear() {
    this.store.clear()
  }

  getItem(key: string) {
    return this.store.get(key) ?? null
  }

  key(index: number) {
    return Array.from(this.store.keys())[index] ?? null
  }

  removeItem(key: string) {
    this.store.delete(key)
  }

  setItem(key: string, value: string) {
    this.store.set(key, value)
  }
}

describe('token storage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', new MemoryStorage())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns null when no token is stored', () => {
    expect(getStoredToken()).toBeNull()
  })

  it('stores and retrieves a token', () => {
    storeToken('abc123')

    expect(getStoredToken()).toBe('abc123')
  })

  it('clears a stored token', () => {
    storeToken('abc123')
    clearStoredToken()

    expect(getStoredToken()).toBeNull()
  })
})
```

- [ ] **Step 4: Run test to verify it fails**

Run: `cd frontend && npx vitest run packages/auth`
Expected: FAIL — `./index` cannot be found (no `src/index.tsx` yet).

- [ ] **Step 5: Create `frontend/packages/auth/src/index.tsx`**

```tsx
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

const TOKEN_STORAGE_KEY = 'ttla.authToken'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function storeToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearStoredToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

export interface AuthContextValue {
  token: string | null
  setToken: (token: string) => void
  clearToken: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setTokenState] = useState<string | null>(() => getStoredToken())

  const setToken = useCallback((next: string) => {
    storeToken(next)
    setTokenState(next)
  }, [])

  const clearToken = useCallback(() => {
    clearStoredToken()
    setTokenState(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ token, setToken, clearToken }),
    [token, setToken, clearToken],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }

  return context
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend && npm install && npx vitest run packages/auth`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add frontend/packages/auth
git commit -m "Add shared auth package"
```

---

### Task 4: `packages/i18n`

**Files:**
- Create: `frontend/packages/i18n/package.json`
- Create: `frontend/packages/i18n/tsconfig.json`
- Create: `frontend/packages/i18n/src/common/en.json`
- Create: `frontend/packages/i18n/src/common/bn.json`
- Create: `frontend/packages/i18n/src/index.ts`
- Test: `frontend/packages/i18n/src/index.test.ts`

**Interfaces:**
- Consumes: `frontend/tsconfig.base.json` (Task 1)
- Produces: `type Locale = 'en' | 'bn'`, `getCommonStrings(locale?: Locale): CommonStrings` where `CommonStrings` has the flat keys `'dashboard.backendStatus' | 'dashboard.statusLoading' | 'dashboard.statusOk' | 'dashboard.statusError'` — exported from `@ttla/i18n`. The `DashboardPlaceholder` page in each app task (6–8) reads these four keys.

- [ ] **Step 1: Create `frontend/packages/i18n/package.json`**

```json
{
  "name": "@ttla/i18n",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "types": "src/index.ts"
}
```

- [ ] **Step 2: Create `frontend/packages/i18n/tsconfig.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "include": ["src"]
}
```

- [ ] **Step 3: Create the locale data** — `frontend/packages/i18n/src/common/en.json`

```json
{
  "dashboard.backendStatus": "Backend connection status",
  "dashboard.statusLoading": "Checking connection...",
  "dashboard.statusOk": "Connected",
  "dashboard.statusError": "Backend unreachable"
}
```

`frontend/packages/i18n/src/common/bn.json`

```json
{
  "dashboard.backendStatus": "ব্যাকএন্ড সংযোগের অবস্থা",
  "dashboard.statusLoading": "সংযোগ পরীক্ষা করা হচ্ছে...",
  "dashboard.statusOk": "সংযুক্ত",
  "dashboard.statusError": "ব্যাকএন্ড সংযোগ করা যায়নি"
}
```

- [ ] **Step 4: Write the failing test** — create `frontend/packages/i18n/src/index.test.ts`

```ts
import { describe, expect, it } from 'vitest'
import { getCommonStrings } from './index'

describe('getCommonStrings', () => {
  it('defaults to English', () => {
    expect(getCommonStrings()['dashboard.statusOk']).toBe('Connected')
  })

  it('returns Bangla strings when requested', () => {
    expect(getCommonStrings('bn')['dashboard.statusOk']).toBe('সংযুক্ত')
  })
})
```

- [ ] **Step 5: Run test to verify it fails**

Run: `cd frontend && npx vitest run packages/i18n`
Expected: FAIL — `./index` cannot be found (no `src/index.ts` yet).

- [ ] **Step 6: Create `frontend/packages/i18n/src/index.ts`**

```ts
import en from './common/en.json'
import bn from './common/bn.json'

export type Locale = 'en' | 'bn'
export type CommonStrings = typeof en

const commonStrings: Record<Locale, CommonStrings> = { en, bn }

export function getCommonStrings(locale: Locale = 'en'): CommonStrings {
  return commonStrings[locale]
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd frontend && npm install && npx vitest run packages/i18n`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add frontend/packages/i18n
git commit -m "Add shared i18n package"
```

---

### Task 5: `packages/ui`

**Files:**
- Create: `frontend/packages/ui/package.json`
- Create: `frontend/packages/ui/tsconfig.json`
- Create: `frontend/packages/ui/src/layout/Sidebar.tsx`
- Create: `frontend/packages/ui/src/layout/AppShell.tsx`
- Create: `frontend/packages/ui/src/layout/AppShell.css`
- Create: `frontend/packages/ui/src/index.ts`

**Interfaces:**
- Consumes: `frontend/tsconfig.base.json` (Task 1); `react`, `react-dom`, `react-router-dom` (peer deps, resolved once an app depends on them)
- Produces: `AppShell` (React component, props `{ title: string; navItems: SidebarItem[] }`), `Sidebar` (React component, props `{ items: SidebarItem[] }`), `type SidebarItem = { to: string; label: string }` — all exported from `@ttla/ui`. `AppShell` renders an `<Outlet />` from `react-router-dom`, so a consuming app must render it inside a `<Route>` element. Each app task (6–8) wraps its single route in `<AppShell title="..." navItems={[{ to: '/', label: 'Dashboard' }]} />`.

No automated test for this task — it's presentational and gets verified visually when each app boots in Task 6–8.

- [ ] **Step 1: Create `frontend/packages/ui/package.json`**

```json
{
  "name": "@ttla/ui",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "types": "src/index.ts",
  "peerDependencies": {
    "react": "^19.3.1",
    "react-dom": "^19.3.1",
    "react-router-dom": "^7.18.4"
  }
}
```

- [ ] **Step 2: Create `frontend/packages/ui/tsconfig.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "include": ["src"]
}
```

- [ ] **Step 3: Create `frontend/packages/ui/src/layout/Sidebar.tsx`**

```tsx
import { NavLink } from 'react-router-dom'

export interface SidebarItem {
  to: string
  label: string
}

export interface SidebarProps {
  items: SidebarItem[]
}

export default function Sidebar({ items }: SidebarProps) {
  return (
    <nav className="sidebar" aria-label="Primary">
      <ul>
        {items.map((item) => (
          <li key={item.to}>
            <NavLink
              to={item.to}
              className={({ isActive }) => (isActive ? 'active' : undefined)}
              end={item.to === '/'}
            >
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
```

- [ ] **Step 4: Create `frontend/packages/ui/src/layout/AppShell.css`**

```css
.app-shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.app-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
  background: #1e293b;
  color: #fff;
  position: sticky;
  top: 0;
  z-index: 10;
}

.app-header h1 {
  font-size: 1.1rem;
  margin: 0;
}

.sidebar-toggle {
  display: none;
  background: transparent;
  border: none;
  color: #fff;
  font-size: 1.25rem;
  cursor: pointer;
}

.app-body {
  display: flex;
  flex: 1;
}

.app-sidebar {
  width: 220px;
  flex-shrink: 0;
  background: #f1f5f9;
  border-right: 1px solid #e2e8f0;
}

.app-sidebar nav ul {
  list-style: none;
  margin: 0;
  padding: 1rem 0;
}

.app-sidebar nav a {
  display: block;
  padding: 0.6rem 1.25rem;
  color: #1e293b;
  text-decoration: none;
}

.app-sidebar nav a.active,
.app-sidebar nav a:hover {
  background: #e2e8f0;
  font-weight: 600;
}

.app-content {
  flex: 1;
  padding: 1.5rem;
}

@media (max-width: 768px) {
  .sidebar-toggle {
    display: inline-block;
  }

  .app-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    transform: translateX(-100%);
    transition: transform 0.2s ease-in-out;
    z-index: 20;
  }

  .app-sidebar.open {
    transform: translateX(0);
  }
}
```

- [ ] **Step 5: Create `frontend/packages/ui/src/layout/AppShell.tsx`**

```tsx
import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar, { type SidebarItem } from './Sidebar'
import './AppShell.css'

export interface AppShellProps {
  title: string
  navItems: SidebarItem[]
}

export default function AppShell({ title, navItems }: AppShellProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="app-shell">
      <header className="app-header">
        <button
          type="button"
          className="sidebar-toggle"
          aria-label="Toggle navigation"
          aria-expanded={sidebarOpen}
          onClick={() => setSidebarOpen((open) => !open)}
        >
          ☰
        </button>
        <h1>{title}</h1>
      </header>

      <div className="app-body">
        <aside className={`app-sidebar ${sidebarOpen ? 'open' : ''}`}>
          <Sidebar items={navItems} />
        </aside>

        <main className="app-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
```

- [ ] **Step 6: Create `frontend/packages/ui/src/index.ts`**

```ts
export { default as AppShell, type AppShellProps } from './layout/AppShell'
export { default as Sidebar, type SidebarItem, type SidebarProps } from './layout/Sidebar'
```

- [ ] **Step 7: Install and verify no type errors**

Run: `cd frontend && npm install && npx tsc --noEmit -p packages/ui`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add frontend/packages/ui
git commit -m "Add shared ui package"
```

---

### Task 6: `apps/teacher`

**Files:**
- Create: `frontend/apps/teacher/package.json`
- Create: `frontend/apps/teacher/tsconfig.json`
- Create: `frontend/apps/teacher/tsconfig.app.json`
- Create: `frontend/apps/teacher/tsconfig.node.json`
- Create: `frontend/apps/teacher/vite.config.ts`
- Create: `frontend/apps/teacher/index.html`
- Create: `frontend/apps/teacher/.env`
- Create: `frontend/apps/teacher/.env.example`
- Create: `frontend/apps/teacher/public/favicon.svg` (copied from `frontend/public/favicon.svg`)
- Create: `frontend/apps/teacher/src/main.tsx`
- Create: `frontend/apps/teacher/src/vite-env.d.ts`
- Create: `frontend/apps/teacher/src/index.css`
- Create: `frontend/apps/teacher/src/App.tsx`
- Create: `frontend/apps/teacher/src/pages/DashboardPlaceholder.tsx`

**Interfaces:**
- Consumes: `getHealth` from `@ttla/api-client` (Task 2), `getCommonStrings` from `@ttla/i18n` (Task 4), `AppShell`/`SidebarItem` from `@ttla/ui` (Task 5)
- Produces: a standalone app on port 5173 — nothing later depends on this app's internals (student/management are siblings, not consumers)

- [ ] **Step 1: Create `frontend/apps/teacher/package.json`**

```json
{
  "name": "@ttla/app-teacher",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "@ttla/api-client": "*",
    "@ttla/auth": "*",
    "@ttla/i18n": "*",
    "@ttla/ui": "*",
    "react": "^19.3.1",
    "react-dom": "^19.3.1",
    "react-router-dom": "^7.18.4"
  }
}
```

- [ ] **Step 2: Create `frontend/apps/teacher/tsconfig.json`**

```json
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ]
}
```

- [ ] **Step 3: Create `frontend/apps/teacher/tsconfig.app.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.app.tsbuildinfo",
    "types": ["vite/client"]
  },
  "include": ["src"]
}
```

- [ ] **Step 4: Create `frontend/apps/teacher/tsconfig.node.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.node.tsbuildinfo",
    "types": ["node"]
  },
  "include": ["vite.config.ts"]
}
```

- [ ] **Step 5: Create `frontend/apps/teacher/vite.config.ts`**

```ts
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  server: {
    host: '127.0.0.1',
    port: 5173,
  },
  preview: {
    host: '127.0.0.1',
    port: 4173,
  },
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'Teacher Portal — TTLA Platform',
        short_name: 'Teacher Portal',
        description:
          'Teacher dashboard for the Teacher Training & Learning Analytics Platform',
        theme_color: '#1e293b',
        background_color: '#ffffff',
        display: 'standalone',
        start_url: '/',
        icons: [
          {
            src: 'favicon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
          },
        ],
      },
    }),
  ],
})
```

- [ ] **Step 6: Create `frontend/apps/teacher/index.html`**

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#1e293b" />
    <title>Teacher Portal | Teacher Training &amp; Learning Analytics Platform</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

- [ ] **Step 7: Create env files**

`frontend/apps/teacher/.env`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

`frontend/apps/teacher/.env.example`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

- [ ] **Step 8: Copy the favicon**

Run:
```bash
mkdir -p frontend/apps/teacher/public
cp frontend/public/favicon.svg frontend/apps/teacher/public/favicon.svg
```

- [ ] **Step 9: Create `frontend/apps/teacher/src/vite-env.d.ts`**

```ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
```

- [ ] **Step 10: Create `frontend/apps/teacher/src/index.css`**

```css
:root {
  color-scheme: light dark;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
}

.status-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1rem;
  max-width: 420px;
}

.status-card.status-ok {
  border-color: #22c55e;
}

.status-card.status-error {
  border-color: #ef4444;
}
```

- [ ] **Step 11: Create `frontend/apps/teacher/src/pages/DashboardPlaceholder.tsx`**

```tsx
import { useEffect, useState } from 'react'
import { getHealth } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

type Status = 'loading' | 'ok' | 'error'

const strings = getCommonStrings()

export default function DashboardPlaceholder() {
  const [status, setStatus] = useState<Status>('loading')
  const [timestamp, setTimestamp] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    getHealth()
      .then((result) => {
        if (cancelled) return
        setStatus('ok')
        setTimestamp(result.data.timestamp)
      })
      .catch(() => {
        if (cancelled) return
        setStatus('error')
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section>
      <h2>Dashboard</h2>
      <div className={`status-card status-${status}`}>
        <h3>{strings['dashboard.backendStatus']}</h3>
        {status === 'loading' && <p>{strings['dashboard.statusLoading']}</p>}
        {status === 'ok' && (
          <p>
            {strings['dashboard.statusOk']} — {timestamp}
          </p>
        )}
        {status === 'error' && <p>{strings['dashboard.statusError']}</p>}
      </div>
    </section>
  )
}
```

- [ ] **Step 12: Create `frontend/apps/teacher/src/App.tsx`**

```tsx
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AppShell } from '@ttla/ui'
import DashboardPlaceholder from './pages/DashboardPlaceholder'

const navItems = [{ to: '/', label: 'Dashboard' }]

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<AppShell title="Teacher Portal" navItems={navItems} />}>
          <Route index element={<DashboardPlaceholder />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}

export default App
```

- [ ] **Step 13: Create `frontend/apps/teacher/src/main.tsx`**

```tsx
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
```

- [ ] **Step 14: Install and build**

Run: `cd frontend && npm install && npm run build --workspace=apps/teacher`
Expected: succeeds, produces `frontend/apps/teacher/dist/`.

- [ ] **Step 15: Boot the dev server and verify**

Run (background): `cd frontend && npm run dev --workspace=apps/teacher`
Then: `curl -sf http://127.0.0.1:5173/ | grep -i "Teacher Portal"`
Expected: the dev server responds and the HTML contains "Teacher Portal". Stop the dev server afterward.

- [ ] **Step 16: Commit**

```bash
git add frontend/apps/teacher
git commit -m "Add teacher portal app"
```

---

### Task 7: `apps/student`

**Files:** same shape as Task 6, under `frontend/apps/student/`, with the differences below.

**Interfaces:** same as Task 6 — consumes `@ttla/api-client`, `@ttla/i18n`, `@ttla/ui`.

- [ ] **Step 1: Create `frontend/apps/student/package.json`**

```json
{
  "name": "@ttla/app-student",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "@ttla/api-client": "*",
    "@ttla/auth": "*",
    "@ttla/i18n": "*",
    "@ttla/ui": "*",
    "react": "^19.3.1",
    "react-dom": "^19.3.1",
    "react-router-dom": "^7.18.4"
  }
}
```

- [ ] **Step 2: Create `frontend/apps/student/tsconfig.json`**

```json
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ]
}
```

- [ ] **Step 3: Create `frontend/apps/student/tsconfig.app.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.app.tsbuildinfo",
    "types": ["vite/client"]
  },
  "include": ["src"]
}
```

- [ ] **Step 4: Create `frontend/apps/student/tsconfig.node.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.node.tsbuildinfo",
    "types": ["node"]
  },
  "include": ["vite.config.ts"]
}
```

- [ ] **Step 5: Create `frontend/apps/student/vite.config.ts`**

```ts
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  server: {
    host: '127.0.0.1',
    port: 5174,
  },
  preview: {
    host: '127.0.0.1',
    port: 4174,
  },
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'Student Portal — TTLA Platform',
        short_name: 'Student Portal',
        description:
          'Student dashboard for the Teacher Training & Learning Analytics Platform',
        theme_color: '#1e293b',
        background_color: '#ffffff',
        display: 'standalone',
        start_url: '/',
        icons: [
          {
            src: 'favicon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
          },
        ],
      },
    }),
  ],
})
```

- [ ] **Step 6: Create `frontend/apps/student/index.html`**

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#1e293b" />
    <title>Student Portal | Teacher Training &amp; Learning Analytics Platform</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

- [ ] **Step 7: Create env files**

`frontend/apps/student/.env`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

`frontend/apps/student/.env.example`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

- [ ] **Step 8: Copy the favicon**

Run:
```bash
mkdir -p frontend/apps/student/public
cp frontend/public/favicon.svg frontend/apps/student/public/favicon.svg
```

- [ ] **Step 9: Create `frontend/apps/student/src/vite-env.d.ts`**

```ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
```

- [ ] **Step 10: Create `frontend/apps/student/src/index.css`**

```css
:root {
  color-scheme: light dark;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
}

.status-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1rem;
  max-width: 420px;
}

.status-card.status-ok {
  border-color: #22c55e;
}

.status-card.status-error {
  border-color: #ef4444;
}
```

- [ ] **Step 11: Create `frontend/apps/student/src/pages/DashboardPlaceholder.tsx`**

```tsx
import { useEffect, useState } from 'react'
import { getHealth } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

type Status = 'loading' | 'ok' | 'error'

const strings = getCommonStrings()

export default function DashboardPlaceholder() {
  const [status, setStatus] = useState<Status>('loading')
  const [timestamp, setTimestamp] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    getHealth()
      .then((result) => {
        if (cancelled) return
        setStatus('ok')
        setTimestamp(result.data.timestamp)
      })
      .catch(() => {
        if (cancelled) return
        setStatus('error')
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section>
      <h2>Dashboard</h2>
      <div className={`status-card status-${status}`}>
        <h3>{strings['dashboard.backendStatus']}</h3>
        {status === 'loading' && <p>{strings['dashboard.statusLoading']}</p>}
        {status === 'ok' && (
          <p>
            {strings['dashboard.statusOk']} — {timestamp}
          </p>
        )}
        {status === 'error' && <p>{strings['dashboard.statusError']}</p>}
      </div>
    </section>
  )
}
```

- [ ] **Step 12: Create `frontend/apps/student/src/App.tsx`**

```tsx
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AppShell } from '@ttla/ui'
import DashboardPlaceholder from './pages/DashboardPlaceholder'

const navItems = [{ to: '/', label: 'Dashboard' }]

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<AppShell title="Student Portal" navItems={navItems} />}>
          <Route index element={<DashboardPlaceholder />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}

export default App
```

- [ ] **Step 13: Create `frontend/apps/student/src/main.tsx`**

```tsx
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
```

- [ ] **Step 14: Install and build**

Run: `cd frontend && npm install && npm run build --workspace=apps/student`
Expected: succeeds, produces `frontend/apps/student/dist/`.

- [ ] **Step 15: Boot the dev server and verify**

Run (background): `cd frontend && npm run dev --workspace=apps/student`
Then: `curl -sf http://127.0.0.1:5174/ | grep -i "Student Portal"`
Expected: the dev server responds and the HTML contains "Student Portal". Stop the dev server afterward.

- [ ] **Step 16: Commit**

```bash
git add frontend/apps/student
git commit -m "Add student portal app"
```

---

### Task 8: `apps/management`

**Files:** same shape as Task 6, under `frontend/apps/management/`, with the differences below.

**Interfaces:** same as Task 6 — consumes `@ttla/api-client`, `@ttla/i18n`, `@ttla/ui`.

- [ ] **Step 1: Create `frontend/apps/management/package.json`**

```json
{
  "name": "@ttla/app-management",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "@ttla/api-client": "*",
    "@ttla/auth": "*",
    "@ttla/i18n": "*",
    "@ttla/ui": "*",
    "react": "^19.3.1",
    "react-dom": "^19.3.1",
    "react-router-dom": "^7.18.4"
  }
}
```

- [ ] **Step 2: Create `frontend/apps/management/tsconfig.json`**

```json
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ]
}
```

- [ ] **Step 3: Create `frontend/apps/management/tsconfig.app.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.app.tsbuildinfo",
    "types": ["vite/client"]
  },
  "include": ["src"]
}
```

- [ ] **Step 4: Create `frontend/apps/management/tsconfig.node.json`**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.node.tsbuildinfo",
    "types": ["node"]
  },
  "include": ["vite.config.ts"]
}
```

- [ ] **Step 5: Create `frontend/apps/management/vite.config.ts`**

```ts
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  server: {
    host: '127.0.0.1',
    port: 5175,
  },
  preview: {
    host: '127.0.0.1',
    port: 4175,
  },
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'Management Portal — TTLA Platform',
        short_name: 'Management Portal',
        description:
          'Management dashboard for the Teacher Training & Learning Analytics Platform',
        theme_color: '#1e293b',
        background_color: '#ffffff',
        display: 'standalone',
        start_url: '/',
        icons: [
          {
            src: 'favicon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
          },
        ],
      },
    }),
  ],
})
```

- [ ] **Step 6: Create `frontend/apps/management/index.html`**

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#1e293b" />
    <title>Management Portal | Teacher Training &amp; Learning Analytics Platform</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

- [ ] **Step 7: Create env files**

`frontend/apps/management/.env`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

`frontend/apps/management/.env.example`
```
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

- [ ] **Step 8: Copy the favicon**

Run:
```bash
mkdir -p frontend/apps/management/public
cp frontend/public/favicon.svg frontend/apps/management/public/favicon.svg
```

- [ ] **Step 9: Create `frontend/apps/management/src/vite-env.d.ts`**

```ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
```

- [ ] **Step 10: Create `frontend/apps/management/src/index.css`**

```css
:root {
  color-scheme: light dark;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
}

.status-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1rem;
  max-width: 420px;
}

.status-card.status-ok {
  border-color: #22c55e;
}

.status-card.status-error {
  border-color: #ef4444;
}
```

- [ ] **Step 11: Create `frontend/apps/management/src/pages/DashboardPlaceholder.tsx`**

```tsx
import { useEffect, useState } from 'react'
import { getHealth } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

type Status = 'loading' | 'ok' | 'error'

const strings = getCommonStrings()

export default function DashboardPlaceholder() {
  const [status, setStatus] = useState<Status>('loading')
  const [timestamp, setTimestamp] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    getHealth()
      .then((result) => {
        if (cancelled) return
        setStatus('ok')
        setTimestamp(result.data.timestamp)
      })
      .catch(() => {
        if (cancelled) return
        setStatus('error')
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section>
      <h2>Dashboard</h2>
      <div className={`status-card status-${status}`}>
        <h3>{strings['dashboard.backendStatus']}</h3>
        {status === 'loading' && <p>{strings['dashboard.statusLoading']}</p>}
        {status === 'ok' && (
          <p>
            {strings['dashboard.statusOk']} — {timestamp}
          </p>
        )}
        {status === 'error' && <p>{strings['dashboard.statusError']}</p>}
      </div>
    </section>
  )
}
```

- [ ] **Step 12: Create `frontend/apps/management/src/App.tsx`**

```tsx
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AppShell } from '@ttla/ui'
import DashboardPlaceholder from './pages/DashboardPlaceholder'

const navItems = [{ to: '/', label: 'Dashboard' }]

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<AppShell title="Management Portal" navItems={navItems} />}>
          <Route index element={<DashboardPlaceholder />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}

export default App
```

- [ ] **Step 13: Create `frontend/apps/management/src/main.tsx`**

```tsx
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
```

- [ ] **Step 14: Install and build**

Run: `cd frontend && npm install && npm run build --workspace=apps/management`
Expected: succeeds, produces `frontend/apps/management/dist/`.

- [ ] **Step 15: Boot the dev server and verify**

Run (background): `cd frontend && npm run dev --workspace=apps/management`
Then: `curl -sf http://127.0.0.1:5175/ | grep -i "Management Portal"`
Expected: the dev server responds and the HTML contains "Management Portal". Stop the dev server afterward.

- [ ] **Step 16: Commit**

```bash
git add frontend/apps/management
git commit -m "Add management portal app"
```

---

### Task 9: Remove legacy single-app files, update docs, final verification

**Files:**
- Delete: `frontend/src/`, `frontend/index.html`, `frontend/vite.config.ts`, `frontend/tsconfig.json`, `frontend/tsconfig.app.json`, `frontend/tsconfig.node.json`, `frontend/.env`, `frontend/.env.example`, `frontend/public/`, `frontend/dist/` (if present)
- Modify: `frontend/README.md`
- Modify: `docs/ARCHITECTURE.md`

**Interfaces:**
- Consumes: all of Tasks 1–8 (this task only deletes now-superseded files and re-verifies the whole workspace)
- Produces: nothing new consumed by later work — this is the terminal task of this plan

- [ ] **Step 1: Delete the legacy single-app files**

Run:
```bash
cd frontend
rm -rf src index.html vite.config.ts tsconfig.json tsconfig.app.json tsconfig.node.json .env .env.example public dist
```

- [ ] **Step 2: Replace `frontend/README.md`**

```markdown
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
```

- [ ] **Step 3: Update `docs/ARCHITECTURE.md`**

Replace the `## 1. High-Level Layout` section (and its surrounding "ONLY frontend app" framing) with:

```markdown
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
```

Also update the `## 3. Frontend Architecture` table's "Structure" row to read:

```markdown
| Structure | npm-workspaces monorepo: `apps/{teacher,student,management}` (each its own Vite app, port, and PWA manifest) + `packages/{api-client,auth,ui,i18n}` (shared, source-only, no build step) |
```

- [ ] **Step 4: Full workspace verification**

Run (from `frontend/`):
```bash
npm install
npm run lint
npm run test
npm run build
```
Expected: all four commands succeed — lint passes across `apps/*` and `packages/*`, Vitest passes all package tests, and `build` produces `dist/` for all three apps.

- [ ] **Step 5: Commit**

```bash
git add -A frontend docs/ARCHITECTURE.md
git commit -m "Remove legacy single-app frontend files and document the monorepo"
```
