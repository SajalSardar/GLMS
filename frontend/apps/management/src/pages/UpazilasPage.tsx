import { useEffect, useState } from 'react'
import {
  createUpazila,
  deleteUpazila,
  listDistricts,
  listUpazilas,
  updateUpazila,
  type District,
  type Upazila,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = { district_id: '', name: '', bn_name: '', url: '' }
const requiredFields = ['district_id', 'name', 'bn_name']

function toPayload(values: Record<string, string>) {
  return {
    district_id: Number(values.district_id),
    name: values.name,
    bn_name: values.bn_name,
    url: values.url || null,
  }
}

export default function UpazilasPage() {
  const { token } = useAuth()
  const [districts, setDistricts] = useState<District[]>([])

  useEffect(() => {
    listDistricts({ perPage: 200 }, token ?? undefined)
      .then((result) => setDistricts(result.data))
      .catch(() => setDistricts([]))
  }, [])

  const columns: GeoColumn<Upazila>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name },
    {
      key: 'district',
      label: t['management.fields.district'],
      render: (row) => row.district?.name ?? row.district_id,
    },
    { key: 'url', label: t['management.fields.url'], render: (row) => row.url ?? '—' },
  ]

  return (
    <GeoCrudTable<Upazila>
      title={t['management.upazilas.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        district_id: String(row.district_id),
        name: row.name,
        bn_name: row.bn_name,
        url: row.url ?? '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.district']} required error={fieldErrors.district_id}>
            <SearchableSelect
              value={values.district_id}
              onChange={(next) => onChange('district_id', next)}
              placeholder={t['management.fields.district']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={districts.map((district) => ({ value: String(district.id), label: district.name }))}
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
          <GeoField label={t['management.fields.url']} error={fieldErrors.url}>
            <input
              className={inputClass}
              value={values.url}
              onChange={(event) => onChange('url', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => listUpazilas({ page, search: filters.search, districtId: filters.parentId })}
      createRow={(values, token) => createUpazila(toPayload(values), token)}
      updateRow={(id, values, token) => updateUpazila(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteUpazila(id, token)}
      parentFilter={{
        label: t['management.fields.district'],
        allLabel: t['management.fields.allDistricts'],
        options: districts.map((district) => ({ id: district.id, label: district.name })),
      }}
    />
  )
}
