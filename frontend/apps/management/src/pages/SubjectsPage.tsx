import {
  createSubject,
  deleteSubject,
  listSubjects,
  updateSubject,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const columns: GeoColumn<Subject>[] = [
  { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
  { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
  { key: 'code', label: t['management.fields.code'], render: (row) => row.code ?? '—' },
]

const initialValues = { name: '', bn_name: '', code: '', description: '' }
const requiredFields = ['name']

export default function SubjectsPage() {
  return (
    <GeoCrudTable<Subject>
      title={t['management.subjects.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        name: row.name,
        bn_name: row.bn_name ?? '',
        code: row.code ?? '',
        description: row.description ?? '',
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
          <GeoField label={t['management.fields.code']} error={fieldErrors.code}>
            <input
              className={inputClass}
              value={values.code}
              onChange={(event) => onChange('code', event.target.value)}
            />
          </GeoField>
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
      fetchPage={(page, filters) => listSubjects({ page, search: filters.search })}
      createRow={(values, token) =>
        createSubject(
          { name: values.name, bn_name: values.bn_name || null, code: values.code || null, description: values.description || null },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateSubject(
          id,
          { name: values.name, bn_name: values.bn_name || null, code: values.code || null, description: values.description || null },
          token,
        )
      }
      deleteRow={(id, token) => deleteSubject(id, token)}
    />
  )
}
