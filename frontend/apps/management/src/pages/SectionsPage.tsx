import { useEffect, useState, type ReactNode } from 'react'
import {
  createSection,
  deleteSection,
  listClasses,
  listSchools,
  listShifts,
  listSections,
  updateSection,
  type School,
  type SchoolClass,
  type Section,
  type Shift,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  shift_id: '',
  name: '',
  bn_name: '',
  code: '',
  capacity: '',
  is_active: 'true',
}

const requiredFields = ['shift_id', 'name']

function toPayload(values: Record<string, string>) {
  return {
    shift_id: Number(values.shift_id),
    name: values.name,
    bn_name: values.bn_name || null,
    code: values.code || null,
    capacity: values.capacity ? Number(values.capacity) : null,
    is_active: values.is_active === 'true',
  }
}

function shiftLabel(shift: Shift): string {
  const classVersionName = shift.class_version?.name

  if (classVersionName) return `${classVersionName} • ${shift.name}`
  return shift.name
}

function classLabel(shift?: Shift | null): string {
  return shift?.class_version?.class?.name ?? '—'
}

function versionLabel(shift?: Shift | null): string {
  return shift?.class_version?.name ?? '—'
}

function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}

function schoolNameFor(schools: School[], schoolId: number): string {
  const school = schools.find((candidate) => candidate.id === schoolId)
  return school ? schoolLabel(school) : String(schoolId)
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

function SectionDetails({ section, schools }: { section: Section; schools: School[] }) {
  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={section.name} />
      <DetailRow label={t['management.fields.bnName']} value={section.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.code']} value={section.code ?? '—'} />
      <DetailRow label={t['management.fields.capacity']} value={section.capacity ?? '—'} />
      <DetailRow label={t['management.fields.school']} value={schoolNameFor(schools, section.school_id)} />
      <DetailRow label={t['management.fields.class']} value={classLabel(section.shift)} />
      <DetailRow label={t['management.fields.classVersion']} value={versionLabel(section.shift)} />
      <DetailRow
        label={t['management.fields.shift']}
        value={section.shift ? shiftLabel(section.shift) : section.shift_id}
      />
      <DetailRow label={t['management.fields.isActive']} value={<ActiveBadge isActive={section.is_active} />} />
    </div>
  )
}

export default function SectionsPage() {
  const { token } = useAuth()
  const [shifts, setShifts] = useState<Shift[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classes, setClasses] = useState<SchoolClass[]>([])

  useEffect(() => {
    listShifts({ perPage: 200 })
      .then((result) => setShifts(result.data))
      .catch(() => setShifts([]))

    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  const shiftOptions = shifts.map((shift) => ({ value: String(shift.id), label: shiftLabel(shift) }))

  const columns: GeoColumn<Section>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'code', label: t['management.fields.code'], render: (row) => row.code ?? '—' },
    { key: 'capacity', label: t['management.fields.capacity'], render: (row) => row.capacity ?? '—' },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => schoolNameFor(schools, row.school_id),
    },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => classLabel(row.shift),
    },
    {
      key: 'class_version',
      label: t['management.fields.classVersion'],
      render: (row) => versionLabel(row.shift),
    },
    {
      key: 'shift',
      label: t['management.fields.shift'],
      render: (row) => (row.shift ? shiftLabel(row.shift) : row.shift_id),
    },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => <ActiveBadge isActive={row.is_active} />,
    },
  ]

  return (
    <GeoCrudTable<Section>
      title={t['management.sections.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        shift_id: String(row.shift_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        code: row.code ?? '',
        capacity: row.capacity != null ? String(row.capacity) : '',
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => {
        const selectedShift = shifts.find((shift) => String(shift.id) === values.shift_id)

        return (
        <>
          <GeoField label={t['management.fields.shift']} required error={fieldErrors.shift_id}>
            <SearchableSelect
              value={values.shift_id}
              onChange={(next) => onChange('shift_id', next)}
              placeholder={t['management.fields.shift']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={shiftOptions}
            />
          </GeoField>

          <GeoField label={t['management.fields.school']}>
            <input
              className={`${inputClass} cursor-not-allowed opacity-60`}
              value={selectedShift ? schoolNameFor(schools, selectedShift.school_id) : '—'}
              disabled
            />
          </GeoField>

          <GeoField label={t['management.fields.classVersion']}>
            <input
              className={`${inputClass} cursor-not-allowed opacity-60`}
              value={versionLabel(selectedShift)}
              disabled
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

          <GeoField label={t['management.fields.code']} error={fieldErrors.code}>
            <input
              className={inputClass}
              value={values.code}
              onChange={(event) => onChange('code', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.capacity']} error={fieldErrors.capacity}>
            <input
              type="number"
              min={1}
              max={200}
              className={inputClass}
              value={values.capacity}
              onChange={(event) => onChange('capacity', event.target.value)}
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
        listSections({
          page,
          search: filters.search,
          schoolId: filters.parentId,
          classId: filters.secondaryParentId,
        }, token ?? undefined)
      }
      createRow={(values, token) => createSection(toPayload(values), token)}
      updateRow={(id, values, token) => updateSection(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteSection(id, token)}
      renderDetails={(row) => <SectionDetails section={row} schools={schools} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
      secondaryFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClassLabel(schoolClass) })),
      }}
    />
  )
}
