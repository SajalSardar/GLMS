# Design: Multi-Portal Authentication & Authorization

**Status:** Approved, ready for implementation planning
**Sub-project:** 2 of 5 (see `docs/superpowers/specs/2026-10-30-frontend-monorepo-restructure-design.md` for the full program decomposition)

## Scope correction (important context)

The program decomposition's original description of this sub-project
("multi-portal auth & authorization ... not yet built") undersold what
already exists in the backend. Investigation during brainstorming found a
mature RBAC foundation already in place:

- 10 seeded roles (`national_admin`, `district_officer`, `school_admin`,
  `teacher`, `student`, `trainer`, `office_staff`, `parent_guardian`,
  `it_admin`, `auditor`) with a full permission catalog and a
  `RolePermissionSeeder` mapping.
- 19 Policy classes (one per domain model), each checking
  `$user->hasPermission(...)` plus a school/district scope check via a
  shared `ChecksScope` trait (`inScope($user, $targetSchoolId,
  $targetDistrictId)`).
- A single `AuthController` with one `/auth/login` endpoint, resolving by
  `email` OR `phone`, issuing a Sanctum bearer token.

This sub-project does **not** build auth from scratch. It is narrower:

1. Add three portal-specific login endpoints (teacher/student/management).
2. Migrate tenant isolation from manual per-Policy/per-controller checks to
   Eloquent global scopes, closing a real gap found during investigation
   (`TeacherProfileController::update()` was missing its `authorize()`
   call entirely — exactly the silent-leak failure mode a global scope
   prevents by construction).
3. Map roles to portals and enforce that boundary at login.

## Architecture mismatches found and resolved

Two documented-target-vs-implementation mismatches were found and decided:

1. **Auth transport.** `docs/API_SPECIFICATION.md` documented cookie-based
   Sanctum SPA session auth as the target ("Bearer tokens are not used for
   the first-party SPA"). The already-implemented `AuthController` issues
   a bearer token, and the frontend's `packages/auth` (built in
   sub-project 1) stores it in `localStorage`, consistent with the
   implementation. **Decision: keep bearer tokens.** Simpler for three
   separate-subdomain SPAs (no shared-cookie-domain configuration needed
   across `teacher.management.gov.bd` / `student.management.gov.bd` /
   `management.gov.bd`). `docs/API_SPECIFICATION.md` and
   `docs/ARCHITECTURE.md` must be updated to describe this as the real
   target, not migrated away from.
2. **Tenant isolation mechanism.** `docs/DATABASE_DESIGN.md` documented
   isolation via an Eloquent global scope ("never relying on the frontend
   to filter by school"), but what's implemented is manual per-Policy
   checks plus explicit `->visibleTo($request->user())` calls controllers
   must remember to add. **Decision: migrate to global scopes**, matching
   the original documented target and closing the found gap by
   construction (see "Global scope design" below).

## Portal-to-role mapping

| Portal | Allowed roles |
|---|---|
| Teacher Portal | `teacher` |
| Student Portal | `student` |
| Management Portal | `national_admin`, `district_officer`, `school_admin` |

`trainer`, `office_staff`, `it_admin`, `auditor`, and `parent_guardian`
exist in the role/permission system but are **not** assigned to any portal
in this sub-project — no login endpoint accepts them. A login attempt by a
user with one of these roles is rejected with the same generic message as
a wrong password (see "Login endpoints" below). Assigning them a portal is
future work, not decided here.

## Login endpoints

Three new endpoints, each portal-specific:

```
POST /api/v1/auth/teacher/login      { login: "<employee_id|phone|email>", password, device_name? }
POST /api/v1/auth/student/login      { login: "<student_id>",              password, device_name? }
POST /api/v1/auth/management/login   { login: "<phone|email>",             password, device_name? }
```

Each endpoint:

1. Resolves `login` against the identifier(s) valid for that portal:
   - Teacher: `teacher_profiles.employee_id` (joined to its `user_id`), or
     `users.phone`, or `users.email`.
   - Student: `student_profiles.student_id` (joined to its `user_id`).
     Student credentials are the existing `users.password` column with
     relaxed validation (numeric-only PIN allowed, shorter minimum length)
     — no new `pin` column.
   - Management: `users.phone` or `users.email` (same as the existing
     `AuthController::login`).
2. Verifies the password against the resolved user's `users.password`.
3. Checks the resolved user's `role.name` is in that portal's allowed set
   (table above).
4. On any failure in 1-3 (identifier not found, wrong password, right
   credentials but wrong-portal role), returns the **same generic**
   "credentials incorrect" validation error — a login endpoint never
   reveals which part was wrong, including whether the issue was the
   portal/role mismatch.
5. On success, issues a Sanctum bearer token exactly as the existing
   `AuthController::login` does (rate limiting via `RateLimiter`, token
   via `createToken($deviceName)`, response via `UserResource` + token).

The existing generic `/auth/login` endpoint is retained as-is (it has no
portal/role restriction) — it is not part of any of the three portals'
login flow going forward, but removing it is out of scope here (no harm in
leaving it; a future cleanup can retire it once all three frontends are
confirmed to use their portal-specific endpoint exclusively).

## Global scope design

A `TenantScoped` trait, applied to the models below, registers a global
scope in `booted()`:

```php
trait TenantScoped
{
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null) {
            return; // public index/show routes are unaffected
        }

        if ($user->school_id !== null) {
            $column = $model instanceof School ? 'id' : 'school_id';
            $builder->where($model->getTable().'.'.$column, $user->school_id);
            return;
        }

        if ($user->district_id !== null) {
            $builder->where($model->getTable().'.district_id', $user->district_id);
        }

        // unscoped (e.g. national_admin): no restriction
    }
}
```

**Models that get `TenantScoped`** (direct `school_id`/`district_id`
column, confirmed against migrations): `User`, `TeacherProfile`,
`StudentProfile`, `SchoolClass`, `Classroom`, `ClassRoutine`,
`ClassVersion`, `Shift`, `Section`, `SubjectTeacherAssignment`,
`TimetableEntry`, `AcademicYear`, and `School` (special case: scoped by
its own `id`, not a `school_id` column, per the `TenantScope` class above).

**Models that do NOT get it** (no direct `school_id`/`district_id`
column — scoped indirectly through a relationship instead): `Guardian`
and `StudentSectionTransfer` keep their existing bespoke `scopeVisibleTo`
(and `Guardian`'s `isVisibleTo`) methods unchanged. `Subject`, `Chapter`,
`Division`, `District`, `Upazila`, `Role`, and `Permission` are shared
reference data, not tenant-owned, and must never get this trait.

**Consequences of adding the global scope:**

- Implicit route-model-binding (`show(TeacherProfile $teacher_profile)`)
  now 404s automatically for a foreign-tenant ID, before the controller or
  Policy runs.
- The 19 Policies' `inScope`/`ChecksScope` logic becomes redundant for
  *fetching* a specific instance (you can no longer load a foreign-tenant
  row to check it against) — simplify each affected Policy's `view`,
  `update`, `delete` methods to drop the `inScope` check and keep only the
  permission check. `viewAny`/`create` are unaffected (no instance to
  scope).
- The explicit `->visibleTo($request->user())` calls in
  `StudentProfileController`, `UserController`, `GuardianController`, and
  `TeacherProfileController` become redundant for the `TenantScoped`
  models and should be removed from those controllers' `index()` methods
  (the global scope applies automatically to the query). `GuardianController`
  keeps calling `Guardian::scopeVisibleTo` as before — `Guardian` is not
  `TenantScoped`.
- The `scopeVisibleTo` local-scope methods already defined on `User`,
  `TeacherProfile`, and `StudentProfile` become dead code once the global
  scope does the same filtering automatically — remove them.
- Fixes the found gap: `TeacherProfileController::update()` never called
  `$this->authorize('update', $teacher_profile)`. Once `TeacherProfile` is
  `TenantScoped`, the model can no longer even be fetched cross-tenant, so
  the leak is closed structurally — but the missing `authorize()` call
  should still be added for permission-check completeness (a same-school
  teacher with no `teacher_profiles.update` permission must still be
  blocked).

## Testing

Per `docs/DEVELOPMENT_ROADMAP.md`'s own Phase 1 completion criteria
("automated cross-tenant leakage tests passing in CI — log in as School A,
confirm every endpoint 403/404s against School B's data"):

- A cross-tenant leakage feature-test suite: seed two schools (A and B)
  with a full set of tenant-owned records each, log in as a School A
  school-scoped user, and assert 404 against every School B record ID for
  every `TenantScoped` model's `show`/`update`/`delete` endpoints.
- A focused test on `TenantScope` itself: an unscoped `national_admin` sees
  rows from both schools in an index query; a school-scoped user sees only
  their own school's rows; a district-scoped user (`school_id` null,
  `district_id` set) sees only their district's rows.
- Feature tests for each of the three new login endpoints: correct
  credentials succeed and return a token; wrong password fails with the
  generic message; correct credentials for a role not in that portal's
  allowed set fails with the same generic message.
- A regression test confirming the existing generic `/auth/login` endpoint
  still works unchanged.

## Out of scope for this sub-project

- No frontend login UI/forms — each portal's own sub-project builds its
  login page against these endpoints; the shared `packages/auth`
  (`AuthProvider`/`setToken`) built in sub-project 1 is already ready to
  receive whatever token these endpoints return.
- No MFA — mentioned in `docs/ARCHITECTURE.md`'s Phase 2 auth target, but
  not requested by the original 3-portal requirement. Deferred.
- No new `portals` database table — the role-to-portal mapping is a small
  fixed PHP array/config, not a schema addition.
- No admin UI for managing the role-to-portal mapping.
- No new "Teacher ID"/"Student ID" columns — they are the existing
  `teacher_profiles.employee_id` and `student_profiles.student_id`
  columns.
- No change to the existing generic `/auth/login` endpoint beyond leaving
  it in place (see "Login endpoints" above).
- No work on `trainer`/`office_staff`/`it_admin`/`auditor`/`parent_guardian`
  portal access — they remain unassigned to any portal.

## Definition of done

- Three new login endpoints exist, each resolving its own identifier type,
  enforcing its own role allowlist, and returning the same generic error
  shape on any failure.
- `TenantScope` + `TenantScoped` trait exist and are applied to the 12
  listed models (`School` plus the 11 others); `Guardian` and
  `StudentSectionTransfer` are confirmed untouched; shared reference
  models are confirmed untouched.
- The 19 Policies are simplified where applicable (scope check dropped,
  permission check kept); the four controllers' redundant `->visibleTo()`
  calls are removed; the three models' dead `scopeVisibleTo` methods are
  removed; `TeacherProfileController::update()`'s missing `authorize()`
  call is added.
- The cross-tenant leakage test suite, the `TenantScope` unit/feature
  tests, and the three login endpoints' feature tests all pass.
- `docs/API_SPECIFICATION.md` and `docs/ARCHITECTURE.md` are updated to
  document bearer-token auth as the real target (not cookie-based SPA
  sessions) and the global-scope tenant isolation mechanism as actually
  implemented, replacing the stale descriptions.
