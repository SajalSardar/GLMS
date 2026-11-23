import { useEffect, useId, useRef, useState, type ChangeEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  createSchool,
  deleteSchool,
  listDistricts,
  listDivisions,
  listSchools,
  listUpazilas,
  updateSchool,
  type District,
  type Division,
  type School,
  type Upazila,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { SCHOOL_LEVELS, SCHOOL_TYPES } from '../components/schoolOptions'
import { buttonNeutral, inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  id: '',
  division_id: '',
  district_id: '',
  upazila_id: '',
  name: '',
  bn_name: '',
  eiin: '',
  type: '',
  level: '',
  address: '',
  phone: '',
  email: '',
  established_year: '',
  existing_logo_url: '',
}

const requiredFields = ['division_id', 'district_id', 'upazila_id', 'name', 'type', 'level']

function buildFormData(values: Record<string, string>, logoFile: File | null): FormData {
  const formData = new FormData()
  formData.append('division_id', values.division_id)
  formData.append('district_id', values.district_id)
  formData.append('upazila_id', values.upazila_id)
  formData.append('name', values.name)
  formData.append('type', values.type)
  formData.append('level', values.level)
  if (values.bn_name) formData.append('bn_name', values.bn_name)
  if (values.eiin) formData.append('eiin', values.eiin)
  if (values.address) formData.append('address', values.address)
  if (values.phone) formData.append('phone', values.phone)
  if (values.email) formData.append('email', values.email)
  if (values.established_year) formData.append('established_year', values.established_year)
  if (logoFile) formData.append('logo', logoFile)
  return formData
}

function SchoolFormFields({
  values,
  onChange,
  fieldErrors,
  divisions,
  districts,
  upazilas,
  onLogoFileChange,
}: {
  values: Record<string, string>
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
  divisions: Division[]
  districts: District[]
  upazilas: Upazila[]
  onLogoFileChange: (file: File | null) => void
}) {
  const fileInputId = useId()
  // Local to this component rather than lifted: it remounts fresh every
  // time the add/edit modal opens (it only renders inside GeoCrudTable's
  // `{formOpen && ...}` block), so `useState(null)` already starts clean
  // without needing an effect to reset it. The lazy initializer also
  // clears the parent's ref (see onLogoFileChange) exactly once per
  // mount, so a file picked in an abandoned, cancelled session can't
  // leak into a later submit that never touched the file input.
  const [logoFile, setLogoFile] = useState<File | null>(() => {
    onLogoFileChange(null)
    return null
  })
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null

    if (previewUrl) URL.revokeObjectURL(previewUrl)

    setLogoFile(file)
    setPreviewUrl(file ? URL.createObjectURL(file) : null)
    onLogoFileChange(file)
  }

  const filteredDistricts = districts.filter((district) => String(district.division_id) === values.division_id)
  const filteredUpazilas = upazilas.filter((upazila) => String(upazila.district_id) === values.district_id)
  const displayUrl = previewUrl ?? (values.existing_logo_url || null)

  return (
    <>
      <GeoField label={t['management.fields.logo']}>
        <div className="flex items-center gap-3">
          {displayUrl ? (
            <img src={displayUrl} alt="" className="h-12 w-12 rounded-full object-cover" />
          ) : (
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
              <span className="material-symbols-outlined !text-xl">school</span>
            </span>
          )}
          <label htmlFor={fileInputId} className={`${buttonNeutral} text-xs`}>
            {t['management.fields.chooseFile']}
          </label>
          <input
            id={fileInputId}
            type="file"
            accept="image/png,image/jpeg"
            className="sr-only"
            onChange={handleFileChange}
          />
          <span className="truncate text-xs text-gray-500">
            {logoFile ? logoFile.name : t['management.fields.noFileChosen']}
          </span>
        </div>
      </GeoField>

      <GeoField label={t['management.fields.division']} required error={fieldErrors.division_id}>
        <SearchableSelect
          value={values.division_id}
          onChange={(next) => {
            onChange('division_id', next)
            onChange('district_id', '')
            onChange('upazila_id', '')
          }}
          placeholder={t['management.fields.division']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={divisions.map((division) => ({ value: String(division.id), label: division.name }))}
        />
      </GeoField>

      <GeoField label={t['management.fields.district']} required error={fieldErrors.district_id}>
        <SearchableSelect
          value={values.district_id}
          onChange={(next) => {
            onChange('district_id', next)
            onChange('upazila_id', '')
          }}
          placeholder={t['management.fields.district']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={filteredDistricts.map((district) => ({ value: String(district.id), label: district.name }))}
        />
      </GeoField>

      <GeoField label={t['management.fields.upazila']} required error={fieldErrors.upazila_id}>
        <SearchableSelect
          value={values.upazila_id}
          onChange={(next) => onChange('upazila_id', next)}
          placeholder={t['management.fields.upazila']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={filteredUpazilas.map((upazila) => ({ value: String(upazila.id), label: upazila.name }))}
        />
      </GeoField>

      <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
        <input className={inputClass} value={values.name} onChange={(event) => onChange('name', event.target.value)} />
      </GeoField>

      <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
        <input
          className={inputClass}
          value={values.bn_name}
          onChange={(event) => onChange('bn_name', event.target.value)}
        />
      </GeoField>

      <GeoField label={t['management.fields.eiin']} error={fieldErrors.eiin}>
        <input className={inputClass} value={values.eiin} onChange={(event) => onChange('eiin', event.target.value)} />
      </GeoField>

      <GeoField label={t['management.fields.type']} required error={fieldErrors.type}>
        <select className={inputClass} value={values.type} onChange={(event) => onChange('type', event.target.value)}>
          <option value="" disabled>
            {t['management.fields.type']}
          </option>
          {SCHOOL_TYPES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </GeoField>

      <GeoField label={t['management.fields.level']} required error={fieldErrors.level}>
        <select className={inputClass} value={values.level} onChange={(event) => onChange('level', event.target.value)}>
          <option value="" disabled>
            {t['management.fields.level']}
          </option>
          {SCHOOL_LEVELS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </GeoField>

      <GeoField label={t['management.fields.address']} error={fieldErrors.address}>
        <input
          className={inputClass}
          value={values.address}
          onChange={(event) => onChange('address', event.target.value)}
        />
      </GeoField>

      <GeoField label={t['management.fields.phone']} error={fieldErrors.phone}>
        <input
          type="tel"
          className={inputClass}
          value={values.phone}
          onChange={(event) => onChange('phone', event.target.value)}
        />
      </GeoField>

      <GeoField label={t['management.fields.email']} error={fieldErrors.email}>
        <input
          type="email"
          className={inputClass}
          value={values.email}
          onChange={(event) => onChange('email', event.target.value)}
        />
      </GeoField>

      <GeoField label={t['management.fields.establishedYear']} error={fieldErrors.established_year}>
        <input
          type="number"
          className={inputClass}
          value={values.established_year}
          onChange={(event) => onChange('established_year', event.target.value)}
        />
      </GeoField>
    </>
  )
}

export default function SchoolsPage() {
  const navigate = useNavigate()
  const { token } = useAuth()
  const [divisions, setDivisions] = useState<Division[]>([])
  const [districts, setDistricts] = useState<District[]>([])
  const [upazilas, setUpazilas] = useState<Upazila[]>([])
  // A ref rather than state: it only needs to be read at submit time
  // (inside createRow/updateRow), never to trigger a re-render here —
  // SchoolFormFields owns the state that drives its own preview/filename.
  const logoFileRef = useRef<File | null>(null)

  useEffect(() => {
    listDivisions({ perPage: 200 })
      .then((result) => setDivisions(result.data))
      .catch(() => setDivisions([]))
    listDistricts({ perPage: 200 })
      .then((result) => setDistricts(result.data))
      .catch(() => setDistricts([]))
    listUpazilas({ perPage: 200 })
      .then((result) => setUpazilas(result.data))
      .catch(() => setUpazilas([]))
  }, [])

  const columns: GeoColumn<School>[] = [
    {
      key: 'logo',
      label: t['management.fields.logo'],
      render: (row) =>
        row.logo_url ? (
          <img src={row.logo_url} alt="" className="h-9 w-9 rounded-full object-cover" />
        ) : (
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400">
            <span className="material-symbols-outlined !text-[18px]">school</span>
          </span>
        ),
    },
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'eiin', label: t['management.fields.eiin'], render: (row) => row.eiin ?? '—' },
    {
      key: 'type',
      label: t['management.fields.type'],
      render: (row) => SCHOOL_TYPES.find((option) => option.value === row.type)?.label ?? row.type,
    },
    {
      key: 'division',
      label: t['management.fields.division'],
      render: (row) => row.division?.name ?? row.division_id,
    },
    {
      key: 'district',
      label: t['management.fields.district'],
      render: (row) => row.district?.name ?? row.district_id,
    },
  ]

  return (
    <GeoCrudTable<School>
      title={t['management.schools.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        id: String(row.id),
        division_id: String(row.division_id),
        district_id: String(row.district_id),
        upazila_id: String(row.upazila_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        eiin: row.eiin ?? '',
        type: row.type,
        level: row.level,
        address: row.address ?? '',
        phone: row.phone ?? '',
        email: row.email ?? '',
        established_year: row.established_year != null ? String(row.established_year) : '',
        existing_logo_url: row.logo_url ?? '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <SchoolFormFields
          values={values}
          onChange={onChange}
          fieldErrors={fieldErrors}
          divisions={divisions}
          districts={districts}
          upazilas={upazilas}
          onLogoFileChange={(file) => {
            logoFileRef.current = file
          }}
        />
      )}
      fetchPage={(page, filters) => listSchools({ page, search: filters.search, divisionId: filters.parentId }, token ?? undefined)}
      createRow={(values, token) => createSchool(buildFormData(values, logoFileRef.current), token)}
      updateRow={(id, values, token) => updateSchool(id, buildFormData(values, logoFileRef.current), token)}
      deleteRow={(id, token) => deleteSchool(id, token)}
      createPermission="schools.create"
      updatePermission="schools.update"
      deletePermission="schools.delete"
      onView={(row) => navigate(`/dashboard/schools/${row.id}`)}
      parentFilter={{
        label: t['management.fields.division'],
        allLabel: t['management.fields.allDivisions'],
        options: divisions.map((division) => ({ id: division.id, label: division.name })),
      }}
    />
  )
}
