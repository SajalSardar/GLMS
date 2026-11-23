import { useEffect, useState } from 'react'
import {
  createHoliday,
  deleteHoliday,
  listAcademicYears,
  listHolidays,
  updateHoliday,
  type AcademicYear,
  type Holiday,
  type HolidayPayload,
  type HolidayType,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const DAYS_OF_WEEK = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']

const DAY_LABEL: Record<string, string> = {
  saturday: t['management.days.saturday'],
  sunday: t['management.days.sunday'],
  monday: t['management.days.monday'],
  tuesday: t['management.days.tuesday'],
  wednesday: t['management.days.wednesday'],
  thursday: t['management.days.thursday'],
  friday: t['management.days.friday'],
}

const TYPE_LABEL: Record<HolidayType, string> = {
  weekly: t['management.fields.holidayTypeWeekly'],
  government: t['management.fields.holidayTypeGovernment'],
  other: t['management.fields.holidayTypeOther'],
}

const initialValues = {
  type: 'weekly',
  day_of_week: '',
  start_date: '',
  end_date: '',
  academic_year_id: '',
  name: '',
  bn_name: '',
  description: '',
  is_active: 'true',
}

function toPayload(values: Record<string, string>): HolidayPayload {
  const type = values.type as HolidayType

  return {
    type,
    day_of_week: type === 'weekly' ? values.day_of_week : null,
    start_date: type === 'weekly' ? null : values.start_date,
    end_date: type === 'weekly' ? null : values.end_date,
    academic_year_id: values.academic_year_id ? Number(values.academic_year_id) : null,
    name: values.name,
    bn_name: values.bn_name || null,
    description: values.description || null,
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

function whenLabel(row: Holiday): string {
  if (row.type === 'weekly') {
    return row.day_of_week ? (DAY_LABEL[row.day_of_week] ?? row.day_of_week) : '—'
  }

  if (!row.start_date || !row.end_date) return '—'
  return row.start_date === row.end_date ? row.start_date : `${row.start_date} – ${row.end_date}`
}

export default function HolidaysPage() {
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])

  useEffect(() => {
    listAcademicYears({ perPage: 200 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  const columns: GeoColumn<Holiday>[] = [
    { key: 'type', label: t['management.fields.holidayType'], render: (row) => TYPE_LABEL[row.type] },
    { key: 'name', label: t['management.fields.name'], render: (row) => row.bn_name ?? row.name },
    { key: 'when', label: t['management.fields.dayOfWeek'], render: (row) => whenLabel(row) },
    {
      key: 'academic_year',
      label: t['management.fields.academicYear'],
      render: (row) => row.academic_year?.name ?? '—',
    },
    { key: 'is_active', label: t['management.fields.isActive'], render: (row) => <ActiveBadge isActive={row.is_active} /> },
  ]

  return (
    <GeoCrudTable<Holiday>
      title={t['management.holidays.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={['type', 'name']}
      valuesFromRow={(row) => ({
        type: row.type,
        day_of_week: row.day_of_week ?? '',
        start_date: row.start_date ?? '',
        end_date: row.end_date ?? '',
        academic_year_id: row.academic_year_id !== null ? String(row.academic_year_id) : '',
        name: row.name,
        bn_name: row.bn_name ?? '',
        description: row.description ?? '',
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
          <GeoField label={t['management.fields.holidayType']} required error={fieldErrors.type}>
            <select
              className={`${inputClass} w-full disabled:cursor-not-allowed disabled:opacity-60`}
              value={values.type}
              // type is immutable after creation (see UpdateHolidayRequest's
              // own comment) — delete and recreate instead of switching it.
              disabled={editingId !== null}
              onChange={(event) => onChange('type', event.target.value)}
            >
              <option value="weekly">{t['management.fields.holidayTypeWeekly']}</option>
              <option value="government">{t['management.fields.holidayTypeGovernment']}</option>
              <option value="other">{t['management.fields.holidayTypeOther']}</option>
            </select>
          </GeoField>

          {values.type === 'weekly' ? (
            <GeoField label={t['management.fields.dayOfWeek']} required error={fieldErrors.day_of_week}>
              <select
                className={`${inputClass} w-full`}
                value={values.day_of_week}
                onChange={(event) => onChange('day_of_week', event.target.value)}
              >
                <option value="">—</option>
                {DAYS_OF_WEEK.map((day) => (
                  <option key={day} value={day}>
                    {DAY_LABEL[day]}
                  </option>
                ))}
              </select>
            </GeoField>
          ) : (
            <>
              <GeoField label={t['management.fields.startDate']} required error={fieldErrors.start_date}>
                <input
                  type="date"
                  className={inputClass}
                  value={values.start_date}
                  onChange={(event) => onChange('start_date', event.target.value)}
                />
              </GeoField>
              <GeoField label={t['management.fields.endDate']} required error={fieldErrors.end_date}>
                <input
                  type="date"
                  className={inputClass}
                  value={values.end_date}
                  onChange={(event) => onChange('end_date', event.target.value)}
                />
              </GeoField>
            </>
          )}

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

          <GeoField label={t['management.fields.academicYear']} error={fieldErrors.academic_year_id}>
            <SearchableSelect
              value={values.academic_year_id}
              onChange={(next) => onChange('academic_year_id', next)}
              placeholder={t['management.fields.noAcademicYear']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: t['management.fields.noAcademicYear'] },
                ...academicYears.map((year) => ({ value: String(year.id), label: year.name })),
              ]}
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
      fetchPage={(page, filters) => listHolidays({ page, academicYearId: filters.parentId })}
      createRow={(values, token) => createHoliday(toPayload(values), token)}
      updateRow={(id, values, token) => updateHoliday(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteHoliday(id, token)}
      createPermission="holidays.create"
      updatePermission="holidays.update"
      deletePermission="holidays.delete"
      parentFilter={{
        label: t['management.fields.academicYear'],
        allLabel: t['management.fields.allAcademicYears'],
        options: academicYears.map((year) => ({ id: year.id, label: year.name })),
      }}
    />
  )
}
