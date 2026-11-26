import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  ApiError,
  getDashboardSummary,
  getHealth,
  type DashboardSummary,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

type HealthStatus = 'loading' | 'ok' | 'error'

const healthDot: Record<HealthStatus, string> = {
  loading: 'bg-gray-300',
  ok: 'bg-success-500',
  error: 'bg-danger-500',
}

function formatShortDate(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

function StatTile({
  icon,
  accentClass,
  label,
  value,
  suffix,
}: {
  icon: string
  accentClass: string
  label: string
  // null means "no data yet" (e.g. nothing marked present/absent/leave
  // today), which is distinct from — and must never render as — 0.
  value: number | null
  suffix?: string
}) {
  return (
    <div className="trezo-card flex items-center gap-4 rounded-md bg-white p-5 shadow-sm">
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

function AttendanceTrendCard({ trend }: { trend: DashboardSummary['attendance_trend'] }) {
  if (trend === null) return null

  const hasData = trend.some((day) => day.total > 0)
  const chartData = trend.map((day) => ({ ...day, label: formatShortDate(day.date) }))

  return (
    <DashboardCard title={t['management.dashboard.attendanceTrend']}>
      {!hasData ? (
        <p className="py-10 text-center text-sm text-gray-500">{t['management.dashboard.noAttendanceData']}</p>
      ) : (
        <div className="h-[260px]">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
              <defs>
                <linearGradient id="presentFill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="var(--color-success-500)" stopOpacity={0.35} />
                  <stop offset="95%" stopColor="var(--color-success-500)" stopOpacity={0.03} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-gray-100)" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} tickLine={false} axisLine={false} />
              <YAxis tick={{ fontSize: 12 }} tickLine={false} axisLine={false} allowDecimals={false} />
              <Tooltip formatter={(value: number) => [value, t['management.dashboard.present']]} />
              <Area type="monotone" dataKey="present" stroke="var(--color-success-500)" fill="url(#presentFill)" strokeWidth={2} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}
    </DashboardCard>
  )
}

function UpcomingHolidaysCard({ holidays }: { holidays: DashboardSummary['upcoming_holidays'] }) {
  if (holidays === null) return null

  return (
    <DashboardCard title={t['management.dashboard.upcomingHolidays']}>
      {holidays.length === 0 ? (
        <p className="py-6 text-center text-sm text-gray-500">{t['management.dashboard.noUpcomingHolidays']}</p>
      ) : (
        <ul className="space-y-3">
          {holidays.map((holiday) => (
            <li key={holiday.id} className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-warning-50 text-warning-500">
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

function RecentStudentsCard({ students }: { students: DashboardSummary['recent_students'] }) {
  if (students === null) return null

  return (
    <DashboardCard
      title={t['management.dashboard.recentAdmissions']}
      action={
        <Link to="/dashboard/students" className="text-sm font-medium text-primary-500 hover:text-primary-600">
          {t['management.dashboard.viewAll']}
        </Link>
      }
    >
      {students.length === 0 ? (
        <p className="py-6 text-center text-sm text-gray-500">{t['management.dashboard.noRecentStudents']}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-3 py-2 text-left whitespace-nowrap">{t['management.dashboard.studentName']}</th>
                <th className="px-3 py-2 text-left whitespace-nowrap">{t['management.fields.studentId']}</th>
                <th className="px-3 py-2 text-left whitespace-nowrap">{t['management.fields.school']}</th>
                <th className="px-3 py-2 text-left whitespace-nowrap">{t['management.fields.admissionDate']}</th>
              </tr>
            </thead>
            <tbody>
              {students.map((student) => (
                <tr key={student.user_id} className="border-t border-gray-100">
                  <td className="px-3 py-2.5 whitespace-nowrap">
                    <Link to={`/dashboard/students/${student.user_id}`} className="font-medium text-gray-800 hover:text-primary-500">
                      {student.name ?? '—'}
                    </Link>
                  </td>
                  <td className="px-3 py-2.5 text-gray-600 whitespace-nowrap">{student.student_id ?? '—'}</td>
                  <td className="px-3 py-2.5 text-gray-600 whitespace-nowrap">{student.school_name ?? '—'}</td>
                  <td className="px-3 py-2.5 text-gray-600 whitespace-nowrap">{student.admission_date ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </DashboardCard>
  )
}

export default function DashboardPage() {
  const { token, name, schoolId } = useAuth()
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [health, setHealth] = useState<HealthStatus>('loading')

  useEffect(() => {
    if (!token) return
    let cancelled = false

    getDashboardSummary(token)
      .then((result) => {
        if (!cancelled) setSummary(result.data)
      })
      .catch((caught: unknown) => {
        if (cancelled) return
        setLoadError(caught instanceof ApiError ? caught.message : t['management.dashboard.loadError'])
      })

    return () => {
      cancelled = true
    }
  }, [token])

  useEffect(() => {
    let cancelled = false

    getHealth()
      .then(() => {
        if (!cancelled) setHealth('ok')
      })
      .catch(() => {
        if (!cancelled) setHealth('error')
      })

    return () => {
      cancelled = true
    }
  }, [])

  const welcome = name ? t['management.dashboard.welcome'].replace('{name}', name) : t['management.dashboard.welcomeGeneric']

  return (
    <section className="space-y-5">
      <div
        className="rounded-md p-6 text-white md:p-8"
        style={{ background: 'linear-gradient(81deg, var(--color-primary-600) 3%, var(--color-primary-400) 93%)' }}
      >
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-xl font-semibold">{welcome}</h2>
            <p className="mt-1 text-sm text-white/80">{t['management.dashboard.subtitle']}</p>
          </div>
          <div className="flex items-center gap-2 rounded-md bg-black/10 px-3 py-1.5 text-xs">
            <span className={`h-2 w-2 rounded-full ${healthDot[health]}`} />
            <span>
              {health === 'loading' && t['dashboard.statusLoading']}
              {health === 'ok' && t['dashboard.statusOk']}
              {health === 'error' && t['dashboard.statusError']}
            </span>
          </div>
        </div>
      </div>

      {loadError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{loadError}</p>}

      {summary && (
        <>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {summary.schools_count !== null && schoolId === null && (
              <StatTile
                icon="apartment"
                accentClass="bg-primary-100 text-primary-600"
                label={t['management.dashboard.schoolsCount']}
                value={summary.schools_count}
              />
            )}
            {summary.teachers_count !== null && (
              <StatTile
                icon="person"
                accentClass="bg-warning-50 text-warning-500"
                label={t['management.dashboard.teachersCount']}
                value={summary.teachers_count}
              />
            )}
            {summary.students_count !== null && (
              <StatTile
                icon="face"
                accentClass="bg-success-50 text-success-600"
                label={t['management.dashboard.studentsCount']}
                value={summary.students_count}
              />
            )}
            {summary.attendance_today !== null && (
              <StatTile
                icon="fact_check"
                accentClass="bg-danger-50 text-danger-500"
                label={t['management.dashboard.attendanceToday']}
                value={summary.attendance_today.present_percent}
                suffix="%"
              />
            )}
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <div className="lg:col-span-3">
              <AttendanceTrendCard trend={summary.attendance_trend} />
            </div>
            <div className="lg:col-span-2">
              <UpcomingHolidaysCard holidays={summary.upcoming_holidays} />
            </div>
          </div>

          <RecentStudentsCard students={summary.recent_students} />
        </>
      )}
    </section>
  )
}
