import { useEffect, useState, type ReactNode } from 'react'
import {
  createAcademicYear,
  deleteAcademicYear,
  listAcademicYears,
  listSchools,
  updateAcademicYear,
  type AcademicYear,
  type School,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  school_id: '',
  name: '',
  start_date: '',
  end_date: '',
  is_current: '',
  description: '',
}

const requiredFields = ['school_id', 'name', 'start_date', 'end_date']

function toPayload(values: Record<string, string>) {
  return {
    school_id: Number(values.school_id),
    name: values.name,
    start_date: values.start_date,
    end_date: values.end_date,
    is_current: values.is_current === 'true',
    description: values.description || null,
  }
}

function CurrentBadge({ isCurrent }: { isCurrent: boolean }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isCurrent ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
      }`}
    >
      {isCurrent ? t['management.fields.yes'] : t['management.fields.no']}
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

function AcademicYearDetails({ year }: { year: AcademicYear }) {
  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={year.name} />
      <DetailRow label={t['management.fields.startDate']} value={year.start_date} />
      <DetailRow label={t['management.fields.endDate']} value={year.end_date} />
      <DetailRow label={t['management.fields.current']} value={<CurrentBadge isCurrent={year.is_current} />} />
      <DetailRow label={t['management.fields.school']} value={year.school?.name ?? year.school_id} />
      <DetailRow label={t['management.fields.description']} value={year.description ?? '—'} />
    </div>
  )
}

export default function AcademicYearsPage() {
  const [schools, setSchools] = useState<School[]>([])

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  const columns: GeoColumn<AcademicYear>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'start_date', label: t['management.fields.startDate'], render: (row) => row.start_date },
    { key: 'end_date', label: t['management.fields.endDate'], render: (row) => row.end_date },
    {
      key: 'is_current',
      label: t['management.fields.current'],
      render: (row) => <CurrentBadge isCurrent={row.is_current} />,
    },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => row.school?.name ?? row.school_id,
    },
  ]

  return (
    <GeoCrudTable<AcademicYear>
      title={t['management.academicYears.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_id: String(row.school_id),
        name: row.name,
        start_date: row.start_date,
        end_date: row.end_date,
        is_current: String(row.is_current),
        description: row.description ?? '',
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
              options={schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))}
            />
          </GeoField>

          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.startDate']} required error={fieldErrors.start_date}>
            <input
              type="date"
              className={inputClass}
              value={values.start_date}
              onChange={(event) => onChange('start_date', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.endDate']} required error={fieldErrors.end_date}>
            <input
              type="date"
              className={inputClass}
              value={values.end_date}
              onChange={(event) => onChange('end_date', event.target.value)}
            />
          </GeoField>

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_current === 'true'}
              onChange={(event) => onChange('is_current', String(event.target.checked))}
            />
            {t['management.fields.isCurrent']}
          </label>

          <GeoField label={t['management.fields.description']} error={fieldErrors.description}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={3}
              value={values.description}
              onChange={(event) => onChange('description', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => listAcademicYears({ page, search: filters.search, schoolId: filters.parentId })}
      createRow={(values, token) => createAcademicYear(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicYear(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicYear(id, token)}
      renderDetails={(row) => <AcademicYearDetails year={row} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
    />
  )
}
