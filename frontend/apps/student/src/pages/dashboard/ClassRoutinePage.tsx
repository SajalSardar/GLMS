import { useEffect, useState } from 'react'
import { getSchool, listMyClassRoutines, type ClassRoutine, type School } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import type { CommonStrings } from '@ttla/i18n'
import { useLocale } from '../../lib/locale'

const DAY_ORDER = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']

function dayLabel(dayOfWeek: string, t: CommonStrings): string {
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

// Periods become columns and days become rows (the traditional printed
// school-routine layout — see inspiration/class-routine.png), rather than
// a plain flat list: a weekly routine is read by "what's in 3rd period on
// Tuesday", not browsed page by page, so pagination/a flat table don't fit
// here the way they do for Class Materials/Class Exams. A gap between two
// periods' times (not modeled as its own column in class_routines) is
// detected from the data itself and rendered as a merged "break" column,
// matching the inspiration's break column between periods.
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

// Dashboard-only page (no public website equivalent, same as Class
// Materials/Class Exams) — this student's own weekly routine, already
// scoped server-side to their exact school/district/class/section/shift/
// class_version tuple by ClassRoutineController::mine(), so there's no
// class/section picker here: there's nothing to pick.
export default function ClassRoutinePage() {
  const { t } = useLocale()
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [routines, setRoutines] = useState<ClassRoutine[]>([])
  const [school, setSchool] = useState<School | null>(null)

  useEffect(() => {
    if (!token) return

    let active = true
    setLoading(true)

    listMyClassRoutines(token)
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

      <div className="flex items-start justify-between gap-4 print:hidden">
        <h1 className="text-2xl font-bold text-primary-800">{t['student.classRoutine.title']}</h1>
        {!loading && routines.length > 0 && (
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-[#EB1F27]"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
            {t['student.classRoutine.print']}
          </button>
        )}
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.classRoutine.loading']}</p>}

      {!loading && routines.length === 0 && <InfoAlert message={t['student.classRoutine.empty']} />}

      {!loading && routines.length > 0 && (
        <div className="mt-6 overflow-x-auto rounded-md bg-white p-5 shadow-sm print:mt-0 print:overflow-visible print:rounded-none print:p-0 print:shadow-none">
          <div className="mb-4 flex items-start justify-between gap-6">
            <div className="w-24 shrink-0" aria-hidden="true" />
            <div className="text-center">
              <p className="text-lg font-bold text-primary-900">{school?.name ?? ''}</p>
              <p className="text-sm text-gray-500">{school?.address ?? ''}</p>
              <p className="mt-1 text-sm font-medium text-primary-700">
                {[academicYearName, className, versionName, shiftName, sectionName].filter(Boolean).join(' • ')}
              </p>
            </div>
            <div className="w-24 shrink-0" aria-hidden="true" />
          </div>

          <table className="w-full border-collapse text-sm">
            <thead>
              <tr>
                <th rowSpan={2} className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800">
                  {t['student.classRoutine.dayAndTime']}
                </th>
                {columns.map((column, index) =>
                  column.type === 'period' ? (
                    <th key={`period-${column.period}`} className="border border-gray-800 bg-primary-50 px-3 py-2 font-medium whitespace-nowrap text-gray-800">
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
                    <th key={`time-${column.period}`} className="border border-gray-800 px-3 py-1.5 text-xs font-normal whitespace-nowrap text-gray-500">
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
                  <td className="border border-gray-800 bg-primary-50 px-3 py-4 font-medium whitespace-nowrap text-gray-800">
                    {dayLabel(day, t)}
                  </td>
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
                            <div className="font-medium text-primary-900">
                              {entry.subject ? (entry.subject.bn_name ?? entry.subject.name) : ''}
                            </div>
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
