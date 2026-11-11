import { useEffect, useState, type ReactNode } from 'react'
import {
  createSection,
  deleteSection,
  listShifts,
  listSections,
  updateSection,
  type Section,
  type Shift,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
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
  const schoolName = shift.class_version?.class?.school?.name
  const classVersionName = shift.class_version?.name

  if (schoolName && classVersionName) return `${schoolName} • ${classVersionName} • ${shift.name}`
  if (classVersionName) return `${classVersionName} • ${shift.name}`
  return shift.name
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

function SectionDetails({ section }: { section: Section }) {
  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={section.name} />
      <DetailRow label={t['management.fields.bnName']} value={section.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.code']} value={section.code ?? '—'} />
      <DetailRow label={t['management.fields.capacity']} value={section.capacity ?? '—'} />
      <DetailRow
        label={t['management.fields.shift']}
        value={section.shift ? shiftLabel(section.shift) : section.shift_id}
      />
      <DetailRow label={t['management.fields.isActive']} value={<ActiveBadge isActive={section.is_active} />} />
    </div>
  )
}

export default function SectionsPage() {
  const [shifts, setShifts] = useState<Shift[]>([])

  useEffect(() => {
    listShifts({ perPage: 200 })
      .then((result) => setShifts(result.data))
      .catch(() => setShifts([]))
  }, [])

  const shiftOptions = shifts.map((shift) => ({ value: String(shift.id), label: shiftLabel(shift) }))

  const columns: GeoColumn<Section>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'code', label: t['management.fields.code'], render: (row) => row.code ?? '—' },
    { key: 'capacity', label: t['management.fields.capacity'], render: (row) => row.capacity ?? '—' },
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
      renderFields={(values, onChange, fieldErrors) => (
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
      )}
      fetchPage={(page, filters) => listSections({ page, search: filters.search, shiftId: filters.parentId })}
      createRow={(values, token) => createSection(toPayload(values), token)}
      updateRow={(id, values, token) => updateSection(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteSection(id, token)}
      renderDetails={(row) => <SectionDetails section={row} />}
      parentFilter={{
        label: t['management.fields.shift'],
        allLabel: t['management.fields.allShifts'],
        options: shifts.map((shift) => ({ id: shift.id, label: shiftLabel(shift) })),
      }}
    />
  )
}
