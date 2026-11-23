import { useEffect, useState, type ReactNode } from 'react'
import {
  createClassVersion,
  deleteClassVersion,
  listClasses,
  listClassSchools,
  listClassVersions,
  listSchools,
  listVersions,
  updateClassVersion,
  type ClassSchool,
  type ClassVersion,
  type School,
  type SchoolClass,
  type Version,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  school_id: '',
  class_id: '',
  version_id: '',
  is_active: 'true',
}

const requiredFields = ['school_id', 'class_id', 'version_id']

function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
    school_id: Number(values.school_id),
    version_id: Number(values.version_id),
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
  const { token } = useAuth()
  // The full national class-name list — used only for the page-level
  // filter ("find every version of Class Six across every school") and
  // for the detail/table views, which never create anything and so
  // don't need the adoption gate.
  const [allClasses, setAllClasses] = useState<SchoolClass[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [versions, setVersions] = useState<Version[]>([])
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

    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))
  }, [])

  useEffect(() => {
    // No school selected: nothing to fetch. `adoptedClassOptions` below
    // already derives an empty list whenever `formSchoolId` is falsy,
    // so there's no need to reset `adoptedClasses` here too.
    if (!formSchoolId) {
      return
    }

    // Guards against a slower, earlier response landing after a faster,
    // later one if the form's School field is switched quickly —
    // without this, a stale class list from the previously-selected
    // school could overwrite the correct one.
    let active = true

    listClassSchools({ schoolId: Number(formSchoolId), perPage: 500 })
      .then((result) => {
        if (active) setAdoptedClasses(result.data)
      })
      .catch(() => {
        if (active) setAdoptedClasses([])
      })

    return () => {
      active = false
    }
  }, [formSchoolId])

  const schoolOptions = schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))
  // Derived rather than reset via the effect above: with no school
  // selected there's nothing to offer, regardless of whatever
  // `adoptedClasses` still holds from a previous selection.
  const adoptedClassOptions = formSchoolId
    ? adoptedClasses.map((classSchool) => ({
        value: String(classSchool.class_id),
        label: classSchool.class?.name ?? String(classSchool.class_id),
      }))
    : []

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
        // Safe: GeoCrudTable calls valuesFromRow only from its own
        // openEditForm(), which itself only runs from a button's
        // onClick handler — never during a render pass — so this
        // setState call never happens inside React's render phase.
        setFormSchoolId(String(row.school_id))
        return {
          school_id: String(row.school_id),
          class_id: String(row.class_id),
          version_id: String(row.version_id),
          is_active: String(row.is_active),
        }
      }}
      renderFields={(values, onChange, fieldErrors) => {
        // `adoptedClasses`/`adoptedClassOptions` are fetched by the
        // effect above, keyed off `formSchoolId` — but GeoCrudTable's
        // own "Add new" button opens a fresh create form by calling its
        // internal openCreateForm() directly, with no way to notify this
        // page, so `formSchoolId` has no chance to reset on that path
        // (only the edit path, via valuesFromRow, and this field's own
        // onChange below ever set it). Comparing the live `values.school_id`
        // against `formSchoolId` here detects exactly that mismatch —
        // a fresh create form showing an empty School field while
        // `formSchoolId` still points at a previously-edited row's
        // school — and falls back to an empty list instead of
        // displaying another school's classes against an empty School
        // field.
        const classOptionsForSchool = values.school_id === formSchoolId ? adoptedClassOptions : []

        return (
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
                options={classOptionsForSchool}
                className={!values.school_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
              />
            </GeoField>

            <GeoField label={t['management.fields.version']} required error={fieldErrors.version_id}>
              <SearchableSelect
                value={values.version_id}
                onChange={(next) => onChange('version_id', next)}
                placeholder={t['management.fields.version']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={versions.filter((version) => version.is_active).map((version) => ({ value: String(version.id), label: version.name }))}
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
        )
      }}
      fetchPage={(page, filters) =>
        listClassVersions({
          page,
          search: filters.search,
          schoolId: filters.parentId,
          classId: filters.secondaryParentId,
        }, token ?? undefined)
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
