import { useEffect, useState, type ReactNode } from 'react'
import {
  createShift,
  deleteShift,
  listAcademicYears,
  listClasses,
  listClassVersions,
  listSchools,
  listShifts,
  updateShift,
  DAYS_OF_WEEK,
  type AcademicYear,
  type ClassVersion,
  type DayOfWeek,
  type School,
  type SchoolClass,
  type Shift,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const dayLabels: Record<DayOfWeek, string> = {
  saturday: t['management.days.saturday'],
  sunday: t['management.days.sunday'],
  monday: t['management.days.monday'],
  tuesday: t['management.days.tuesday'],
  wednesday: t['management.days.wednesday'],
  thursday: t['management.days.thursday'],
  friday: t['management.days.friday'],
}

const initialValues = {
  class_version_id: '',
  name: '',
  bn_name: '',
  start_time: '',
  end_time: '',
  days_of_week: '',
  period_duration_minutes: '',
  academic_year_id: '',
  is_active: 'true',
}

const requiredFields = ['class_version_id', 'name', 'start_time', 'end_time', 'days_of_week']

function toDaysArray(value: string): DayOfWeek[] {
  return value ? (value.split(',') as DayOfWeek[]) : []
}

function toPayload(values: Record<string, string>) {
  return {
    class_version_id: Number(values.class_version_id),
    name: values.name,
    bn_name: values.bn_name || null,
    start_time: values.start_time,
    end_time: values.end_time,
    days_of_week: toDaysArray(values.days_of_week),
    period_duration_minutes: values.period_duration_minutes ? Number(values.period_duration_minutes) : null,
    academic_year_id: values.academic_year_id ? Number(values.academic_year_id) : null,
    is_active: values.is_active === 'true',
  }
}

function classVersionLabel(version: ClassVersion): string {
  const className = version.class?.name

  if (className) return `${className} • ${version.name}`
  return version.name
}

function schoolNameFor(schools: School[], schoolId: number): string {
  const school = schools.find((candidate) => candidate.id === schoolId)
  return school ? schoolLabel(school) : String(schoolId)
}

function daysLabel(days: DayOfWeek[]): string {
  return days.map((day) => dayLabels[day]).join(', ')
}

function schoolClassLabel(schoolClass: SchoolClass): string {
  return schoolClass.name
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-right text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function ShiftDetails({ shift, schools }: { shift: Shift; schools: School[] }) {
  return (
    <div className="flex flex-col gap-1">
      <DetailRow label={t['management.fields.name']} value={shift.name} />
      <DetailRow label={t['management.fields.bnName']} value={shift.bn_name ?? '—'} />
      <DetailRow label={t['management.fields.school']} value={schoolNameFor(schools, shift.school_id)} />
      <DetailRow
        label={t['management.fields.classVersion']}
        value={shift.class_version ? classVersionLabel(shift.class_version) : shift.class_version_id}
      />
      <DetailRow label={t['management.fields.startTime']} value={shift.start_time} />
      <DetailRow label={t['management.fields.endTime']} value={shift.end_time} />
      <DetailRow label={t['management.fields.daysOfWeek']} value={daysLabel(shift.days_of_week)} />
      <DetailRow
        label={t['management.fields.periodDuration']}
        value={shift.period_duration_minutes ?? '—'}
      />
      <DetailRow label={t['management.fields.isActive']} value={shift.is_active ? t['management.fields.yes'] : t['management.fields.no']} />
    </div>
  )
}

export default function ShiftsPage() {
  const { token } = useAuth()
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classes, setClasses] = useState<SchoolClass[]>([])

  useEffect(() => {
    listClassVersions({ perPage: 200 })
      .then((result) => setClassVersions(result.data))
      .catch(() => setClassVersions([]))

    listAcademicYears({ perPage: 200 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))

    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  const classVersionOptions = classVersions.map((version) => ({
    value: String(version.id),
    label: classVersionLabel(version),
  }))

  const columns: GeoColumn<Shift>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    {
      key: 'school',
      label: t['management.fields.school'],
      render: (row) => schoolNameFor(schools, row.school_id),
    },
    {
      key: 'class_version',
      label: t['management.fields.classVersion'],
      render: (row) => (row.class_version ? classVersionLabel(row.class_version) : row.class_version_id),
    },
    { key: 'start_time', label: t['management.fields.startTime'], render: (row) => row.start_time },
    { key: 'end_time', label: t['management.fields.endTime'], render: (row) => row.end_time },
    { key: 'days_of_week', label: t['management.fields.daysOfWeek'], render: (row) => daysLabel(row.days_of_week) },
  ]

  return (
    <GeoCrudTable<Shift>
      title={t['management.shifts.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        class_version_id: String(row.class_version_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        start_time: row.start_time,
        end_time: row.end_time,
        days_of_week: row.days_of_week.join(','),
        period_duration_minutes: row.period_duration_minutes != null ? String(row.period_duration_minutes) : '',
        academic_year_id: row.academic_year_id != null ? String(row.academic_year_id) : '',
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => {
        const selectedDays = toDaysArray(values.days_of_week)
        const selectedVersion = classVersions.find((version) => String(version.id) === values.class_version_id)

        function toggleDay(day: DayOfWeek) {
          const next = selectedDays.includes(day)
            ? selectedDays.filter((entry) => entry !== day)
            : [...selectedDays, day]
          onChange('days_of_week', next.join(','))
        }

        return (
          <>
            <GeoField label={t['management.fields.classVersion']} required error={fieldErrors.class_version_id}>
              <SearchableSelect
                value={values.class_version_id}
                onChange={(next) => onChange('class_version_id', next)}
                placeholder={t['management.fields.classVersion']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={classVersionOptions}
              />
            </GeoField>

            <GeoField label={t['management.fields.school']}>
              <input
                className={`${inputClass} cursor-not-allowed opacity-60`}
                value={selectedVersion ? schoolNameFor(schools, selectedVersion.school_id) : '—'}
                disabled
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

            <div className="flex gap-3">
              <GeoField label={t['management.fields.startTime']} required error={fieldErrors.start_time}>
                <input
                  type="time"
                  className={inputClass}
                  value={values.start_time}
                  onChange={(event) => onChange('start_time', event.target.value)}
                />
              </GeoField>

              <GeoField label={t['management.fields.endTime']} required error={fieldErrors.end_time}>
                <input
                  type="time"
                  className={inputClass}
                  value={values.end_time}
                  onChange={(event) => onChange('end_time', event.target.value)}
                />
              </GeoField>
            </div>

            <GeoField label={t['management.fields.daysOfWeek']} required error={fieldErrors.days_of_week}>
              <div className="flex flex-wrap gap-3">
                {DAYS_OF_WEEK.map((day) => (
                  <label key={day} className="flex items-center gap-1.5 text-sm text-gray-700">
                    <input
                      type="checkbox"
                      checked={selectedDays.includes(day)}
                      onChange={() => toggleDay(day)}
                    />
                    {dayLabels[day]}
                  </label>
                ))}
              </div>
            </GeoField>

            <GeoField
              label={t['management.fields.periodDuration']}
              error={fieldErrors.period_duration_minutes}
            >
              <input
                type="number"
                min={1}
                max={180}
                className={inputClass}
                value={values.period_duration_minutes}
                onChange={(event) => onChange('period_duration_minutes', event.target.value)}
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
          </>
        )
      }}
      fetchPage={(page, filters) =>
        listShifts({
          page,
          search: filters.search,
          schoolId: filters.parentId,
          classId: filters.secondaryParentId,
          classVersionId: filters.tertiaryParentId,
        }, token ?? undefined)
      }
      createRow={(values, token) => createShift(toPayload(values), token)}
      updateRow={(id, values, token) => updateShift(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteShift(id, token)}
      renderDetails={(row) => <ShiftDetails shift={row} schools={schools} />}
      parentFilter={{
        label: t['management.fields.school'],
        allLabel: t['management.fields.allSchools'],
        options: schools.map((school) => ({ id: school.id, label: schoolLabel(school) })),
      }}
      secondaryFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClassLabel(schoolClass) })),
      }}
      tertiaryFilter={{
        label: t['management.fields.classVersion'],
        allLabel: t['management.fields.allClassVersions'],
        options: classVersions.map((version) => ({ id: version.id, label: classVersionLabel(version) })),
      }}
    />
  )
}
