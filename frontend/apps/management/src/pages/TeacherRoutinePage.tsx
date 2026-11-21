import { useEffect, useState } from 'react'
import { getSchool, listClassRoutines, listSchools, listTeacherUsers, type ClassRoutine, type School, type TeacherUser } from '@ttla/api-client'
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

// Same periods-as-columns / days-as-rows layout as the Teacher Portal's
// own Class Routine page (ported here, not shared, matching this
// codebase's existing convention of separate per-app page files) — kept
// identical so the two read the same way.
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

// Lets a school_admin/national_admin look up ANY teacher's own weekly
// routine (the same data the Teacher Portal's Class Routine page shows
// that teacher) — School then Teacher pickers, then the identical grid.
// A school_admin's School picker is restricted to their own school (the
// auth-context schoolId captured at management login); a national_admin
// sees and can pick any school, since /schools itself is intentionally
// public/unscoped (see TenantScope's own doc comment) and doesn't
// restrict this on its own.
export default function TeacherRoutinePage() {
  const { token, role, schoolId: ownSchoolId } = useAuth()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [teachers, setTeachers] = useState<TeacherUser[]>([])
  const [teacherId, setTeacherId] = useState<number | null>(null)

  const [loading, setLoading] = useState(false)
  const [routines, setRoutines] = useState<ClassRoutine[]>([])
  const [school, setSchool] = useState<School | null>(null)
  const [shiftId, setShiftId] = useState<number | null>(null)

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
    if (!schoolId || !token) {
      setTeachers([])
      return
    }

    let active = true

    listTeacherUsers({ schoolId, perPage: 200 }, token)
      .then((result) => {
        if (!active) return
        setTeachers(result.data)
        setTeacherId(null)
      })
      .catch(() => {
        if (active) setTeachers([])
      })

    return () => {
      active = false
    }
  }, [schoolId, token])

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
    if (!schoolId || !teacherId) {
      setRoutines([])
      return
    }

    let active = true
    setLoading(true)

    listClassRoutines({ schoolId, teacherId })
      .then((result) => {
        if (!active) return
        setRoutines(result.data)
        setShiftId(result.data.find((routine) => routine.shift_id !== null)?.shift_id ?? null)
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
  }, [schoolId, teacherId])

  // Shift options: this teacher's own distinct shifts, built from the
  // routine rows themselves (same "derive the filter from data already
  // in hand" pattern the Teacher Portal's own page uses) — prefixed with
  // class/version since Shift.name is almost always the generic "Day
  // Shift" left over from System Setup's own default, so a teacher
  // teaching more than one class would otherwise see indistinguishable
  // options.
  const shiftOptions = new Map<number, string>()
  for (const routine of routines) {
    if (routine.shift_id !== null && !shiftOptions.has(routine.shift_id)) {
      const shiftName = routine.shift ? (routine.shift.bn_name ?? routine.shift.name) : `#${routine.shift_id}`
      const className = routine.class ? (routine.class.bn_name ?? routine.class.name) : null
      const versionName = routine.class_version ? (routine.class_version.bn_name ?? routine.class_version.name) : null
      shiftOptions.set(routine.shift_id, [className, versionName, shiftName].filter(Boolean).join(' - '))
    }
  }

  const shiftRoutines = shiftId !== null ? routines.filter((routine) => routine.shift_id === shiftId) : routines

  const byDay = new Map<string, Map<number, ClassRoutine>>()
  for (const routine of shiftRoutines) {
    if (!byDay.has(routine.day_of_week)) byDay.set(routine.day_of_week, new Map())
    byDay.get(routine.day_of_week)!.set(routine.period_no, routine)
  }
  const days = DAY_ORDER.filter((day) => byDay.has(day))
  const { columns, periodTimes } = buildColumns(shiftRoutines)

  const first = shiftRoutines[0]
  const academicYearName = first?.academic_year?.name ?? ''
  const shiftName = first?.shift ? (first.shift.bn_name ?? first.shift.name) : ''

  return (
    <section className="px-4 py-6 print:px-0 print:py-0 lg:px-6">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-2xl font-bold text-gray-900 print:hidden">{t['management.teacherRoutine.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm print:hidden">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.school']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.fields.school']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((s) => ({ value: String(s.id), label: s.bn_name ?? s.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.teacher']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={teacherId !== null ? String(teacherId) : ''}
            onChange={(value) => setTeacherId(Number(value))}
            placeholder={t['management.fields.teacher']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={teachers.map((teacher) => ({ value: String(teacher.id), label: teacher.bn_name ?? teacher.name }))}
          />
        </label>

        {shiftOptions.size > 1 && (
          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['management.fields.shift']}</span>
            <select
              className={`${inputClass} min-w-[14rem]`}
              value={shiftId ?? ''}
              onChange={(event) => setShiftId(Number(event.target.value))}
            >
              {[...shiftOptions.entries()].map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
            </select>
          </label>
        )}

        {!loading && shiftRoutines.length > 0 && (
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
            {t['teacher.classRoutine.print']}
          </button>
        )}
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.classRoutine.loading']}</p>}

      {!loading && teacherId === null && <p className="mt-6 text-sm text-gray-500">{t['management.teacherRoutine.pickSchoolAndTeacher']}</p>}

      {!loading && teacherId !== null && routines.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['management.teacherRoutine.empty']}</p>}

      {!loading && routines.length > 0 && shiftRoutines.length > 0 && (
        <div className="mt-6 overflow-x-auto rounded-md bg-white p-5 shadow-sm print:mt-0 print:overflow-visible print:rounded-none print:p-0 print:shadow-none">
          <div className="mb-4 text-center">
            <p className="text-lg font-bold text-primary-900">{school?.name ?? ''}</p>
            <p className="text-sm text-gray-500">{school?.address ?? ''}</p>
            <p className="mt-1 text-sm font-medium text-primary-700">{[academicYearName, shiftName].filter(Boolean).join(' • ')}</p>
          </div>

          <table className="w-full border-collapse text-sm">
            <thead>
              <tr>
                <th rowSpan={2} className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800">
                  {t['teacher.classRoutine.dayAndTime']}
                </th>
                {columns.map((column, index) =>
                  column.type === 'period' ? (
                    <th
                      key={`period-${column.period}`}
                      className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800"
                    >
                      {t['teacher.classRoutine.period']} {column.period}
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
                          {t['teacher.classRoutine.break']}
                          <br />
                          {column.minutes !== null ? `${column.minutes} ${t['teacher.classRoutine.minutes']}` : ''}
                        </td>
                      )
                    }

                    const entry = byDay.get(day)?.get(column.period)

                    return (
                      <td key={`cell-${day}-${column.period}`} className="border border-gray-800 px-3 py-2 text-center align-middle">
                        {entry && (
                          <>
                            <div className="font-medium text-primary-900">{entry.subject ? (entry.subject.bn_name ?? entry.subject.name) : ''}</div>
                            {entry.class && <div className="text-xs text-gray-500">{entry.class.bn_name ?? entry.class.name}</div>}
                            {entry.section && <div className="text-xs text-gray-500">{entry.section.bn_name ?? entry.section.name}</div>}
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
