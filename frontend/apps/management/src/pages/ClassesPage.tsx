import { useEffect, useState, type ReactNode } from 'react'
import {
  createClass,
  deleteClass,
  listClasses,
  listSchools,
  updateClass,
  type School,
  type SchoolClass,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  school_id: '',
  name: '',
  bn_name: '',
  numeric_level: '',
  section: '',
}

const requiredFields = ['school_id', 'name']

function toPayload(values: Record<string, string>) {
  return {
    school_id: Number(values.school_id),
    name: values.name,
    bn_name: values.bn_name || null,
    numeric_level: values.numeric_level ? Number(values.numeric_level) : null,
    section: values.section || null,
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
      <DetailRow label={t['management.fields.section']} value={schoolClass.section ?? '—'} />
      <DetailRow label={t['management.fields.school']} value={schoolClass.school?.name ?? schoolClass.school_id} />
      <DetailRow label={t['management.fields.subjects']} value={subjectsLabel} />
    </div>
  )
}

export default function ClassesPage() {
  const [schools, setSchools] = useState<School[]>([])

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  const columns: GeoColumn<SchoolClass>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    {
      key: 'numeric_level',
      label: t['management.fields.numericLevel'],
      render: (row) => row.numeric_level ?? '—',
    },
    { key: 'section', label: t['management.fields.section'], render: (row) => row.section ?? '—' },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => row.school?.name ?? row.school_id,
    },
  ]

  return (
    <GeoCrudTable<SchoolClass>
      title={t['management.classes.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_id: String(row.school_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        numeric_level: row.numeric_level != null ? String(row.numeric_level) : '',
        section: row.section ?? '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.school']} required error={fieldErrors.school_id}>
            <SearchableSelect
              value={values.school_id}
              onChange={(next) => onChange('school_id', next)}
              placeholder={t['management.fields.school']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={schools.map((school) => ({ value: String(school.id), label: school.name }))}
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

          <GeoField label={t['management.fields.section']} error={fieldErrors.section}>
            <input
              className={inputClass}
              value={values.section}
              onChange={(event) => onChange('section', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => listClasses({ page, search: filters.search, schoolId: filters.parentId })}
      createRow={(values, token) => createClass(toPayload(values), token)}
      updateRow={(id, values, token) => updateClass(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteClass(id, token)}
      renderDetails={(row) => <ClassDetails schoolClass={row} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: school.name })),
      }}
    />
  )
}
