import {
  createAcademicExamTerm,
  deleteAcademicExamTerm,
  listAcademicExamTerms,
  updateAcademicExamTerm,
  type AcademicExamTerm,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  name: '',
  bn_name: '',
  sort_order: '0',
  is_annual: 'false',
  is_active: 'true',
}

const requiredFields = ['name']

function toPayload(values: Record<string, string>) {
  return {
    name: values.name,
    bn_name: values.bn_name || null,
    sort_order: values.sort_order ? Number(values.sort_order) : null,
    is_annual: values.is_annual === 'true',
    is_active: values.is_active === 'true',
  }
}

function AnnualBadge({ isAnnual }: { isAnnual: boolean }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isAnnual ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
      }`}
    >
      {isAnnual ? t['management.fields.yes'] : t['management.fields.no']}
    </span>
  )
}

export default function AcademicExamTermsPage() {
  const columns: GeoColumn<AcademicExamTerm>[] = [
    { key: 'sort_order', label: t['management.fields.sortOrder'], render: (row) => row.sort_order },
    { key: 'name', label: t['management.fields.examTermName'], render: (row) => row.bn_name ?? row.name },
    {
      key: 'is_annual',
      label: t['management.fields.isAnnualTerm'],
      render: (row) => <AnnualBadge isAnnual={row.is_annual} />,
    },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => (row.is_active ? t['management.fields.yes'] : t['management.fields.no']),
    },
  ]

  return (
    <GeoCrudTable<AcademicExamTerm>
      title={t['management.academicExamTerms.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        name: row.name,
        bn_name: row.bn_name ?? '',
        sort_order: String(row.sort_order),
        is_annual: String(row.is_annual),
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.examTermName']} required error={fieldErrors.name}>
            <input className={inputClass} value={values.name} onChange={(event) => onChange('name', event.target.value)} />
          </GeoField>

          <GeoField label={t['management.fields.examTermBnName']} error={fieldErrors.bn_name}>
            <input className={inputClass} value={values.bn_name} onChange={(event) => onChange('bn_name', event.target.value)} />
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
              checked={values.is_annual === 'true'}
              onChange={(event) => onChange('is_annual', String(event.target.checked))}
            />
            {t['management.fields.isAnnualTerm']}
          </label>

          {fieldErrors.is_annual && <p className="text-sm text-danger-600">{fieldErrors.is_annual}</p>}

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
      fetchPage={(page) => listAcademicExamTerms({ page })}
      createRow={(values, token) => createAcademicExamTerm(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicExamTerm(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicExamTerm(id, token)}
      createPermission="academic_exam_terms.create"
      updatePermission="academic_exam_terms.update"
      deletePermission="academic_exam_terms.delete"
    />
  )
}
