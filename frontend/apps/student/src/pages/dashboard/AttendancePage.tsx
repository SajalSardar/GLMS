import { useEffect, useState } from 'react'
import { getMyAttendance, type AttendanceStatus, type MyAttendance } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../../lib/locale'

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

// "YYYY-MM-DD" parsed as local-midnight, not via `new Date(str)` (which
// reads a date-only ISO string as UTC midnight — shifting .getDay() by a
// day for any viewer west of UTC).
function parseLocalDate(dateStr: string): Date {
  const [year, month, day] = dateStr.split('-').map(Number)
  return new Date(year, month - 1, day)
}

const STATUS_BOX: Record<AttendanceStatus, string> = {
  present: 'border-success-200 bg-success-50 text-success-700',
  absent: 'border-danger-200 bg-danger-50 text-danger-700',
  leave: 'border-amber-200 bg-amber-50 text-amber-700',
}

// Month calendar, not a table — attendance is checked day-by-day, so a
// grid readers already scan the same way a phone/wall calendar does beats
// a long list. Weeks start Sunday, matching Holiday::DAYS_OF_WEEK's own
// canonical order.
export default function AttendancePage() {
  const { token } = useAuth()
  const { t } = useLocale()

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [loading, setLoading] = useState(true)
  const [attendance, setAttendance] = useState<MyAttendance | null>(null)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)

    getMyAttendance({ year, month }, token)
      .then((result) => {
        if (active) setAttendance(result.data)
      })
      .catch(() => {
        if (active) setAttendance(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, year, month])

  function goToPreviousMonth() {
    if (month === 1) {
      setYear((previous) => previous - 1)
      setMonth(12)
    } else {
      setMonth((previous) => previous - 1)
    }
  }

  function goToNextMonth() {
    if (month === 12) {
      setYear((previous) => previous + 1)
      setMonth(1)
    } else {
      setMonth((previous) => previous + 1)
    }
  }

  const monthLabel = new Date(year, month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })

  const days = attendance?.days ?? []
  const leadingBlanks = days.length > 0 ? parseLocalDate(days[0].date).getDay() : 0
  const cells: Array<{ date: string; status: AttendanceStatus | null; holiday_name: string | null } | null> = [
    ...Array.from({ length: leadingBlanks }, () => null),
    ...days,
  ]

  // Grouped by name rather than listed day-by-day: a weekly holiday (e.g.
  // "Weekend") covers most Fridays/Saturdays in the month and would
  // otherwise crowd out the one-off government holidays this list exists
  // to surface — 3+ occurrences in one month is treated as recurring and
  // shown once, without its dates; anything rarer keeps its date(s).
  const holidaysByName = new Map<string, Date[]>()
  for (const day of days) {
    if (!day.holiday_name) continue
    const dates = holidaysByName.get(day.holiday_name) ?? []
    dates.push(parseLocalDate(day.date))
    holidaysByName.set(day.holiday_name, dates)
  }
  const holidayGroups = [...holidaysByName.entries()].map(([name, dates]) => ({
    name,
    dates,
    recurring: dates.length > 2,
  }))

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.attendance.title']}</h1>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.attendance.loading']}</p>}

      {!loading && attendance === null && <InfoAlert message={t['student.attendance.noProfile']} />}

      {!loading && attendance !== null && (
        <>
          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-md bg-white p-4 shadow-sm">
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={goToPreviousMonth}
                aria-label={t['student.attendance.previousMonth']}
                className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:border-primary-300 hover:text-primary-700"
              >
                ‹
              </button>
              <span className="min-w-[10rem] text-center text-sm font-semibold text-gray-800">{monthLabel}</span>
              <button
                type="button"
                onClick={goToNextMonth}
                aria-label={t['student.attendance.nextMonth']}
                className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:border-primary-300 hover:text-primary-700"
              >
                ›
              </button>
            </div>

            <div className="flex flex-wrap gap-4 text-xs font-medium text-gray-600">
              <span className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-full bg-success-500" /> {t['student.attendance.summaryPresent']}: {attendance.summary.present}
              </span>
              <span className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-full bg-danger-500" /> {t['student.attendance.summaryAbsent']}: {attendance.summary.absent}
              </span>
              <span className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-full bg-amber-500" /> {t['student.attendance.summaryLeave']}: {attendance.summary.leave}
              </span>
            </div>
          </div>

          <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
            <div className="overflow-x-auto rounded-md bg-white p-4 shadow-sm">
              <div className="grid min-w-[22rem] grid-cols-7 gap-1">
                {/* 2023-01-01 is a Sunday — just a stable reference date to read weekday names from, independent of the month being viewed. */}
                {Array.from({ length: 7 }, (_, index) => new Date(2023, 0, 1 + index)).map((date) => (
                  <div key={date.getDay()} className="pb-1 text-center text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                    {date.toLocaleDateString(undefined, { weekday: 'short' })}
                  </div>
                ))}

                {cells.map((cell, index) =>
                  cell === null ? (
                    <div key={`blank-${index}`} className="aspect-square" />
                  ) : (
                    <div
                      key={cell.date}
                      title={cell.holiday_name ?? undefined}
                      className={`flex aspect-square flex-col items-center justify-center overflow-hidden rounded-md border px-0.5 text-xs font-semibold ${
                        cell.status
                          ? STATUS_BOX[cell.status]
                          : cell.holiday_name
                            ? 'border-gray-200 bg-gray-50 text-gray-600'
                            : 'border-gray-100 font-medium text-gray-400'
                      }`}
                    >
                      <span>{Number(cell.date.slice(-2))}</span>
                      {cell.holiday_name && !cell.status && (
                        <span className="line-clamp-1 w-full text-center text-[8px] leading-tight font-normal text-ellipsis">
                          {cell.holiday_name}
                        </span>
                      )}
                    </div>
                  ),
                )}
              </div>
            </div>

            <div className="rounded-md bg-white p-4 shadow-sm">
              <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.attendance.holidaysThisMonth']}</p>
              {holidayGroups.length === 0 && <p className="mt-2 text-sm text-gray-400">—</p>}
              {holidayGroups.length > 0 && (
                <ul className="mt-2 flex flex-col gap-2 text-sm text-gray-700">
                  {holidayGroups.map((group) => (
                    <li key={group.name} className="flex items-start gap-2">
                      <span className="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-gray-300" />
                      <span>
                        {!group.recurring && (
                          <span className="mr-1 font-medium text-gray-500">{group.dates.map((date) => date.getDate()).join(', ')}</span>
                        )}
                        <span>{group.name}</span>
                        {group.recurring && <span className="ml-1 text-xs text-gray-400">({t['student.attendance.recurringHoliday']})</span>}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </>
      )}
    </section>
  )
}
