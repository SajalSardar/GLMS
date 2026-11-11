import { useEffect, useState, type ReactNode } from 'react'
import {
  createClassVersion,
  deleteClassVersion,
  listClasses,
  listClassVersions,
  updateClassVersion,
  type ClassVersion,
  type SchoolClass,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  class_id: '',
  name: '',
  bn_name: '',
  is_active: 'true',
}

const requiredFields = ['class_id', 'name']

function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
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

function ClassVersionDetails({ version }: { version: ClassVersion }) {
  const subjectsLabel = version.class ? version.class.name : String(version.class_id)

  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={version.name} />
      <DetailRow label={t['management.fields.bnName']} value={version.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.class']} value={subjectsLabel} />
      <DetailRow label={t['management.fields.school']} value={version.class?.school?.name ?? '—'} />
      <DetailRow label={t['management.fields.isActive']} value={<ActiveBadge isActive={version.is_active} />} />
    </div>
  )
}

export default function ClassVersionsPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])

  useEffect(() => {
    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  const classOptions = classes.map((schoolClass) => ({
    value: String(schoolClass.id),
    label: schoolClass.school ? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name,
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
      render: (row) => row.class?.school?.name ?? '—',
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
      valuesFromRow={(row) => ({
        class_id: String(row.class_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
            <SearchableSelect
              value={values.class_id}
              onChange={(next) => onChange('class_id', next)}
              placeholder={t['management.fields.class']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={classOptions}
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
      fetchPage={(page, filters) => listClassVersions({ page, search: filters.search, classId: filters.parentId })}
      createRow={(values, token) => createClassVersion(toPayload(values), token)}
      updateRow={(id, values, token) => updateClassVersion(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteClassVersion(id, token)}
      renderDetails={(row) => <ClassVersionDetails version={row} />}
      parentFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({
          id: schoolClass.id,
          label: schoolClass.school ? `${schoolClass.school.name} • ${schoolClass.name}` : schoolClass.name,
        })),
      }}
    />
  )
}
