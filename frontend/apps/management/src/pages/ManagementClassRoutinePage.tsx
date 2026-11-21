import { useEffect, useState } from 'react'
import {
  getSchool,
  listClasses,
  listClassRoutines,
  listClassVersions,
  listSchools,
  listSections,
  listShifts,
  type ClassRoutine,
  type ClassVersion,
  type School,
  type SchoolClass,
  type Section,
  type Shift,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const DAY_ORDER = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']

function dayLabel(dayOfWeek: string): string {
  const labels: Record<string, string> = {
    saturday: t['management.days.saturday'],
    sunday: t['management.days.sunday'],
    monday: t['management.days.monday'],
    tuesday: t['management.days.tuesday'],
    wednesday: t['management.days.wednesday'],
    thursday: t['management.days.thursday'],
    friday: t['management.days.friday'],
  }

  return labels[dayOfWeek] ?? dayOfWeek
}

function toMinutes(time: string): number {
  const [hours, minutes] = time.split(':').map(Number)
  return hours * 60 + minutes
}

type Column = { type: 'period'; period: number } | { type: 'break'; minutes: number | null }

// Same periods-as-columns / days-as-rows layout as the Student Portal's
// own Class Routine page (ported here, not shared, matching this
// codebase's existing convention of separate per-app page files).
function buildColumns(routines: ClassRoutine[]): { columns: Column[]; periodTimes: Map<number, { start: string; end: string } | null> } {
  const periods = [...new Set(routines.map((routine) => routine.period_no))].sort((a, b) => a - b)

  const periodTimes = new Map<number, { start: string; end: string } | null>()
  for (const period of periods) {
    const sample = routines.find((routine) => routine.period_no === period && routine.start_time && routine.end_time)
    periodTimes.set(period, sample ? { start: sample.start_time!, end: sample.end_time! } : null)
  }

  const columns: Column[] = []
  periods.forEach((period, index) => {
    columns.push({ type: 'period', period })

    const next = periods[index + 1]
    if (next === undefined) return

    const current = periodTimes.get(period)
    const upcoming = periodTimes.get(next)
    if (current && upcoming && current.end !== upcoming.start) {
      columns.push({ type: 'break', minutes: toMinutes(upcoming.start) - toMinutes(current.end) })
    }
  })

  return { columns, periodTimes }
}

// Lets a school_admin/national_admin look up any section's weekly
// routine (the same data the Student Portal's Class Routine page shows
// that section's own students) — School → Class → Class Version → Shift
// → Section cascade, then the identical fixed-section grid. A
// school_admin's School picker is restricted to their own school (the
// auth-context schoolId captured at management login); a national_admin
// sees and can pick any school.
export default function ManagementClassRoutinePage() {
  const { role, schoolId: ownSchoolId } = useAuth()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classId, setClassId] = useState<number | null>(null)

  const [versions, setVersions] = useState<ClassVersion[]>([])
  const [classVersionId, setClassVersionId] = useState<number | null>(null)

  const [shifts, setShifts] = useState<Shift[]>([])
  const [shiftId, setShiftId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const [loading, setLoading] = useState(false)
  const [routines, setRoutines] = useState<ClassRoutine[]>([])
  const [school, setSchool] = useState<School | null>(null)

  useEffect(() => {
    if (role === 'school_admin' && ownSchoolId !== null) {
      getSchool(ownSchoolId)
        .then((result) => {
          setSchools([result.data])
          setSchoolId(result.data.id)
        })
        .catch(() => setSchools([]))

      return
    }

    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))
  }, [role, ownSchoolId])

  useEffect(() => {
    if (!schoolId) {
      setSchool(null)
      return
    }

    let active = true

    getSchool(schoolId)
      .then((result) => {
        if (active) setSchool(result.data)
      })
      .catch(() => {
        if (active) setSchool(null)
      })

    return () => {
      active = false
    }
  }, [schoolId])

  useEffect(() => {
    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  useEffect(() => {
    if (!classId || !schoolId) {
      setVersions([])
      setClassVersionId(null)
      return
    }

    let active = true

    listClassVersions({ classId, schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setVersions(result.data)
        setClassVersionId(null)
      })
      .catch(() => {
        if (active) setVersions([])
      })

    return () => {
      active = false
    }
  }, [classId, schoolId])

  useEffect(() => {
    if (!classVersionId || !schoolId) {
      setShifts([])
      setShiftId(null)
      return
    }

    let active = true

    listShifts({ classVersionId, schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setShifts(result.data)
        setShiftId(null)
      })
      .catch(() => {
        if (active) setShifts([])
      })

    return () => {
      active = false
    }
  }, [classVersionId, schoolId])

  useEffect(() => {
    if (!shiftId || !schoolId) {
      setSections([])
      setSectionId(null)
      return
    }

    let active = true

    listSections({ shiftId, schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setSections(result.data)
        setSectionId(null)
      })
      .catch(() => {
        if (active) setSections([])
      })

    return () => {
      active = false
    }
  }, [shiftId, schoolId])

  useEffect(() => {
    if (!sectionId) {
      setRoutines([])
      return
    }

    let active = true
    setLoading(true)

    listClassRoutines({ sectionId })
      .then((result) => {
        if (active) setRoutines(result.data)
      })
      .catch(() => {
        if (active) setRoutines([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [sectionId])

  const byDay = new Map<string, Map<number, ClassRoutine>>()
  for (const routine of routines) {
    if (!byDay.has(routine.day_of_week)) byDay.set(routine.day_of_week, new Map())
    byDay.get(routine.day_of_week)!.set(routine.period_no, routine)
  }
  const days = DAY_ORDER.filter((day) => byDay.has(day))
  const { columns, periodTimes } = buildColumns(routines)

  const first = routines[0]
  const className = first?.class ? (first.class.bn_name ?? first.class.name) : ''
  const academicYearName = first?.academic_year?.name ?? ''
  const versionName = first?.class_version ? (first.class_version.bn_name ?? first.class_version.name) : ''
  const shiftName = first?.shift ? (first.shift.bn_name ?? first.shift.name) : ''
  const sectionName = first?.section ? (first.section.bn_name ?? first.section.name) : ''

  return (
    <section className="px-4 py-6 print:px-0 print:py-0 lg:px-6">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-2xl font-bold text-gray-900 print:hidden">{t['management.classRoutine.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm print:hidden">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.school']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[13rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.fields.school']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((s) => ({ value: String(s.id), label: s.bn_name ?? s.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.class']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={classId ?? ''}
            onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {classes.map((schoolClass) => (
              <option key={schoolClass.id} value={schoolClass.id}>
                {schoolClass.bn_name ?? schoolClass.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.classVersion']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={classVersionId ?? ''}
            disabled={classId === null}
            onChange={(event) => setClassVersionId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {versions.map((version) => (
              <option key={version.id} value={version.id}>
                {version.bn_name ?? version.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.shift']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={shiftId ?? ''}
            disabled={classVersionId === null}
            onChange={(event) => setShiftId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {shifts.map((shift) => (
              <option key={shift.id} value={shift.id}>
                {shift.bn_name ?? shift.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.section']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={sectionId ?? ''}
            disabled={shiftId === null}
            onChange={(event) => setSectionId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {section.bn_name ?? section.name}
              </option>
            ))}
          </select>
        </label>

        {!loading && routines.length > 0 && (
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
            {t['student.classRoutine.print']}
          </button>
        )}
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['student.classRoutine.loading']}</p>}

      {!loading && sectionId === null && <p className="mt-6 text-sm text-gray-500">{t['management.classRoutine.pickSection']}</p>}

      {!loading && sectionId !== null && routines.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['management.classRoutine.empty']}</p>}

      {!loading && routines.length > 0 && (
        <div className="mt-6 overflow-x-auto rounded-md bg-white p-5 shadow-sm print:mt-0 print:overflow-visible print:rounded-none print:p-0 print:shadow-none">
          <div className="mb-4 text-center">
            <p className="text-lg font-bold text-primary-900">{school?.name ?? ''}</p>
            <p className="text-sm text-gray-500">{school?.address ?? ''}</p>
            <p className="mt-1 text-sm font-medium text-primary-700">
              {[academicYearName, className, versionName, shiftName, sectionName].filter(Boolean).join(' • ')}
            </p>
          </div>

          <table className="w-full border-collapse text-sm">
            <thead>
              <tr>
                <th rowSpan={2} className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800">
                  {t['student.classRoutine.dayAndTime']}
                </th>
                {columns.map((column, index) =>
                  column.type === 'period' ? (
                    <th
                      key={`period-${column.period}`}
                      className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800"
                    >
                      {t['student.classRoutine.period']} {column.period}
                    </th>
                  ) : (
                    <th key={`break-head-${index}`} className="border border-gray-800 bg-gray-50" />
                  ),
                )}
              </tr>
              <tr>
                {columns.map((column, index) =>
                  column.type === 'period' ? (
                    <th
                      key={`time-${column.period}`}
                      className="border border-gray-800 px-3 py-1.5 text-xs font-normal whitespace-nowrap text-gray-500"
                    >
                      {periodTimes.get(column.period) ? `${periodTimes.get(column.period)!.start} - ${periodTimes.get(column.period)!.end}` : ''}
                    </th>
                  ) : (
                    <th key={`break-time-${index}`} className="border border-gray-800 bg-gray-50" />
                  ),
                )}
              </tr>
            </thead>
            <tbody>
              {days.map((day, dayIndex) => (
                <tr key={day}>
                  <td className="border border-gray-800 bg-primary-50 px-3 py-4 font-medium whitespace-nowrap text-gray-800">{dayLabel(day)}</td>
                  {columns.map((column, index) => {
                    if (column.type === 'break') {
                      if (dayIndex !== 0) return null

                      return (
                        <td
                          key={`break-${index}`}
                          rowSpan={days.length}
                          className="border border-gray-800 bg-gray-50 px-2 py-2 text-center align-middle text-xs font-medium whitespace-nowrap text-gray-600"
                        >
                          {t['student.classRoutine.break']}
                          <br />
                          {column.minutes !== null ? `${column.minutes} ${t['student.classRoutine.minutes']}` : ''}
                        </td>
                      )
                    }

                    const entry = byDay.get(day)?.get(column.period)

                    return (
                      <td key={`cell-${day}-${column.period}`} className="border border-gray-800 px-3 py-2 text-center align-middle">
                        {entry && (
                          <>
                            <div className="font-medium text-primary-900">{entry.subject ? (entry.subject.bn_name ?? entry.subject.name) : ''}</div>
                            {entry.teacher && <div className="text-xs text-gray-500">{entry.teacher.name}</div>}
                            {entry.classroom && <div className="text-xs text-gray-500">{entry.classroom.name}</div>}
                          </>
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
