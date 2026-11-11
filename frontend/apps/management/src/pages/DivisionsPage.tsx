import {
  createDivision,
  deleteDivision,
  listDivisions,
  updateDivision,
  type Division,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const columns: GeoColumn<Division>[] = [
  { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
  { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name },
  { key: 'url', label: t['management.fields.url'], render: (row) => row.url ?? '—' },
]

const initialValues = { name: '', bn_name: '', url: '' }
const requiredFields = ['name', 'bn_name']

export default function DivisionsPage() {
  return (
    <GeoCrudTable<Division>
      title={t['management.divisions.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({ name: row.name, bn_name: row.bn_name, url: row.url ?? '' })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.bnName']} required error={fieldErrors.bn_name}>
            <input
              className={inputClass}
              value={values.bn_name}
              onChange={(event) => onChange('bn_name', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.url']} error={fieldErrors.url}>
            <input
              className={inputClass}
              value={values.url}
              onChange={(event) => onChange('url', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => listDivisions({ page, search: filters.search })}
      createRow={(values, token) =>
        createDivision({ name: values.name, bn_name: values.bn_name, url: values.url || null }, token)
      }
      updateRow={(id, values, token) =>
        updateDivision(id, { name: values.name, bn_name: values.bn_name, url: values.url || null }, token)
      }
      deleteRow={(id, token) => deleteDivision(id, token)}
    />
  )
}
