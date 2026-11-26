import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  getDashboardSummary,
  getMyTeacherAttendance,
  listLeaveApplications,
  type AttendanceStatus,
  type DashboardSummary,
  type MyTeacherAttendanceDay,
  type StudentLeaveApplication,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: t['teacher.attendance.statusPresent'],
  absent: t['teacher.attendance.statusAbsent'],
  leave: t['teacher.attendance.statusLeave'],
}

const STATUS_DOT: Record<AttendanceStatus, string> = {
  present: 'bg-success-500',
  absent: 'bg-danger-500',
  leave: 'bg-amber-500',
}

function formatShortDate(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' })
}

function StatTile({ icon, accentClass, label, value, suffix }: { icon: string; accentClass: string; label: string; value: number | null; suffix?: string }) {
  return (
    <div className="flex items-center gap-4 rounded-md bg-white p-5 shadow-sm">
      <div className={`flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-full ${accentClass}`}>
        <span className="material-symbols-outlined !text-[26px]">{icon}</span>
      </div>
      <div>
        <span className="block text-sm text-gray-500">{label}</span>
        <span className="mt-0.5 block text-xl font-semibold text-gray-800">
          {value === null ? '—' : `${value.toLocaleString()}${suffix ?? ''}`}
        </span>
      </div>
    </div>
  )
}

function DashboardCard({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <div className="rounded-md bg-white p-5 shadow-sm md:p-6">
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-base font-semibold text-gray-800">{title}</h3>
        {action}
      </div>
      {children}
    </div>
  )
}

// A single person's attendance doesn't fit a multi-series area/bar chart
// the way an aggregate count does (see the management dashboard's own
// trend chart) — a day-by-day colored strip reads more clearly for "which
// of the last 7 days was I present/absent/on leave."
function AttendanceTrendStrip({ days }: { days: MyTeacherAttendanceDay[] }) {
  // `days` is the whole requested month, future dates included — slicing
  // the raw array can pick up days after today (e.g. Nov 27-30 while
  // today is Nov 26). Filter to today-or-earlier first, then take the
  // last 7 of those.
  const todayIso = new Date().toISOString().slice(0, 10)
  const last7 = days.filter((day) => day.date <= todayIso).slice(-7)
  const hasData = last7.some((day) => day.status !== null)

  if (!hasData) {
    return <p className="py-6 text-center text-sm text-gray-500">{t['teacher.dashboard.noAttendanceData']}</p>
  }

  return (
    <div className="grid grid-cols-7 gap-2">
      {last7.map((day) => (
        <div key={day.date} className="flex flex-col items-center gap-2 rounded-md bg-gray-50 py-3">
          <span className="text-xs text-gray-500">{formatShortDate(day.date)}</span>
          {day.status ? (
            <>
              <span className={`h-3 w-3 rounded-full ${STATUS_DOT[day.status]}`} />
              <span className="text-[11px] text-gray-600">{STATUS_LABEL[day.status]}</span>
            </>
          ) : (
            <>
              <span className="h-3 w-3 rounded-full bg-gray-200" />
              <span className="text-[11px] text-gray-400">{day.holiday_name ?? '—'}</span>
            </>
          )}
        </div>
      ))}
    </div>
  )
}

function UpcomingHolidaysCard({ holidays }: { holidays: DashboardSummary['upcoming_holidays'] }) {
  if (holidays === null) return null

  return (
    <DashboardCard title={t['teacher.dashboard.upcomingHolidays']}>
      {holidays.length === 0 ? (
        <p className="py-6 text-center text-sm text-gray-500">{t['teacher.dashboard.noUpcomingHolidays']}</p>
      ) : (
        <ul className="space-y-3">
          {holidays.map((holiday) => (
            <li key={holiday.id} className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-600">
                <span className="material-symbols-outlined !text-[20px]">event_busy</span>
              </div>
              <div>
                <span className="block text-sm font-medium text-gray-800">{holiday.name}</span>
                <span className="block text-xs text-gray-500">
                  {holiday.start_date === holiday.end_date
                    ? formatShortDate(holiday.start_date)
                    : `${formatShortDate(holiday.start_date)} – ${formatShortDate(holiday.end_date)}`}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </DashboardCard>
  )
}

function PendingLeaveReviewsCard({ applications }: { applications: StudentLeaveApplication[] }) {
  return (
    <DashboardCard
      title={t['teacher.dashboard.pendingLeaveReviews']}
      action={
        <Link to="/student-leave" className="text-sm font-medium text-primary-600 hover:text-primary-500">
          {t['teacher.dashboard.viewAll']}
        </Link>
      }
    >
      {applications.length === 0 ? (
        <p className="py-6 text-center text-sm text-gray-500">{t['teacher.dashboard.noPendingLeaveReviews']}</p>
      ) : (
        <ul className="divide-y divide-gray-100">
          {applications.slice(0, 5).map((application) => (
            <li key={application.id} className="flex items-center justify-between py-3">
              <div>
                <span className="block text-sm font-medium text-gray-800">{application.student_profile?.user?.name ?? '—'}</span>
                <span className="block text-xs text-gray-500">
                  {application.from_date} – {application.to_date}
                </span>
              </div>
              <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">{application.status}</span>
            </li>
          ))}
        </ul>
      )}
    </DashboardCard>
  )
}

export default function DashboardHomePage() {
  const { name, token } = useAuth()
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [attendanceDays, setAttendanceDays] = useState<MyTeacherAttendanceDay[]>([])
  const [attendancePresentPercent, setAttendancePresentPercent] = useState<number | null>(null)
  const [pendingLeave, setPendingLeave] = useState<StudentLeaveApplication[]>([])

  useEffect(() => {
    if (!token) return
    let cancelled = false

    const now = new Date()

    getDashboardSummary(token).then((result) => {
      if (!cancelled) setSummary(result.data)
    })

    getMyTeacherAttendance({ year: now.getFullYear(), month: now.getMonth() + 1 }, token).then((result) => {
      if (cancelled || !result.data) return
      setAttendanceDays(result.data.days)
      const { present, absent, leave } = result.data.summary
      const total = present + absent + leave
      setAttendancePresentPercent(total > 0 ? Math.round((present / total) * 100) : null)
    })

    listLeaveApplications({ status: 'pending' }, token).then((result) => {
      if (!cancelled) setPendingLeave(result.data)
    })

    return () => {
      cancelled = true
    }
  }, [token])

  const welcome = name ? t['teacher.dashboard.welcome'].replace('{name}', name) : t['teacher.dashboard.welcomeGeneric']

  return (
    <section className="space-y-5 px-4 py-6 lg:px-6">
      <div
        className="rounded-md p-6 text-white md:p-8"
        style={{ background: 'linear-gradient(81deg, var(--color-primary-600) 3%, var(--color-primary-400) 93%)' }}
      >
        <h2 className="text-xl font-semibold">{welcome}</h2>
        <p className="mt-1 text-sm text-white/80">{t['teacher.dashboard.subtitle']}</p>
      </div>

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
        <StatTile
          icon="fact_check"
          accentClass="bg-danger-50 text-danger-500"
          label={t['teacher.dashboard.myAttendance']}
          value={attendancePresentPercent}
          suffix="%"
        />
        {summary?.teachers_count != null && (
          <StatTile icon="person" accentClass="bg-warning-50 text-warning-500" label={t['teacher.dashboard.totalTeachers']} value={summary.teachers_count} />
        )}
        {summary?.students_count != null && (
          <StatTile icon="face" accentClass="bg-success-50 text-success-600" label={t['teacher.dashboard.totalStudents']} value={summary.students_count} />
        )}
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <DashboardCard title={t['teacher.dashboard.attendanceTrend']}>
            <AttendanceTrendStrip days={attendanceDays} />
          </DashboardCard>
        </div>
        <div className="lg:col-span-2">
          <UpcomingHolidaysCard holidays={summary?.upcoming_holidays ?? null} />
        </div>
      </div>

      <PendingLeaveReviewsCard applications={pendingLeave} />
    </section>
  )
}
