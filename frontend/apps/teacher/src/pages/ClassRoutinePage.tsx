import { useEffect, useState } from 'react'
import { getSchool, listMyClassRoutines, type ClassRoutine, type School } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

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

function InfoAlert({ message }: { message: string }) {
  return (
    <div className="mt-6 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700">
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v5" />
        <circle cx="12" cy="8" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

type Column = { type: 'period'; period: number } | { type: 'break'; minutes: number | null }

// Same periods-as-columns / days-as-rows layout as the student portal's
// own Class Routine page (see that file's own comment on why) — kept
// identical so the two read the same way across portals.
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

// This teacher's own weekly routine — every period they're assigned to
// teach, across every class/section they have, scoped server-side by
// ClassRoutineController::mine(). Unlike the student portal's version
// (one fixed class/section, so the header carries that line and each
// cell only needs subject/teacher/room), a teacher's own periods span
// different classes and sections — so here it's the other way around:
// the header stays generic (school + shift + year) and each cell carries
// its own subject/class/section/room. A teacher who teaches across more
// than one shift sees only one shift's grid at a time (the Shift filter
// below) since different shifts' period numbering isn't comparable.
export default function ClassRoutinePage() {
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [routines, setRoutines] = useState<ClassRoutine[]>([])
  const [school, setSchool] = useState<School | null>(null)
  const [shiftId, setShiftId] = useState<number | null>(null)

  useEffect(() => {
    if (!token) return

    let active = true
    setLoading(true)

    listMyClassRoutines(token)
      .then((result) => {
        if (!active) return
        setRoutines(result.data)
        setShiftId((previous) => previous ?? result.data.find((routine) => routine.shift_id !== null)?.shift_id ?? null)
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
  }, [token])

  useEffect(() => {
    const schoolId = routines[0]?.school_id
    if (!schoolId) return

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
  }, [routines])

  // Shift options: this teacher's own distinct shifts, built from the
  // routine rows themselves rather than a separate endpoint — the same
  // "derive the filter from the data already in hand" pattern as the
  // attendance filters' Academic Year/Class/Section cascade.
  //
  // Each shift belongs to exactly one ClassVersion, but Shift.name is
  // almost always the generic "Day Shift" left over from System Setup's
  // own default — so a teacher who teaches more than one class sees
  // multiple options that are all labeled identically and can't tell
  // them apart. Prefixing with the class (and version, when set) makes
  // each option unique the same way sectionLabel()-style combined labels
  // already do elsewhere in this codebase (e.g. PromoteStudentsPage.tsx).
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

      <div className="flex flex-wrap items-start justify-between gap-4 print:hidden">
        <h1 className="text-2xl font-bold text-primary-700">{t['teacher.classRoutine.title']}</h1>

        <div className="flex items-center gap-3">
          {shiftOptions.size > 1 && (
            <label className="flex items-center gap-2 text-sm">
              <span className="font-medium text-gray-700">{t['teacher.classRoutine.selectShift']}</span>
              <select
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
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
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.classRoutine.loading']}</p>}

      {!loading && routines.length === 0 && <InfoAlert message={t['teacher.classRoutine.empty']} />}

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
