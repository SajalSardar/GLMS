# Management Frontend Class-Wise Filter Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every place in the management frontend that broke (or silently degraded) when sub-project 2 restructured `school_classes` into a national list + `class_schools` adoption pivot, and build the missing adoption/subjects-sync UI that pivot needs.

**Architecture:** Update the shared `api-client` package's class-related types/functions first (every page task depends on this), then fix each affected page: three hard breaks (`ClassesPage`, `ClassVersionsPage`, `StudentDetailPage`), one page needing new interactive UI it never had (`SchoolDetailPage`), and four pages needing only a small label cleanup (`SectionsPage`, `ShiftsPage`, `StudentsPage`, `SubjectTeacherAssignmentsPage`).

**Tech Stack:** React + TypeScript, Vite, Tailwind, Vitest (for `api-client`'s own tests — there is no component test runner for `apps/management`).

**Spec:** `docs/superpowers/specs/2026-11-18-management-frontend-class-filters-design.md`

## Global Constraints

- No backend changes anywhere in this plan — sub-project 2 already finished and merged everything this depends on.
- Do not wire up `PUT /class-versions/{class_version}/subjects` — that gap predates sub-project 2 and is explicitly out of scope here.
- Every new/changed function in `frontend/packages/api-client/src/index.ts` needs a corresponding test in `index.test.ts`, matching that file's existing `vi.stubGlobal('fetch', ...)` mocking pattern exactly.
- After every task that touches `.tsx`/`.ts` files, run `npx tsc -b apps/management/tsconfig.json` from `frontend/` to confirm no new type errors anywhere in the workspace (not just the file you changed — these are shared types). There is no root `tsconfig.json` in this repo, so a bare `npx tsc -b` fails immediately with `TS5083` — always pass the management app's project file explicitly.
- After every task that touches a page's user-facing behavior, manually verify it in the dev server (`npm run dev:management` from `frontend/`) before considering the task done. State what you checked in your report.
- Add new i18n keys to **both** `frontend/packages/i18n/src/common/en.json` and `frontend/packages/i18n/src/common/bn.json` — `bn.json` is actively used by `ManagementLogin.tsx` and kept in sync with `en.json` throughout this codebase.
- `management.fields.section`/`management.fields.allSections` i18n keys **must not be deleted** — `SectionsPage.tsx` uses them for the real, unrelated `Section` (timetable) entity. Only `ClassesPage.tsx`'s *usage* of `management.fields.section` for the dropped curriculum-stream field is removed.

---

## Task 1: API client — class-related types and functions

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`
- Modify: `frontend/packages/api-client/src/index.test.ts`

**Interfaces:**
- Produces: updated `SchoolClass`/`SchoolClassPayload` (no `school_id`/`district_id`/`section`/`school`), updated `listClasses()` (no `schoolId`/`districtId` params), updated `ClassVersionPayload` (adds required `school_id`), new `ClassSchool`/`ClassSchoolPayload` types, new `listClassSchools()`/`createClassSchool()`/`deleteClassSchool()`/`updateClassSchoolSubjects()` functions — every later task in this plan consumes these.

- [ ] **Step 1: Write the failing tests**

In `frontend/packages/api-client/src/index.test.ts`, find the `describe('school classes', ...)` block (around line 329) and replace its two tests:

```ts
describe('school classes', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists classes with no school filter', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClasses({ search: 'six' })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes?search=six`)
  })

  it('creates a class with no school_id in the body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClass({ name: 'Class Six' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ name: 'Class Six' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('class schools', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists class schools filtered by school_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClassSchools({ schoolId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools?school_id=5`)
  })

  it('creates a class school with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClassSchool({ class_id: 7, school_id: 5 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ class_id: 7, school_id: 5 }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('deletes a class school', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await deleteClassSchool(9, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools/9`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'DELETE',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('syncs a class school\'s subjects with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 9 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await updateClassSchoolSubjects(9, [1, 2, 3], 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools/9/subjects`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PUT',
      body: JSON.stringify({ subject_ids: [1, 2, 3] }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})
```

Add `listClassSchools, createClassSchool, deleteClassSchool, updateClassSchoolSubjects` to this test file's existing `import { ... } from './index'` block at the top (alongside the other imported functions already there).

Also find the `describe('class versions', ...)` block's `'creates a class version with a JSON body'` test and update its payload to include `school_id` (since `ClassVersionPayload` gains a required field):

```ts
  it('creates a class version with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClassVersion({ class_id: 5, school_id: 3, name: 'English Version' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-versions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ class_id: 5, school_id: 3, name: 'English Version' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `frontend/`): `npx vitest run packages/api-client/src/index.test.ts`
Expected: FAIL — `listClassSchools`/`createClassSchool`/`deleteClassSchool`/`updateClassSchoolSubjects` don't exist yet (import error), and the two rewritten `school classes`/`class versions` tests fail against the current `listClasses`/`createClass`/`createClassVersion` signatures.

- [ ] **Step 3: Update the types and functions**

In `frontend/packages/api-client/src/index.ts`, replace the `// ---------- School classes ----------` section:

```ts
// ---------- School classes ----------

export interface SchoolClass {
  id: number
  name: string
  bn_name: string | null
  numeric_level: number | null
  subjects?: Array<{ id: number; name: string; bn_name: string | null }> | null
}

export interface SchoolClassPayload {
  name: string
  bn_name?: string | null
  numeric_level?: number | null
}

export function listClasses(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<SchoolClass>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
  }

  return apiFetch<PaginatedResponse<SchoolClass>>(`/classes${buildQuery(query)}`)
}

export function createClass(payload: SchoolClassPayload, token: string): Promise<{ data: SchoolClass }> {
  return createGeo<SchoolClass, SchoolClassPayload>('classes', payload, token)
}

export function updateClass(
  id: number,
  payload: Partial<SchoolClassPayload>,
  token: string,
): Promise<{ data: SchoolClass }> {
  return updateGeo<SchoolClass, Partial<SchoolClassPayload>>('classes', id, payload, token)
}

export function deleteClass(id: number, token: string): Promise<void> {
  return deleteGeo('classes', id, token)
}

// ---------- Class schools ----------

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

(This moves the "Class schools" section to immediately after "School classes" and before "Academic years" — check the file's current section ordering and preserve whatever comes next unchanged.)

In the `// ---------- Class versions ----------` section, update `ClassVersionPayload`:

```ts
export interface ClassVersionPayload {
  class_id: number
  school_id: number
  name: string
  bn_name?: string | null
  is_active?: boolean
}
```

Leave `ClassVersion`, `listClassVersions`, `createClassVersion`, `updateClassVersion`, `deleteClassVersion` themselves unchanged — only the payload type gains the field (the functions already pass whatever payload type they're given straight through).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run packages/api-client/src/index.test.ts`
Expected: PASS

- [ ] **Step 5: Type-check the workspace**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: many errors, in every file this plan still needs to fix (`ClassesPage.tsx`, `ClassVersionsPage.tsx`, `SchoolDetailPage.tsx`, `StudentDetailPage.tsx`, `SectionsPage.tsx`, `ShiftsPage.tsx`, `StudentsPage.tsx`, `SubjectTeacherAssignmentsPage.tsx`) — this is expected at this point in the plan; each subsequent task fixes its own file's errors. Confirm the errors are all in those named files and relate to the removed `SchoolClass` fields / new `ClassVersionPayload` field, not somewhere unexpected.

- [ ] **Step 6: Commit**

```bash
cd frontend
git add packages/api-client/src/index.ts packages/api-client/src/index.test.ts
git commit -m "Update api-client for the national class list + class-schools adoption pivot"
```

---

## Task 2: `ClassesPage.tsx` — simplify to national class-name CRUD

**Files:**
- Modify: `frontend/apps/management/src/pages/ClassesPage.tsx`

**Interfaces:**
- Consumes: Task 1's simplified `SchoolClass`/`SchoolClassPayload`/`listClasses`.

- [ ] **Step 1: Rewrite the page**

Replace the full contents of `frontend/apps/management/src/pages/ClassesPage.tsx`:

```tsx
import { type ReactNode } from 'react'
import { createClass, deleteClass, listClasses, updateClass, type SchoolClass } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  name: '',
  bn_name: '',
  numeric_level: '',
}

const requiredFields = ['name']

function toPayload(values: Record<string, string>) {
  return {
    name: values.name,
    bn_name: values.bn_name || null,
    numeric_level: values.numeric_level ? Number(values.numeric_level) : null,
  }
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-right text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function ClassDetails({ schoolClass }: { schoolClass: SchoolClass }) {
  const subjectsLabel = schoolClass.subjects?.length
    ? schoolClass.subjects.map((subject) => subject.name).join(', ')
    : '—'

  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={schoolClass.name} />
      <DetailRow label={t['management.fields.bnName']} value={schoolClass.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.numericLevel']} value={schoolClass.numeric_level ?? '—'} />
      <DetailRow label={t['management.fields.subjects']} value={subjectsLabel} />
    </div>
  )
}

export default function ClassesPage() {
  const columns: GeoColumn<SchoolClass>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    {
      key: 'numeric_level',
      label: t['management.fields.numericLevel'],
      render: (row) => row.numeric_level ?? '—',
    },
  ]

  return (
    <GeoCrudTable<SchoolClass>
      title={t['management.classes.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        name: row.name,
        bn_name: row.bn_name ?? '',
        numeric_level: row.numeric_level != null ? String(row.numeric_level) : '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
            <input
              className={inputClass}
              value={values.bn_name}
              onChange={(event) => onChange('bn_name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.numericLevel']} error={fieldErrors.numeric_level}>
            <input
              type="number"
              min={1}
              max={12}
              className={inputClass}
              value={values.numeric_level}
              onChange={(event) => onChange('numeric_level', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => listClasses({ page, search: filters.search })}
      createRow={(values, token) => createClass(toPayload(values), token)}
      updateRow={(id, values, token) => updateClass(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteClass(id, token)}
      renderDetails={(row) => <ClassDetails schoolClass={row} />}
    />
  )
}
```

This component now needs no state or effects at all (no `schools` fetch, no parent filter) — `ClassesPage` is a pure function from its props (none) straight to the `GeoCrudTable` it returns.

- [ ] **Step 2: Type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: no errors in `ClassesPage.tsx` anymore (errors in other not-yet-fixed files are still expected).

- [ ] **Step 3: Manually verify in the dev server**

Run `npm run dev:management` (from `frontend/`), log in as `national_admin`, navigate to Classes. Verify: the table shows name/bn_name/numeric_level only (no School/Section columns), "Add new" opens a form with no School field, creating a class succeeds, editing and deleting work. Note in your report what you checked.

- [ ] **Step 4: Commit**

```bash
cd frontend
git add apps/management/src/pages/ClassesPage.tsx
git commit -m "Simplify ClassesPage to a national class-name CRUD"
```

---

## Task 3: `ClassVersionsPage.tsx` — require School, scope Class to adopted classes

**Files:**
- Modify: `frontend/apps/management/src/pages/ClassVersionsPage.tsx`

**Interfaces:**
- Consumes: Task 1's `ClassVersionPayload` (now requires `school_id`), `ClassSchool`/`listClassSchools`.

- [ ] **Step 1: Rewrite the page**

Replace the full contents of `frontend/apps/management/src/pages/ClassVersionsPage.tsx`:

```tsx
import { useEffect, useState, type ReactNode } from 'react'
import {
  createClassVersion,
  deleteClassVersion,
  listClasses,
  listClassSchools,
  listClassVersions,
  listSchools,
  updateClassVersion,
  type ClassSchool,
  type ClassVersion,
  type School,
  type SchoolClass,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  school_id: '',
  class_id: '',
  name: '',
  bn_name: '',
  is_active: 'true',
}

const requiredFields = ['school_id', 'class_id', 'name']

function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
    school_id: Number(values.school_id),
    name: values.name,
    bn_name: values.bn_name || null,
    is_active: values.is_active === 'true',
  }
}

function ActiveBadge({ isActive }: { isActive: boolean }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isActive ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
      }`}
    >
      {isActive ? t['management.fields.yes'] : t['management.fields.no']}
    </span>
  )
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-right text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function ClassVersionDetails({ version, schools }: { version: ClassVersion; schools: School[] }) {
  const school = schools.find((candidate) => candidate.id === version.school_id)

  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={version.name} />
      <DetailRow label={t['management.fields.bnName']} value={version.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.class']} value={version.class?.name ?? version.class_id} />
      <DetailRow label={t['management.fields.school']} value={school ? schoolLabel(school) : version.school_id} />
      <DetailRow label={t['management.fields.isActive']} value={<ActiveBadge isActive={version.is_active} />} />
    </div>
  )
}

export default function ClassVersionsPage() {
  // The full national class-name list — used only for the page-level
  // filter ("find every version of Class Six across every school") and
  // for the detail/table views, which never create anything and so
  // don't need the adoption gate.
  const [allClasses, setAllClasses] = useState<SchoolClass[]>([])
  const [schools, setSchools] = useState<School[]>([])
  // The create/edit form's Class dropdown, re-fetched whenever the
  // form's School field changes. Scoped to that school's actual
  // adoptions so it's structurally impossible to submit a
  // (class_id, school_id) pair absent from class_schools.
  const [adoptedClasses, setAdoptedClasses] = useState<ClassSchool[]>([])
  const [formSchoolId, setFormSchoolId] = useState('')

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setAllClasses(result.data))
      .catch(() => setAllClasses([]))

    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  useEffect(() => {
    if (!formSchoolId) {
      setAdoptedClasses([])
      return
    }

    listClassSchools({ schoolId: Number(formSchoolId), perPage: 500 })
      .then((result) => setAdoptedClasses(result.data))
      .catch(() => setAdoptedClasses([]))
  }, [formSchoolId])

  const schoolOptions = schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))
  const adoptedClassOptions = adoptedClasses.map((classSchool) => ({
    value: String(classSchool.class_id),
    label: classSchool.class?.name ?? String(classSchool.class_id),
  }))

  const columns: GeoColumn<ClassVersion>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => row.class?.name ?? row.class_id,
    },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => {
        const school = schools.find((candidate) => candidate.id === row.school_id)
        return school ? schoolLabel(school) : row.school_id
      },
    },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => <ActiveBadge isActive={row.is_active} />,
    },
  ]

  return (
    <GeoCrudTable<ClassVersion>
      title={t['management.classVersions.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => {
        setFormSchoolId(String(row.school_id))
        return {
          school_id: String(row.school_id),
          class_id: String(row.class_id),
          name: row.name,
          bn_name: row.bn_name ?? '',
          is_active: String(row.is_active),
        }
      }}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.school']} required error={fieldErrors.school_id}>
            <SearchableSelect
              value={values.school_id}
              onChange={(next) => {
                onChange('school_id', next)
                onChange('class_id', '')
                setFormSchoolId(next)
              }}
              placeholder={t['management.fields.school']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={schoolOptions}
            />
          </GeoField>

          <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
            <SearchableSelect
              value={values.class_id}
              onChange={(next) => onChange('class_id', next)}
              placeholder={t['management.fields.class']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={adoptedClassOptions}
              className={!values.school_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
            />
          </GeoField>

          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
            <input
              className={inputClass}
              value={values.bn_name}
              onChange={(event) => onChange('bn_name', event.target.value)}
            />
          </GeoField>

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_active === 'true'}
              onChange={(event) => onChange('is_active', String(event.target.checked))}
            />
            {t['management.fields.isActive']}
          </label>
        </>
      )}
      fetchPage={(page, filters) =>
        listClassVersions({
          page,
          search: filters.search,
          schoolId: filters.parentId,
          classId: filters.secondaryParentId,
        })
      }
      createRow={(values, token) => createClassVersion(toPayload(values), token)}
      updateRow={(id, values, token) => updateClassVersion(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteClassVersion(id, token)}
      renderDetails={(row) => <ClassVersionDetails version={row} schools={schools} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
      secondaryFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: allClasses.map((schoolClass) => ({ id: schoolClass.id, label: schoolClass.name })),
      }}
    />
  )
}
```

Note the `valuesFromRow` callback now has a side effect (`setFormSchoolId`) in addition to returning the form values — this is necessary so editing an existing row immediately populates `adoptedClasses` for that row's school (otherwise the Class dropdown would show empty options until the school field is touched). Setting state inside a callback invoked during render is safe here because `GeoCrudTable` calls `valuesFromRow` only from its `openEditForm` event handler (confirmed by reading `GeoCrudTable.tsx`), never during its own render pass.

- [ ] **Step 2: Type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: no errors in `ClassVersionsPage.tsx` anymore.

- [ ] **Step 3: Manually verify in the dev server**

Verify: creating a class version requires picking a school first; the Class dropdown is empty/disabled-looking until a school is picked, then only shows that school's adopted classes; submitting succeeds (no more 403); editing an existing version pre-fills both School and Class correctly; the table's School column shows a real name, not a dead lookup.

- [ ] **Step 4: Commit**

```bash
cd frontend
git add apps/management/src/pages/ClassVersionsPage.tsx
git commit -m "Fix ClassVersionsPage: require school, scope class options to that school's adoptions"
```

---

## Task 4: `StudentDetailPage.tsx` — fix the hard break (empty class dropdown)

**Files:**
- Modify: `frontend/apps/management/src/pages/StudentDetailPage.tsx`

**Interfaces:**
- Consumes: Task 1's `ClassSchool`/`listClassSchools`.

- [ ] **Step 1: Replace the classes state and fetch**

Find (around line 11, 20, 935, 995-1003):

```tsx
  listClasses,
```
in the import block — replace with:
```tsx
  listClassSchools,
```

Find the type import `type SchoolClass,` — replace with `type ClassSchool,`.

Find (around line 935):
```tsx
  const [classes, setClasses] = useState<SchoolClass[]>([])
```
replace with:
```tsx
  const [schoolClasses, setSchoolClasses] = useState<ClassSchool[]>([])
```

Find the effect at line 995 that fetches the national class list on mount:
```tsx
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
```
Delete this effect entirely (it's being replaced by a school-dependent fetch below) — remove it from the `useEffect` block that currently also fetches `schools`, leaving that effect fetching only `schools`:
```tsx
  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])
```

Add a new effect, re-fetching `schoolClasses` whenever `values.school_id` changes:
```tsx
  useEffect(() => {
    if (!values.school_id) {
      setSchoolClasses([])
      return
    }

    listClassSchools({ schoolId: Number(values.school_id), perPage: 500 })
      .then((result) => setSchoolClasses(result.data))
      .catch(() => setSchoolClasses([]))
  }, [values.school_id])
```
Place this immediately after the existing `schoolOptions`/`classOptions` computation's current location, or right after the mount effect — either is fine as long as it runs whenever `values.school_id` changes.

Find (around line 1001-1003):
```tsx
  const classOptions = classes
    .filter((schoolClass) => String(schoolClass.school_id) === values.school_id)
    .map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))
```
replace with:
```tsx
  const classOptions = schoolClasses.map((classSchool) => ({
    value: String(classSchool.class_id),
    label: classSchool.class?.name ?? String(classSchool.class_id),
  }))
```

- [ ] **Step 2: Type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: no errors in `StudentDetailPage.tsx` anymore.

- [ ] **Step 3: Manually verify in the dev server**

Verify: on the Add Student page, picking a School populates the Class dropdown with that school's adopted classes (not empty); submitting a new student with a class succeeds; editing an existing student lets you change their class (dropdown populated from their current school); changing the class to a different one and saving persists correctly.

- [ ] **Step 4: Commit**

```bash
cd frontend
git add apps/management/src/pages/StudentDetailPage.tsx
git commit -m "Fix StudentDetailPage's class dropdown going permanently empty after picking a school"
```

---

## Task 5: `SchoolDetailPage.tsx` — fix the hierarchy source, add class adoption + subjects UI

**Files:**
- Modify: `frontend/apps/management/src/pages/SchoolDetailPage.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: Task 1's `ClassSchool`/`listClassSchools`/`createClassSchool`/`deleteClassSchool`/`updateClassSchoolSubjects`, `listClasses`, `listSubjects`.

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add after the existing `"management.schoolDetail.loading": "Loading school..."` line:

```json
  "management.schoolDetail.adoptedClasses": "Adopted classes",
  "management.schoolDetail.noAdoptedClasses": "No classes adopted yet.",
  "management.schoolDetail.adoptClass": "Adopt a class",
  "management.schoolDetail.adoptClassPlaceholder": "Select a class to adopt",
  "management.schoolDetail.adopt": "Adopt",
  "management.schoolDetail.manageSubjects": "Manage subjects",
  "management.schoolDetail.removeClass": "Remove",
  "management.schoolDetail.removeClassConfirm": "Remove this class from the school? This is blocked while any class version exists under it.",
  "management.schoolDetail.subjectsSaved": "Subjects updated.",
  "management.schoolDetail.classAdopted": "Class adopted.",
  "management.schoolDetail.classRemoved": "Class removed.",
```

In `frontend/packages/i18n/src/common/bn.json`, add the same keys after the matching `"management.schoolDetail.loading"` line, with Bengali translations:

```json
  "management.schoolDetail.adoptedClasses": "গৃহীত ক্লাস",
  "management.schoolDetail.noAdoptedClasses": "এখনও কোনো ক্লাস গ্রহণ করা হয়নি।",
  "management.schoolDetail.adoptClass": "একটি ক্লাস গ্রহণ করুন",
  "management.schoolDetail.adoptClassPlaceholder": "গ্রহণ করার জন্য একটি ক্লাস নির্বাচন করুন",
  "management.schoolDetail.adopt": "গ্রহণ করুন",
  "management.schoolDetail.manageSubjects": "বিষয় পরিচালনা করুন",
  "management.schoolDetail.removeClass": "অপসারণ করুন",
  "management.schoolDetail.removeClassConfirm": "স্কুল থেকে এই ক্লাসটি অপসারণ করবেন? এর অধীনে কোনো ক্লাস ভার্সন থাকলে এটি অবরুদ্ধ হবে।",
  "management.schoolDetail.subjectsSaved": "বিষয় আপডেট হয়েছে।",
  "management.schoolDetail.classAdopted": "ক্লাস গ্রহণ করা হয়েছে।",
  "management.schoolDetail.classRemoved": "ক্লাস অপসারণ করা হয়েছে।",
```

- [ ] **Step 2: Fix the hierarchy table's data source**

In `frontend/apps/management/src/pages/SchoolDetailPage.tsx`, change the import block:

```tsx
import {
  ApiError,
  getSchool,
  listClasses,
  listClassSchools,
  listClassVersions,
  listShifts,
  listSections,
  type ClassSchool,
  type ClassVersion,
  type School,
  type Section,
  type Shift,
} from '@ttla/api-client'
```

(dropped `type SchoolClass`, added `listClassSchools` and `type ClassSchool`; kept `listClasses` — it's still needed for the new "adopt a class" dropdown added below).

Change `buildHierarchyRows`'s signature and body:

```tsx
function buildHierarchyRows(
  classSchools: ClassSchool[],
  versions: ClassVersion[],
  shifts: Shift[],
  sections: Section[],
): HierarchyRow[] {
  const rows: HierarchyRow[] = []

  const sortedClassSchools = [...classSchools].sort(
    (a, b) => (a.class?.numeric_level ?? 0) - (b.class?.numeric_level ?? 0) || (a.class?.name ?? '').localeCompare(b.class?.name ?? ''),
  )

  for (const classSchool of sortedClassSchools) {
    const className = classSchool.class?.name ?? '—'
    const classVersions = versions.filter((version) => version.class_id === classSchool.class_id)

    if (classVersions.length === 0) {
      rows.push({
        key: `c${classSchool.id}`,
        className,
        versionName: '—',
        shiftName: '—',
        sectionName: '—',
      })
      continue
    }

    for (const version of classVersions.sort((a, b) => a.name.localeCompare(b.name))) {
      const versionShifts = shifts.filter((shift) => shift.class_version_id === version.id)

      if (versionShifts.length === 0) {
        rows.push({
          key: `v${version.id}`,
          className,
          versionName: version.name,
          shiftName: '—',
          sectionName: '—',
        })
        continue
      }

      for (const shift of versionShifts.sort((a, b) => a.start_time.localeCompare(b.start_time))) {
        const shiftSections = sections
          .filter((section) => section.shift_id === shift.id)
          .sort((a, b) => a.name.localeCompare(b.name))

        rows.push({
          key: `sh${shift.id}`,
          className,
          versionName: version.name,
          shiftName: shift.name,
          sectionName: shiftSections.length > 0 ? shiftSections.map((section) => section.name).join(', ') : '—',
        })
      }
    }
  }

  return rows
}
```

In `SchoolDetailPage`'s `loadSchool`, replace `listClasses({ schoolId, perPage: 500 })` with `listClassSchools({ schoolId, perPage: 500 })`:

```tsx
  const loadSchool = useCallback((schoolId: number) => {
    setLoading(true)
    setError(null)

    Promise.all([
      getSchool(schoolId),
      listClassSchools({ schoolId, perPage: 500 }),
      listClassVersions({ schoolId, perPage: 500 }),
      listShifts({ schoolId, perPage: 500 }),
      listSections({ schoolId, perPage: 500 }),
    ])
      .then(([schoolResult, classSchoolsResult, versionsResult, shiftsResult, sectionsResult]) => {
        setSchool(schoolResult.data)
        setClassSchools(classSchoolsResult.data)
        setRows(
          buildHierarchyRows(classSchoolsResult.data, versionsResult.data, shiftsResult.data, sectionsResult.data),
        )
      })
      .catch((caught: unknown) => {
        setError(caught instanceof ApiError ? caught.message : String(caught))
      })
      .finally(() => setLoading(false))
  }, [])
```

Add a `classSchools` state alongside the existing `school`/`rows`/`loading`/`error` state declarations:

```tsx
  const [classSchools, setClassSchools] = useState<ClassSchool[]>([])
```

- [ ] **Step 3: Add the Adopted Classes panel**

Add these imports:

```tsx
import { useAuth } from '@ttla/auth'
import { createClassSchool, deleteClassSchool, listSubjects, updateClassSchoolSubjects, type SchoolClass, type Subject } from '@ttla/api-client'
import SearchableSelect from '../components/SearchableSelect'
import { buttonPrimary } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'
```

(merge `type SchoolClass` and `listSubjects`/`type Subject` into the existing `@ttla/api-client` import block from Step 2 rather than a separate statement — write it as one combined import.)

Add a new component, placed after `SchoolInfo` and before `SchoolDetailPage`:

```tsx
function AdoptedClassesPanel({
  schoolId,
  classSchools,
  onChanged,
}: {
  schoolId: number
  classSchools: ClassSchool[]
  onChanged: () => void
}) {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [allClasses, setAllClasses] = useState<SchoolClass[]>([])
  const [allSubjects, setAllSubjects] = useState<Subject[]>([])
  const [adoptClassId, setAdoptClassId] = useState('')
  const [adopting, setAdopting] = useState(false)
  const [removingId, setRemovingId] = useState<number | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)
  const [managingId, setManagingId] = useState<number | null>(null)
  const [checkedSubjectIds, setCheckedSubjectIds] = useState<Set<number>>(new Set())
  const [savingSubjects, setSavingSubjects] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setAllClasses(result.data))
      .catch(() => setAllClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setAllSubjects(result.data))
      .catch(() => setAllSubjects([]))
  }, [])

  const adoptedClassIds = new Set(classSchools.map((classSchool) => classSchool.class_id))
  const adoptableOptions = allClasses
    .filter((schoolClass) => !adoptedClassIds.has(schoolClass.id))
    .map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))

  async function handleAdopt() {
    if (!token || !adoptClassId) return

    setAdopting(true)
    try {
      await createClassSchool({ class_id: Number(adoptClassId), school_id: schoolId }, token)
      setAdoptClassId('')
      showToast({ type: 'success', message: t['management.schoolDetail.classAdopted'] })
      onChanged()
    } catch (error) {
      showToast({ type: 'error', message: error instanceof ApiError ? error.message : String(error) })
    } finally {
      setAdopting(false)
    }
  }

  async function handleRemove(id: number) {
    if (!token) return

    setRemovingId(id)
    setRemoveError(null)
    try {
      await deleteClassSchool(id, token)
      setRemovingId(null)
      showToast({ type: 'success', message: t['management.schoolDetail.classRemoved'] })
      onChanged()
    } catch (error) {
      const message = error instanceof ApiError ? error.message : String(error)
      setRemoveError(message)
      showToast({ type: 'error', message })
    } finally {
      setRemovingId(null)
    }
  }

  function openManageSubjects(classSchool: ClassSchool) {
    setManagingId(classSchool.id)
    setCheckedSubjectIds(new Set((classSchool.subjects ?? []).map((subject) => subject.id)))
  }

  function toggleSubject(subjectId: number) {
    setCheckedSubjectIds((prev) => {
      const next = new Set(prev)
      if (next.has(subjectId)) {
        next.delete(subjectId)
      } else {
        next.add(subjectId)
      }
      return next
    })
  }

  async function handleSaveSubjects() {
    if (!token || managingId === null) return

    setSavingSubjects(true)
    try {
      await updateClassSchoolSubjects(managingId, [...checkedSubjectIds], token)
      setManagingId(null)
      showToast({ type: 'success', message: t['management.schoolDetail.subjectsSaved'] })
      onChanged()
    } catch (error) {
      showToast({ type: 'error', message: error instanceof ApiError ? error.message : String(error) })
    } finally {
      setSavingSubjects(false)
    }
  }

  return (
    <div className="space-y-3">
      <h3 className="text-base font-semibold text-gray-800">{t['management.schoolDetail.adoptedClasses']}</h3>

      <div className="rounded-md bg-white p-5 shadow-sm">
        {classSchools.length === 0 ? (
          <p className="text-sm text-gray-500">{t['management.schoolDetail.noAdoptedClasses']}</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {classSchools.map((classSchool) => (
              <li key={classSchool.id} className="flex flex-col gap-2 py-3">
                <div className="flex items-center justify-between gap-3">
                  <span className="text-sm font-medium text-gray-800">{classSchool.class?.name ?? classSchool.class_id}</span>
                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      className="cursor-pointer text-sm text-primary-600 hover:underline"
                      onClick={() => openManageSubjects(classSchool)}
                    >
                      {t['management.schoolDetail.manageSubjects']}
                    </button>
                    <button
                      type="button"
                      className="cursor-pointer text-sm text-danger-500 hover:underline disabled:opacity-60"
                      disabled={removingId === classSchool.id}
                      onClick={() => {
                        if (window.confirm(t['management.schoolDetail.removeClassConfirm'])) {
                          handleRemove(classSchool.id)
                        }
                      }}
                    >
                      {t['management.schoolDetail.removeClass']}
                    </button>
                  </div>
                </div>

                {removeError && removingId === classSchool.id && (
                  <p className="text-xs text-danger-500">{removeError}</p>
                )}

                {managingId === classSchool.id && (
                  <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                      {allSubjects.map((subject) => (
                        <label key={subject.id} className="flex items-center gap-2 text-sm text-gray-700">
                          <input
                            type="checkbox"
                            checked={checkedSubjectIds.has(subject.id)}
                            onChange={() => toggleSubject(subject.id)}
                          />
                          {subject.name}
                        </label>
                      ))}
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                      <button
                        type="button"
                        className="cursor-pointer text-sm text-gray-500 hover:underline"
                        onClick={() => setManagingId(null)}
                      >
                        {t['management.table.cancel']}
                      </button>
                      <button
                        type="button"
                        className={buttonPrimary}
                        disabled={savingSubjects}
                        onClick={handleSaveSubjects}
                      >
                        {savingSubjects ? t['management.table.saving'] : t['management.table.save']}
                      </button>
                    </div>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4 flex items-end gap-3 border-t border-gray-100 pt-4">
          <div className="w-full sm:w-64">
            <SearchableSelect
              value={adoptClassId}
              onChange={setAdoptClassId}
              placeholder={t['management.schoolDetail.adoptClassPlaceholder']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={adoptableOptions}
            />
          </div>
          <button
            type="button"
            className={buttonPrimary}
            disabled={!adoptClassId || adopting}
            onClick={handleAdopt}
          >
            {adopting ? t['management.table.saving'] : t['management.schoolDetail.adopt']}
          </button>
        </div>
      </div>

      <ToastViewport toast={toast} />
    </div>
  )
}
```

In `SchoolDetailPage`'s JSX, add the panel right after the existing hierarchy table's closing `</div>` (the one closing `<div className="space-y-3">...{t['management.schoolDetail.hierarchy']}...</div>`), inside the same `{school && (<>...</>)}` block:

```tsx
          <AdoptedClassesPanel
            schoolId={school.id}
            classSchools={classSchools}
            onChanged={() => loadSchool(school.id)}
          />
```

- [ ] **Step 2: Type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: no errors in `SchoolDetailPage.tsx` anymore.

- [ ] **Step 3: Manually verify in the dev server**

Verify: the hierarchy table now shows only this school's actual adopted classes (not the national list); adopting a new class makes it appear in both the "Adopted classes" panel and (once a version is added) the hierarchy table; the adopt dropdown excludes already-adopted classes; removing a class with no versions under it works, and removing one that still has a version shows the backend's 422 message inline; "Manage subjects" pre-checks the current subject list, and saving persists it (confirm by reopening the panel).

- [ ] **Step 4: Commit**

```bash
cd frontend
git add apps/management/src/pages/SchoolDetailPage.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Fix SchoolDetailPage's hierarchy source and add class adoption + subjects-sync UI"
```

---

## Task 6: Label cleanup — `SectionsPage`, `ShiftsPage`, `StudentsPage`, `SubjectTeacherAssignmentsPage`

**Files:**
- Modify: `frontend/apps/management/src/pages/SectionsPage.tsx`
- Modify: `frontend/apps/management/src/pages/ShiftsPage.tsx`
- Modify: `frontend/apps/management/src/pages/StudentsPage.tsx`
- Modify: `frontend/apps/management/src/pages/SubjectTeacherAssignmentsPage.tsx`

**Interfaces:**
- Consumes: Task 1's simplified `SchoolClass` (no `school` field).

These four files share the identical dead-code pattern (a `schoolClassLabel()` helper reading the now-nonexistent `schoolClass.school`), so this is one batched task covering all four — same fix, same shape, in each.

- [ ] **Step 1: Fix `SectionsPage.tsx`**

Find (around line 58-60):
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  const schoolName = schoolClass.school?.name
  return schoolName ? `${schoolName} • ${schoolClass.name}` : schoolClass.name
}
```
replace with:
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}
```

- [ ] **Step 2: Fix `ShiftsPage.tsx`**

Find (around line 82-84):
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.school ? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name
}
```
replace with:
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}
```

(Leave `classVersionLabel`'s `version.class?.school?.name` reference in this same file alone for now — read the surrounding function fully first; if it degrades the same way, apply the identical fix there too: drop the school-prefix branch, return the version's own name/class name without it, matching whatever that function's exact current body turns out to be.)

- [ ] **Step 3: Fix `StudentsPage.tsx`**

Find (around line 21-23):
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.school ? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name
}
```
replace with:
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}
```

- [ ] **Step 4: Fix `SubjectTeacherAssignmentsPage.tsx`**

Find (around line 49-51):
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.school ? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name
}
```
replace with:
```tsx
function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}
```

- [ ] **Step 5: Type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`)
Expected: no errors remaining anywhere in the workspace — this should be the task that brings the full `npx tsc -b apps/management/tsconfig.json` run to a clean pass, since every file this plan identified has now been fixed.

- [ ] **Step 6: Manually verify in the dev server**

For each of the four pages: confirm the class filter dropdown still lists every class by name (now without a school prefix) and that filtering by it still works exactly as before (the underlying `class_id`/`classId` query behavior is unchanged).

- [ ] **Step 7: Commit**

```bash
cd frontend
git add apps/management/src/pages/SectionsPage.tsx apps/management/src/pages/ShiftsPage.tsx apps/management/src/pages/StudentsPage.tsx apps/management/src/pages/SubjectTeacherAssignmentsPage.tsx
git commit -m "Drop dead schoolClass.school label prefix in four class-filter dropdowns"
```

---

## Task 7: Final verification

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1-6.

- [ ] **Step 1: Full workspace type-check**

Run: `npx tsc -b apps/management/tsconfig.json` (from `frontend/`, after a clean `npx tsc -b apps/management/tsconfig.json --force` or deleting any stale `.tsbuildinfo` if a prior run's cache could mask a real error)
Expected: clean, zero errors across the entire frontend workspace.

- [ ] **Step 2: Full `api-client` test suite**

Run: `npm test` (from `frontend/`, runs `vitest run` across the workspace per the root `package.json`'s `test` script)
Expected: all tests pass, including every test added/changed in Task 1.

- [ ] **Step 3: Full manual walkthrough**

With `npm run dev:management` running, walk through, in order: Classes (create/edit/delete a national class), a school's detail page (adopt a class, manage its subjects, remove an unused adoption, confirm the 422-blocked removal message for one with a live class version), Class Versions (create one against a freshly adopted class, confirm the dropdown scoping), Add/Edit Student (confirm the class dropdown populates correctly after picking a school), and one pass each through Sections/Shifts/Students/Subject-Teacher-Assignments confirming their class filters still work with the new label. Note any issue found and fix it before considering this task (and the plan) done.

- [ ] **Step 4: Commit (only if Step 3 required fixes)**

```bash
cd frontend
git add -A
git commit -m "Fix issues found during final manual verification"
```

(Skip this step entirely if Step 3 found nothing to fix.)
