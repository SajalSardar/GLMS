# School Admin Tenant Scoping & Menu Parity — Design

## Problem Statement

Two related bugs, reported together by the user and verified against the seeded account `school.admin@tlap.test` (school_id=1):

1. **Data leak.** A school_admin can currently view and (for some resources) manage data belonging to every school, not just their own. Confirmed in tinker: an unauthenticated `ClassVersion::count()` returns 71 rows across all 16 schools; the same query made while authenticated as a school_admin should return only their own 5 rows, but today it doesn't, because the authenticated actor is never resolved on the routes these pages call.

2. **Menu mismatch.** The intended design (per the user) is that a school_admin sees the same sidebar menu as a national_admin, minus "System Setup". Today school_admin is missing four whole menu groups — Class Materials, Exam Control, Holiday Calendar, and School Setup — even though the seeded `RolePermissionSeeder` already grants school_admin `.view` permission on every resource under them. The route guards (`RequireNationalAdmin`) simply block the pages outright, regardless of permission.

Both bugs stem from the same underlying gap: tenant scoping and permission-aware UI were only ever built for the pages a school_admin could already reach (Teachers, Students, Attendance, Publish Results, Promote Students, Routine, and the flat class-config pages). The newer and more "national-admin-only-by-default" pages never got the same treatment.

## Goals

- A school_admin sees every sidebar menu a national_admin sees, except System Setup.
- Every list, filter, and create/edit flow a school_admin uses is scoped to their own school — consistently, not resource-by-resource.
- What actions (create/edit/delete) a school_admin can perform within that scope is driven by their role's permissions (already configurable by national_admin via the existing Roles/Permissions admin UI) — both enforced server-side (already true almost everywhere) and reflected in the UI (buttons for actions they lack permission for should not mislead them into a 403).
- national_admin's own behavior is unchanged: unrestricted visibility and action, same as today.
- Anonymous/public callers (student portal, teacher portal, public catalog browsing) keep working exactly as before — none of this fix may require authentication where it isn't already required.

## Non-Goals

- Redesigning the permission system itself (roles, permission names, the Roles/Permissions admin screen) — out of scope, already exists and works.
- District-scoped actors (`district_id` set, `school_id` null) — `TenantScope` already has a documented branch for these; this work does not change or test that branch beyond confirming the guard fix doesn't regress it.
- Any resource confirmed as genuinely national (no school dimension at all): `SchoolClass`, `AcademicYear`, `Holiday`, `AcademicExamTerm`, `AcademicGradeConfigure`, `AcademicSubjectMarkConfigure`. These stay as unrestricted reference data for every role; only their *menu visibility* changes (per Goal 1), not their scoping.
- Retrofitting permission-gated UI onto every existing national-admin-only page (e.g. Divisions/Districts/Upazilas under System Setup). Only pages newly reachable by school_admin as part of this work get the treatment — see "Permission-gated UI" below.

## Root Cause

`backend/app/Models/Scopes/TenantScope.php:49` resolves the acting user via `Auth::user()`, which reads Laravel's *default* guard. The default guard is `web` (session-based — see `backend/config/auth.php:19`), and only the `auth:sanctum` middleware switches the default guard to `sanctum` for the duration of a request (`Auth::shouldUse('sanctum')`). Most of the resources a school_admin actually browses are registered in `backend/routes/api.php`'s public, middleware-free route group (`school`s, `class-versions`, `shifts`, `sections`, `subject-teacher-assignments`, plus `books`/`chapters`/`chapter-materials`/`chapter-videos`/`class-routines`) — deliberately, because the same endpoints also serve genuinely anonymous callers (the public catalog, student/teacher portals). On those routes `auth:sanctum` never runs, so `Auth::user()` returns `null` even when a valid bearer token (or, for the student app, a valid Sanctum SPA session cookie) was sent — and `TenantScope::apply()` treats `null` the same as "anonymous", which is correct for anonymous requests but wrong for an authenticated school_admin hitting the same route.

This is confirmed, not hypothesized: `php artisan tinker` with no actor shows `ClassVersion::count()` = 71; `Auth::login()`'ing the school_admin account and re-running shows 5. The scoping logic itself is correct; it's simply never given an actor to scope against on these routes.

## Design

### 1. Centralized fix: `TenantScope` resolves the actor via the `sanctum` guard directly

Laravel Sanctum registers its own `sanctum` guard at boot (`vendor/laravel/sanctum/src/SanctumServiceProvider.php`), independent of any middleware. That guard's resolution logic (`vendor/laravel/sanctum/src/Guard.php:30-62`) already handles both of this app's auth mechanisms transparently: it first checks the configured stateful guard (session — the student app's cookie-based SPA auth) and falls back to bearer-token lookup (the management/teacher apps). So instead of asking the *default* guard, `TenantScope::apply()` should ask the `sanctum` guard explicitly:

```php
$user = Auth::guard('sanctum')->user();
```

This is a one-line change. It:
- Correctly scopes `School`, `ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment` (all already `TenantScoped`) the moment a valid actor is present, on every route, with no route or controller changes.
- Is a no-op behavior change for every route already behind `auth:sanctum` (the default guard there is already `sanctum`, so `Auth::user()` and `Auth::guard('sanctum')->user()` already returned the same thing).
- Preserves exactly the current behavior for genuinely anonymous requests (no cookie session, no bearer token) — `Auth::guard('sanctum')->user()` returns `null` there too, so public/student/teacher-portal reads of these same resources are untouched.
- Does not change the trait's existing `School` vs. other-model branch, or the district-scoped branch, or the re-entrancy guard — those stay as-is.

### 2. Explicit scoping for resources without a `school_id` column

`Book`, `Chapter`, `ChapterMaterial`, `ChapterVideo` have no `school_id`/`district_id` column and don't use `TenantScoped` — they reach "their" school only transitively (`Book.class_version_id -> ClassVersion.school_id`, and one/two more hops for `Chapter`/`ChapterMaterial`/`ChapterVideo`). The centralized fix above doesn't cover these. Each of their controllers' `index()`/`show()` needs to:

1. Resolve the actor the same way `TimetableEntryController`/`ChapterVideoController` already do: `$request->user('sanctum')` (equivalent to the guard call above, already the established pattern in this codebase for a controller that needs the actor explicitly rather than through a model scope).
2. If the actor is school-scoped (`$actor?->school_id !== null`), add an unconditional `whereHas(..., fn ($q) => $q->where('school_id', $actor->school_id))` filter through the appropriate relation chain — *in addition to* whatever request-driven filters (`class_id`, `subject_id`, etc.) already exist. This is not an optional query parameter the frontend opts into; it's always applied for a school-scoped actor, the same way `TenantScope` is unconditional for the models it covers.
3. Leave the query entirely unrestricted for `null` (anonymous) or unscoped (`national_admin`) actors — unchanged from today.

`ClassRoutineController::index()` gets the same treatment, mirroring its sibling `TimetableEntryController::index()`, which already implements this exact pattern correctly — use it as the reference implementation rather than inventing a new shape.

### 3. Write-side ownership validation

Confirm, and fix if missing, that `store()`/`update()` on `Book`, `Chapter`, `ChapterMaterial`, `ChapterVideo` reject a school-scoped actor attempting to attach a record to a `class_version_id` (or, transitively, a `book_id`/`chapter_id`) that does not belong to their own school — not just that they hold the `*.create`/`*.update` permission. Today's policies (`BookPolicy`, etc.) check only the permission string, not row ownership — that's correct for `national_admin` (who has no school to be confined to) but insufficient on its own for `school_admin`. Where the referenced parent (`ClassVersion`, `Book`, `Chapter`) is itself `TenantScoped` or reachable through one, resolving it via `findOrFail()` under the now-correctly-scoped guard (§1) will already 404 a cross-school reference automatically, the same way it already does today for `Shift`/`Section`/`ClassVersion` writes (confirmed in the investigation: `School::findOrFail(2)` under a school_admin actor throws `ModelNotFoundException`). Verify this holds for each write path during implementation; add an explicit check only where the automatic scoping doesn't already cover it.

`ClassVersion`/`Shift`/`Section`/`SubjectTeacherAssignment`/`School` writes are already confirmed correct today (they sit behind `auth:sanctum`, so §1's fix doesn't change their behavior) — no action needed there beyond the regression tests in the Testing section.

### 4. Frontend: unblock the four menus for school_admin

In `frontend/apps/management/src/App.tsx`:
- Change the route guard wrapping Class Materials, Exam Control, Holiday Calendar, and School Setup from `RequireNationalAdmin` to `RequireSchoolManager` (the latter already exists and is used by the pages school_admin can currently reach).
- Update the sidebar `navItems` construction so these four nav groups are included for `school_admin`, not just `national_admin` — mirroring the pattern already used for `schoolManagedNavItems`.
- "System Setup" (Divisions/Districts/Upazilas/Subjects) stays `RequireNationalAdmin`/national_admin-only — the one explicit exception the user named.

No change to which resources are reachable — only who can reach the route. Scoping (§1, §2) is what makes the contents correct once reachable.

### 5. Permission-gated UI (new, scoped tightly)

The login response already includes `role.permissions: string[]` (`PortalUser.role.permissions` in `frontend/packages/api-client/src/index.ts`), but nothing stores or uses it client-side today, and the shared `GeoCrudTable` component unconditionally renders Add/Edit/Delete for every page. This isn't a security gap — every write endpoint already enforces permissions server-side via policies — but it means a user without a given permission sees an action they can't actually use and gets a bare 403.

- `@ttla/auth`'s context gains a `permissions: string[]` field (stored the same way `role`/`name`/etc. already are) and a small `can(permission: string): boolean` helper.
- `GeoCrudTable` gains optional `createPermission?: string`, `updatePermission?: string`, `deletePermission?: string` props. When provided, the corresponding button/action is hidden (not just disabled — matching how `onAdd`/`onEdit` already let a page override default behavior) unless `useAuth().can(permission)` is true. When omitted, behavior is unchanged (button always shown) — this keeps every existing page's behavior identical by default.
- Wire these props into the pages newly reachable by school_admin from §4 (Books, Chapters, Chapter Materials, Chapter Videos, Holidays, the three Academic Exam config pages, School Setup), using the exact permission strings already defined in `RolePermissionSeeder` (e.g. `books.create`/`books.update`/`books.delete`). Retrofitting this onto already-existing national-admin-only pages is out of scope (see Non-Goals) — national_admin holds every permission, so it would be a no-op for them anyway.

### School Setup specifics

Once §1 lands, `School::index()`/`show()` automatically narrows to the actor's own school for a school_admin (the scope's existing `School`-specific branch filters by `id = user.school_id`, already implemented, just newly activated). No backend change needed for Schools specifically beyond §1. On the frontend, the School Setup page will then show school_admin exactly one row — their own school — and (via §5) the Add/Delete actions should not render for them at all, since `RolePermissionSeeder` grants school_admin `schools.view`/`schools.update` only (no `.create`/`.delete`).

## Testing Strategy

**Backend (feature tests, extending existing suites where a resource already has one):**
- For each of `School`, `ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment`: an index/show test asserting a school_admin actor sees only their own school's rows, mirroring the existing `test_index_filters_by_the_national_version_id`-style cross-school test pattern from this session's Books/Chapters/ChapterVideos work (two schools seeded, assert the count and identity of what comes back).
- Same pattern for `Book`, `Chapter`, `ChapterMaterial`, `ChapterVideo`, `ClassRoutine` (the §2 explicit-filter resources).
- A write-side test per resource in §3 confirming a school_admin's attempt to create/attach against another school's parent record is rejected (404 or 422/403, whichever the existing `findOrFail`/policy pattern produces — match existing conventions, don't invent a new error shape).
- A regression test confirming `national_admin` and anonymous/unauthenticated requests are unrestricted on every touched resource — this is the "didn't break public access" guarantee and is at least as important as the new restriction.
- A regression test confirming a district-scoped actor (`district_id` set, `school_id` null) still sees only their own district's rows after the `TenantScope` guard change — no new behavior intended here, just confirming the existing branch isn't disturbed.
- A test that specifically exercises the `TenantScope` re-entrancy path under the new guard call — e.g. an authenticated request against any `TenantScoped` model that doesn't hang or recurse — per the re-entrancy risk noted below.

**Frontend:** live browser verification logged in as `school.admin@tlap.test`:
- Sidebar matches national_admin minus System Setup.
- Every newly-reachable list (Books, Chapters, Class Videos, Holidays, Exam Control pages, School Setup) shows only school 1's data.
- Add/Edit/Delete buttons on those pages reflect the seeded school_admin permissions (present only where `RolePermissionSeeder` grants the matching `.create`/`.update`/`.delete`).
- Spot-check that `national_admin` login still sees everything, unrestricted, exactly as before (no regression).

## Risks

- `Auth::guard('sanctum')->user()` runs inside `TenantScope::apply()`, which fires on every query against every `TenantScoped` model — including, per the trait's own existing re-entrancy comment, queries that are themselves part of resolving the Sanctum guard's own actor (e.g. loading a `PersonalAccessToken`'s `tokenable` row). The existing `$resolvingActor` re-entrancy guard already handles this for `Auth::user()` and needs no changes for `Auth::guard('sanctum')->user()` — same call shape, same guard variable — but this must be explicitly verified with a test (an authenticated request against a `TenantScoped` model must not recurse/hang), not just asserted.
- Changing route guards in `App.tsx` must not expand *national_admin's* own visibility or behavior — only school_admin's. Verify via a regression check, not just a diff read.
- `whereHas()` filters added to `Book`/`Chapter`/`ChapterMaterial`/`ChapterVideo` must use the correct relation path depth for each model (one hop for `Book`, two for `Chapter`, three for `ChapterMaterial`/`ChapterVideo` — chapter.book.classVersion for the latter two, matching the same dot-notation pattern already proven correct in this session's earlier Chapters/Class Videos work) — get this wrong and it either leaks (too shallow) or breaks entirely (wrong relation name).
