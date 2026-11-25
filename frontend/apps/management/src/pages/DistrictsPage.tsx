import { useEffect, useState } from 'react'
import {
  createDistrict,
  deleteDistrict,
  listDistricts,
  listDivisions,
  updateDistrict,
  type Division,
  type District,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = { division_id: '', name: '', bn_name: '', lat: '', lon: '', url: '' }
const requiredFields = ['division_id', 'name', 'bn_name']

function toPayload(values: Record<string, string>) {
  return {
    division_id: Number(values.division_id),
    name: values.name,
    bn_name: values.bn_name,
    lat: values.lat ? Number(values.lat) : null,
    lon: values.lon ? Number(values.lon) : null,
    url: values.url || null,
  }
}

export default function DistrictsPage() {
  const { token } = useAuth()
  const [divisions, setDivisions] = useState<Division[]>([])

  useEffect(() => {
    listDivisions({ perPage: 200 })
      .then((result) => setDivisions(result.data))
      .catch(() => setDivisions([]))
  }, [])

  const columns: GeoColumn<District>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name },
    {
      key: 'division',
      label: t['management.fields.division'],
      render: (row) => row.division?.name ?? row.division_id,
    },
    { key: 'url', label: t['management.fields.url'], render: (row) => row.url ?? '—' },
  ]

  return (
    <GeoCrudTable<District>
      title={t['management.districts.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        division_id: String(row.division_id),
        name: row.name,
        bn_name: row.bn_name,
        lat: row.lat != null ? String(row.lat) : '',
        lon: row.lon != null ? String(row.lon) : '',
        url: row.url ?? '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.division']} required error={fieldErrors.division_id}>
            <SearchableSelect
              value={values.division_id}
              onChange={(next) => onChange('division_id', next)}
              placeholder={t['management.fields.division']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={divisions.map((division) => ({ value: String(division.id), label: division.name }))}
            />
          </GeoField>
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
          <GeoField label={t['management.fields.lat']} error={fieldErrors.lat}>
            <input
              className={inputClass}
              value={values.lat}
              onChange={(event) => onChange('lat', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.lon']} error={fieldErrors.lon}>
            <input
              className={inputClass}
              value={values.lon}
              onChange={(event) => onChange('lon', event.target.value)}
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
      fetchPage={(page, filters) =>
        listDistricts({ page, search: filters.search, divisionId: filters.parentId }, token ?? undefined)
      }
      createRow={(values, token) => createDistrict(toPayload(values), token)}
      updateRow={(id, values, token) => updateDistrict(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteDistrict(id, token)}
      parentFilter={{
        label: t['management.fields.division'],
        allLabel: t['management.fields.allDivisions'],
        options: divisions.map((division) => ({ id: division.id, label: division.name })),
      }}
    />
  )
}
