import {
  createAcademicGradeConfigure,
  deleteAcademicGradeConfigure,
  listAcademicGradeConfigures,
  updateAcademicGradeConfigure,
  type AcademicGradeConfigure,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  min_percent: '',
  max_percent: '',
  grade_name: '',
  bn_grade_name: '',
  grade_point: '',
  sort_order: '0',
  is_active: 'true',
}

const requiredFields = ['min_percent', 'max_percent', 'grade_name', 'grade_point']

function toPayload(values: Record<string, string>) {
  return {
    min_percent: Number(values.min_percent),
    max_percent: Number(values.max_percent),
    grade_name: values.grade_name,
    bn_grade_name: values.bn_grade_name || null,
    grade_point: Number(values.grade_point),
    sort_order: values.sort_order ? Number(values.sort_order) : null,
    is_active: values.is_active === 'true',
  }
}

export default function AcademicGradeConfiguresPage() {
  const columns: GeoColumn<AcademicGradeConfigure>[] = [
    { key: 'sort_order', label: t['management.fields.sortOrder'], render: (row) => row.sort_order },
    { key: 'grade_name', label: t['management.fields.gradeName'], render: (row) => row.bn_grade_name ?? row.grade_name },
    { key: 'range', label: `${t['management.fields.minPercent']} - ${t['management.fields.maxPercent']}`, render: (row) => `${row.min_percent}% - ${row.max_percent}%` },
    { key: 'grade_point', label: t['management.fields.gradePoint'], render: (row) => row.grade_point },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => (row.is_active ? t['management.fields.yes'] : t['management.fields.no']),
    },
  ]

  return (
    <GeoCrudTable<AcademicGradeConfigure>
      title={t['management.academicGradeConfigures.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        min_percent: row.min_percent,
        max_percent: row.max_percent,
        grade_name: row.grade_name,
        bn_grade_name: row.bn_grade_name ?? '',
        grade_point: row.grade_point,
        sort_order: String(row.sort_order),
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.minPercent']} required error={fieldErrors.min_percent}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.min_percent}
              onChange={(event) => onChange('min_percent', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.maxPercent']} required error={fieldErrors.max_percent}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.max_percent}
              onChange={(event) => onChange('max_percent', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.gradeName']} required error={fieldErrors.grade_name}>
            <input className={inputClass} value={values.grade_name} onChange={(event) => onChange('grade_name', event.target.value)} />
          </GeoField>

          <GeoField label={t['management.fields.gradeBnName']} error={fieldErrors.bn_grade_name}>
            <input
              className={inputClass}
              value={values.bn_grade_name}
              onChange={(event) => onChange('bn_grade_name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.gradePoint']} required error={fieldErrors.grade_point}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.grade_point}
              onChange={(event) => onChange('grade_point', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.sortOrder']} error={fieldErrors.sort_order}>
            <input
              type="number"
              className={inputClass}
              value={values.sort_order}
              onChange={(event) => onChange('sort_order', event.target.value)}
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
      fetchPage={(page) => listAcademicGradeConfigures({ page })}
      createRow={(values, token) => createAcademicGradeConfigure(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicGradeConfigure(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicGradeConfigure(id, token)}
      createPermission="academic_grade_configures.create"
      updatePermission="academic_grade_configures.update"
      deletePermission="academic_grade_configures.delete"
    />
  )
}
