import { useEffect, useState } from 'react'
import { getMyTeacherAttendance, type AttendanceStatus, type MyTeacherAttendance } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

const MONTH_NAMES = Array.from({ length: 12 }, (_, index) => new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'long' }))

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: t['teacher.attendance.statusPresent'],
  absent: t['teacher.attendance.statusAbsent'],
  leave: t['teacher.attendance.statusLeave'],
}

const STATUS_COLOR: Record<AttendanceStatus, string> = {
  present: 'text-success-600',
  absent: 'text-danger-600',
  leave: 'text-amber-700',
}

export default function MyAttendancePage() {
  const { token } = useAuth()

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [loading, setLoading] = useState(true)
  const [record, setRecord] = useState<MyTeacherAttendance | null>(null)
  const [hasProfile, setHasProfile] = useState(true)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)

    getMyTeacherAttendance({ year, month }, token)
      .then((result) => {
        if (!active) return
        setRecord(result.data)
        setHasProfile(result.data !== null)
      })
      .catch(() => {
        if (active) setRecord(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, year, month])

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.myAttendance.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['teacher.myAttendance.month']}</span>
          <select
            className="h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={month}
            onChange={(event) => setMonth(Number(event.target.value))}
          >
            {MONTH_NAMES.map((name, index) => (
              <option key={name} value={index + 1}>
                {name}
              </option>
            ))}
          </select>
        </label>

        <input
          type="number"
          className="h-11 w-24 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={year}
          onChange={(event) => setYear(Number(event.target.value))}
        />
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.myAttendance.loading']}</p>}

      {!loading && !hasProfile && <p className="mt-6 text-sm text-gray-500">{t['teacher.myAttendance.noProfile']}</p>}

      {!loading && record && (
        <>
          <div className="mt-4 flex flex-wrap gap-4">
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryPresent']}</p>
              <p className="text-lg font-semibold text-success-600">{record.summary.present}</p>
            </div>
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryAbsent']}</p>
              <p className="text-lg font-semibold text-danger-600">{record.summary.absent}</p>
            </div>
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryLeave']}</p>
              <p className="text-lg font-semibold text-amber-700">{record.summary.leave}</p>
            </div>
          </div>

          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[32rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colDate']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colCheckIn']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colCheckOut']}</th>
                </tr>
              </thead>
              <tbody>
                {record.days.map((day) => (
                  <tr key={day.date} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{day.date}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      {day.status ? (
                        // A stored row (device punch or admin correction) always wins
                        // over a holiday label — same invariant the backend's mine()
                        // endpoint enforces: holiday_name only supplies a label when
                        // there's no row-derived status to show, it never hides one.
                        <span className={`font-semibold ${STATUS_COLOR[day.status]}`}>{STATUS_LABEL[day.status]}</span>
                      ) : day.holiday_name ? (
                        <span className="text-amber-700">{day.holiday_name}</span>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {day.check_in_at ? new Date(day.check_in_at).toLocaleTimeString() : '—'}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {day.check_out_at ? new Date(day.check_out_at).toLocaleTimeString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}
