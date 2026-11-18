# Design: Management Frontend Class-Wise Filter Fixes

**Status:** Approved, ready for implementation planning
**Sub-project:** 4 of 5 (see "Program decomposition" below)

## Program decomposition (context for this and future specs)

Carried from `docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md`'s
own program decomposition (sub-projects 1-3, already merged):

1. De-scope national content + remove video progress — **done, merged**.
2. Restructure `school_classes` into a national list + per-school
   assignment (`class_schools`) — **done, merged**.
3. Public/guest exam-taking — **done, merged**.
4. **Management frontend class-wise filter fixes** (this spec) — depends
   on (2).
5. New public student frontend — depends on (2) and (3).

Each sub-project gets its own spec → plan → implementation cycle.

## Scope of this spec

Sub-project 2 made `school_classes` a national list of class names with a
new `class_schools` pivot recording per-school adoption, and moved
`class_subjects` from a global fact to a per-(school, class) fact. That
spec's own "out of scope" section named three pages as broken
(`ClassesPage.tsx`, `ClassVersionsPage.tsx`, `StudentDetailPage.tsx`) and
flagged that "this affects more of the frontend surface than just the
three pages named above." Grepping the management frontend for
`SchoolClass`/`listClasses`/`class_id` usage confirms a wider surface: 8
pages reference the old per-school `SchoolClass` shape, and there is no
frontend at all yet for the new `ClassSchoolController` API — nothing
lets a school admin adopt a class or manage that offering's subject list.
This spec covers the full surface. Backend only requires no further
changes (sub-project 2 already finished and merged all backend work this
depends on). This is entirely a `frontend/apps/management` + shared
`frontend/packages/api-client` change.

## Current state vs. target (per file)

Confirmed by reading each file; grouped by how badly each is broken.

**Hard breaks (feature currently produces wrong/broken behavior today,
on `master`, right now):**

- `StudentDetailPage.tsx:1001-1003` — filters the national class list by
  `schoolClass.school_id`, a field the backend no longer returns. The
  class dropdown is permanently empty after picking a school. A user
  cannot assign or change a student's class at all.
- `SchoolDetailPage.tsx:150` — calls `listClasses({ schoolId, perPage:
  500 })`. The backend silently ignores `school_id` now (sub-project 2
  dropped that filter param entirely), so this returns the *entire
  national class list* regardless of which school's page you're
  viewing. The hierarchy table shows every nationally-defined class with
  "—" placeholders for version/shift/section, not this school's actual
  offerings.
- `ClassVersionsPage.tsx` — posts `{class_id, name, bn_name, is_active}`
  with no `school_id`. `StoreClassVersionRequest::authorize()` now
  requires `school_id` and 403s without it — every class-version
  creation fails.
- `ClassesPage.tsx` — posts `school_id`/`section`, both dropped columns.
  `store()`/`update()` on this resource ignore `school_id` entirely now
  (it's not in `StoreClassRequest`'s accepted fields) and `section` was
  removed from the table, so this page's form sends dead fields; its
  parent-filter (`schoolId`) silently does nothing since `index()` no
  longer accepts it.

**Soft breaks (no crash, but a UX regression — context silently
disappears):**

- `SectionsPage.tsx:58-60`, `ShiftsPage.tsx:82-83`,
  `StudentsPage.tsx:21-22`, `SubjectTeacherAssignmentsPage.tsx:49-50` —
  each has a `schoolClassLabel()` helper reading `schoolClass.school?.name`
  to prefix a class-filter dropdown option ("School A • Class Six").
  `schoolClass.school` is always `undefined` now, so every option
  silently degrades to the bare class name. Not a crash (optional
  chaining), and arguably *fine* since class names are now unique
  nationally — but worth fixing deliberately rather than leaving dead
  code that reads as if it still does something.

**Missing entirely:**

- No page or control anywhere lets an actor create/delete a
  `class_schools` row or sync its `subjects` list. `ClassSchoolController`
  (`/class-schools`, full CRUD + `PUT /class-schools/{id}/subjects`) has
  zero frontend callers.
- (Noted, not fixed by this spec) `PUT /class-versions/{class_version}/subjects`
  also has zero frontend callers — this predates sub-project 2 and isn't
  something it broke, so it's out of scope here; mentioned only so a
  future reader doesn't assume this spec built it.

## Decisions confirmed during brainstorming

- **Class-school adoption UI lives inside `SchoolDetailPage.tsx`**, not a
  new top-level page. That page already renders a per-school
  class→version→shift→section tree; adding an "adopt a class" control
  and a per-adoption "manage subjects" control there fits its existing
  drill-down purpose, and avoids a new nav entry for a page that would
  otherwise just show one school's own already-visible data a second
  way.
- **`ClassVersionsPage.tsx` stays a standalone CRUD page** (matches the
  existing pattern shared by `ShiftsPage`/`SectionsPage`/`StudentsPage`:
  a top-level page with a school parent-filter), rather than folding
  class-version creation into `SchoolDetailPage` too. Its form gains a
  required School field; once chosen, the Class dropdown is scoped to
  that school's adopted classes only (via `class-schools`), not the
  national list — so it's structurally impossible to submit an
  unadopted `(class_id, school_id)` pair and hit the backend's 422.

## API client changes (`frontend/packages/api-client/src/index.ts`)

- `SchoolClass` interface: drop `school_id`, `district_id`, `section`,
  `school`. Keep `id`, `name`, `bn_name`, `numeric_level`, `subjects?`
  (per sub-project 2's spec, `subjects` is only ever populated when
  fetched through a `class_schools`-scoped query — kept optional here for
  that reason, but `ClassesPage`'s own bare `listClasses()` calls will
  simply never populate it, same as today's `ClassResource` behavior).
- `SchoolClassPayload`: drop `school_id`, `section`. Becomes `{ name,
  bn_name?, numeric_level? }`.
- `listClasses()`: drop `schoolId`/`districtId` params from its params
  type and query-building (both already no-ops server-side after
  sub-project 2; removing them here stops any new caller from
  reintroducing the mistake).
- `ClassVersionPayload`: add required `school_id: number`.
- New section "Class schools" (after the existing "Class versions"
  section, before "Shifts"):
  ```ts
  export interface ClassSchool {
    id: number
    class_id: number
    school_id: number
    district_id: number
    class?: SchoolClass | null
    school?: School | null
    subjects?: Subject[] | null
  }

  export interface ClassSchoolPayload {
    class_id: number
    school_id: number
  }

  export function listClassSchools(
    params: { page?: number; perPage?: number; schoolId?: number; classId?: number } = {},
  ): Promise<PaginatedResponse<ClassSchool>> {
    const query: Record<string, string | number | undefined> = {
      page: params.page,
      per_page: params.perPage,
      school_id: params.schoolId,
      class_id: params.classId,
    }

    return apiFetch<PaginatedResponse<ClassSchool>>(`/class-schools${buildQuery(query)}`)
  }

  export function createClassSchool(payload: ClassSchoolPayload, token: string): Promise<{ data: ClassSchool }> {
    return createGeo<ClassSchool, ClassSchoolPayload>('class-schools', payload, token)
  }

  export function deleteClassSchool(id: number, token: string): Promise<void> {
    return deleteGeo('class-schools', id, token)
  }

  export function updateClassSchoolSubjects(
    id: number,
    subjectIds: number[],
    token: string,
  ): Promise<{ data: ClassSchool }> {
    return apiFetch<{ data: ClassSchool }>(`/class-schools/${id}/subjects`, {
      method: 'PUT',
      headers: authHeaders(token),
      body: JSON.stringify({ subject_ids: subjectIds }),
    })
  }
  ```
  (`createGeo`/`deleteGeo`/`buildQuery`/`authHeaders`/`apiFetch` are
  this file's existing helpers — read the "Class versions" or "Shifts"
  section immediately above where this is inserted to confirm their
  exact current signatures before using them, since the plan must not
  guess at helper signatures.)
- `listClassVersions`/`ClassVersion`/`ClassVersionResource`: **no
  change** — `ClassVersion` already carries its own `school_id` (a bare
  number, confirmed in `ClassVersionResource.php` — no nested `school`
  object). Any page needing a readable school name for a `ClassVersion`
  row resolves `school_id` against an already-fetched `schools` list by
  id (the same pattern `ClassesPage.tsx`'s old `schoolLabel()` call
  already used) — not a new relation.

## Per-page changes

### `ClassesPage.tsx` — simplify to national class-name CRUD

Drop the `school_id` field (form, `initialValues`, `requiredFields`,
`toPayload`, `valuesFromRow`, the School column, `ClassDetails`' School
row, the `schools` state/fetch, the `parentFilter` prop entirely). Drop
`section` the same way (field, column, detail row). Remaining columns:
`name`, `bn_name`, `numeric_level`. `fetchPage` drops the `schoolId`
param from its `listClasses()` call.

### `SchoolDetailPage.tsx` — fix the hierarchy source, add adoption UI

- Replace `listClasses({ schoolId, perPage: 500 })` with
  `listClassSchools({ schoolId, perPage: 500 })` in the `Promise.all` in
  `loadSchool()`. `buildHierarchyRows()`'s first parameter changes from
  `SchoolClass[]` to `ClassSchool[]`; inside, replace direct
  `schoolClass.id`/`schoolClass.name`/`schoolClass.numeric_level` reads
  with `classSchool.class?.id`/`classSchool.class?.name`/
  `classSchool.class?.numeric_level` (the nested nullable `class` object
  — guard with a fallback the same way other nullable-relation reads in
  this file already do, e.g. `school.division?.name ?? school.division_id`).
- Add an "Adopted Classes" panel below the existing hierarchy table
  (same card styling as `SchoolInfo`'s `rounded-md bg-white p-5
  shadow-sm` container), containing:
  - A list of this school's current `ClassSchool` rows (class name +
    two actions: "Manage subjects" and "Remove").
  - An "Adopt a class" control: a `SearchableSelect` (same component
    `ClassesPage`/`ClassVersionsPage` already use) populated from
    `listClasses({ perPage: 500 })` filtered to exclude classes already
    present in this school's `ClassSchool` list, plus a submit button
    calling `createClassSchool({ class_id, school_id }, token)`.
  - "Remove" calls `deleteClassSchool(id, token)`; on the backend's 422
    (class still has `ClassVersion`s under it), surface that message
    inline the same way `GeoCrudTable`'s existing delete-error handling
    already does — read that component's delete-failure path before
    implementing, to match its established error-display convention
    rather than inventing a new one.
  - "Manage subjects" opens an inline panel (not a separate route) with
    a checkbox per subject (fetched once via `listSubjects({ perPage:
    200 })`, shared across all rows in this panel), pre-checked from
    `classSchool.subjects`, and a Save button calling
    `updateClassSchoolSubjects(id, checkedSubjectIds, token)`. This is
    new UI with no existing precedent in this codebase to mirror (the
    equivalent `class-versions/{id}/subjects` endpoint has never been
    wired up either) — keep it to the simplest possible form: a
    checkbox list + Save, no drag-reordering or search, matching this
    app's plainest existing form patterns (e.g. the `is_active` checkbox
    in `ClassVersionsPage.tsx`).
  - This page currently has no token/auth-aware mutation code at all
    (it's read-only today) — it will need `useAuth()` the way
    `StudentDetailPage.tsx`/other mutating pages already do, and a
    toast/error-display pattern; copy whichever of those two this app
    uses most consistently (grep for `useToast` usage across pages
    before implementing to confirm the dominant pattern).

### `ClassVersionsPage.tsx` — add School field, scope Class options

- `initialValues`/`requiredFields`/`toPayload`/`valuesFromRow` gain
  `school_id`.
- Add a `schools` state + `listSchools({ perPage: 200 })` fetch (mirrors
  `ClassesPage.tsx`'s old pattern, now moving here).
  Add a School `SearchableSelect` field (required) in `renderFields`,
  positioned before the Class field.
  **Class field behavior:** maintain a `schoolClasses: ClassSchool[]`
  state fetched via `listClassSchools({ schoolId: Number(values.school_id)
  })` whenever `values.school_id` changes (clear the current `class_id`
  selection when the school changes, the same way `StudentDetailPage.tsx`
  already clears `class_id` when its School field changes — follow that
  exact pattern). The Class dropdown's options come from
  `schoolClasses.map((cs) => ({ value: String(cs.class_id), label:
  cs.class?.name ?? String(cs.class_id) }))` instead of the current
  flat `classes` (`listClasses()`) state.
- Table's "School" column: resolve `row.school_id` against a `schools`
  list fetched once at the page level (`listSchools({ perPage: 200 })`,
  can reuse the same fetch added above), the same resolve-by-id pattern
  other pages use — not `row.class?.school`, which no longer exists.
- `parentFilter`/`secondaryFilter` props: `parentFilter` (School) keeps
  working unchanged (`ClassVersion.school_id` is unchanged). `secondaryFilter`
  (Class) options come from a separate flat `listClasses({ perPage: 500
  })` fetch (the national list) kept alongside the form's per-school
  `schoolClasses` state described above — these are two distinct lists
  with two distinct purposes and must not be collapsed into one: the
  filter's job is "find every version of Class Six across every school"
  (no adoption gate needed, since filtering reads existing data rather
  than creating a new `(class_id, school_id)` pair), while the form's
  Class dropdown must stay scoped to the selected school's adoptions
  only (to prevent submitting an unadopted pair). Name them distinctly
  in the implementation (e.g. `allClasses` vs. `adoptedClasses`) so a
  future reader isn't tempted to merge them.

### `StudentDetailPage.tsx` — fix the hard break

Replace the `classes: SchoolClass[]` state + `listClasses({ perPage: 500
})` fetch with a `schoolClasses: ClassSchool[]` state, re-fetched via
`listClassSchools({ schoolId: Number(values.school_id) })` whenever
`values.school_id` changes (same pattern as `ClassVersionsPage` above,
and this file already has the "clear `class_id` when school changes"
line at `onChange('class_id', '')` near line 319 — keep that, it's
already correct). `classOptions` becomes `schoolClasses.map((cs) => ({
value: String(cs.class_id), label: cs.class?.name ?? String(cs.class_id)
}))`, replacing the current filter-by-dead-field logic entirely.

### `SectionsPage.tsx`, `ShiftsPage.tsx`, `StudentsPage.tsx`, `SubjectTeacherAssignmentsPage.tsx` — label cleanup

In each, `schoolClassLabel(schoolClass)` simplifies from `` schoolClass.school
? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name ``
to just `schoolClass.name` (the ternary's true branch is dead code now —
delete it, don't leave an always-false condition). No other change in
these four files: their `classId`/`secondaryParentId` filtering already
operates on `class_id` values that remain valid and meaningful (identifying
one specific national class name across every school's versions), since
`ClassVersion.class_id` itself didn't change.

## Testing

No component test runner exists for this app today (confirmed: no
`*.test.tsx` anywhere under `frontend/apps/management`, no `vitest`/`jest`
script in its `package.json` — only `tsc -b` type-checking and manual
verification). This sub-project's testing is:

- Extend `frontend/packages/api-client/src/index.test.ts` for every new/
  changed function listed above (`listClasses` without school params,
  `listClassSchools`, `createClassSchool`, `deleteClassSchool`,
  `updateClassSchoolSubjects`) — read that file's existing test
  structure first and match its pattern exactly (likely asserting the
  constructed URL/query-string and request method/body, based on how
  this package's other list/create/delete functions are already tested
  there).
- Run `tsc -b` across the frontend workspace to catch every type error
  the interface changes surface (expected to be substantial, given how
  many pages reference the removed `SchoolClass` fields).
- Manual verification in the dev server for every changed page's golden
  path and the specific edge cases named above (an empty "no classes
  adopted yet" state in `SchoolDetailPage`, the Class dropdown correctly
  re-populating when School changes in both `ClassVersionsPage` and
  `StudentDetailPage`, the 422-on-destroy-with-live-versions path in the
  new "Remove" action, subjects actually persisting after a "Manage
  subjects" save).

## Out of scope for this sub-project

- Any backend change — sub-project 2 already finished and merged
  everything this depends on.
- Wiring up `PUT /class-versions/{class_version}/subjects` on the
  frontend — this gap predates sub-project 2 and isn't something it (or
  this sub-project) broke.
- The new public student frontend (sub-project 5) — a wholly separate,
  not-yet-started portal.
- Any visual redesign beyond what's needed to add the new adoption/
  subjects-sync controls — this sub-project fixes broken/missing
  functionality, not aesthetics.

## Definition of done

- `api-client`'s `SchoolClass`/`SchoolClassPayload`/`listClasses` match
  the national-list shape; `ClassVersionPayload` requires `school_id`;
  `ClassSchool`/`ClassSchoolPayload` and the four `class-schools`
  functions exist.
- `ClassesPage.tsx` is a pure national class-name CRUD with no
  school/section fields.
- `SchoolDetailPage.tsx` shows this school's actual adopted classes
  (not the national list), and supports adopting a class, removing an
  adoption (blocked with a clear message while `ClassVersion`s exist
  under it), and syncing an adoption's subject list.
- `ClassVersionsPage.tsx` and `StudentDetailPage.tsx` both require a
  School selection before offering a Class choice, and only offer
  classes that school has actually adopted.
- `SectionsPage.tsx`/`ShiftsPage.tsx`/`StudentsPage.tsx`/
  `SubjectTeacherAssignmentsPage.tsx` no longer reference the removed
  `schoolClass.school` field.
- `api-client/src/index.test.ts` covers every new/changed function.
- `tsc -b` passes across the frontend workspace.
- Every changed page manually verified in a running dev server, golden
  path and the named edge cases.
