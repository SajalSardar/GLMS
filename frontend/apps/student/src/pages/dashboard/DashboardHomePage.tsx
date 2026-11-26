import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  getDashboardSummary,
  getMyAttendance,
  listMyChapterMaterials,
  type AttendanceStatus,
  type ChapterMaterial,
  type DashboardSummary,
  type MyAttendanceDay,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../../lib/locale'

interface QuickLink {
  to: string
  title: string
  desc: string
}

function formatShortDate(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' })
}

function StatTile({ icon, accentClass, label, value, suffix }: { icon: string; accentClass: string; label: string; value: number | null; suffix?: string }) {
  return (
    <div className="flex items-center gap-4 rounded-lg border border-primary-100 bg-white p-5 shadow-sm">
      <div className={`flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-full ${accentClass}`}>
        <span className="material-symbols-outlined !text-[26px]">{icon}</span>
      </div>
      <div>
        <span className="block text-sm text-primary-600">{label}</span>
        <span className="mt-0.5 block text-xl font-semibold text-primary-900">
          {value === null ? '—' : `${value.toLocaleString()}${suffix ?? ''}`}
        </span>
      </div>
    </div>
  )
}

function DashboardCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-lg border border-primary-100 bg-white p-5 shadow-sm md:p-6">
      <h3 className="mb-4 text-base font-semibold text-primary-800">{title}</h3>
      {children}
    </div>
  )
}

// A single student's attendance doesn't fit a multi-series chart the way
// the management portal's school-wide trend does — a day-by-day colored
// strip reads more clearly for "which of the last 7 days was I present."
function AttendanceTrendStrip({
  days,
  statusLabel,
  noDataLabel,
}: {
  days: MyAttendanceDay[]
  statusLabel: Record<AttendanceStatus, string>
  noDataLabel: string
}) {
  const statusDot: Record<AttendanceStatus, string> = {
    present: 'bg-success-500',
    absent: 'bg-danger-500',
    leave: 'bg-amber-500',
  }

  // `days` is the whole requested month, future dates included — slicing
  // the raw array can pick up days after today. Filter to today-or-earlier
  // first, then take the last 7 of those.
  const todayIso = new Date().toISOString().slice(0, 10)
  const last7 = days.filter((day) => day.date <= todayIso).slice(-7)
  const hasData = last7.some((day) => day.status !== null)

  if (!hasData) {
    return <p className="py-6 text-center text-sm text-primary-600">{noDataLabel}</p>
  }

  return (
    <div className="grid grid-cols-7 gap-2">
      {last7.map((day) => (
        <div key={day.date} className="flex flex-col items-center gap-2 rounded-md bg-primary-50 py-3">
          <span className="text-xs text-primary-600">{formatShortDate(day.date)}</span>
          {day.status ? (
            <>
              <span className={`h-3 w-3 rounded-full ${statusDot[day.status]}`} />
              <span className="text-[11px] text-primary-700">{statusLabel[day.status]}</span>
            </>
          ) : (
            <>
              <span className="h-3 w-3 rounded-full bg-primary-200" />
              <span className="text-[11px] text-primary-400">{day.holiday_name ?? '—'}</span>
            </>
          )}
        </div>
      ))}
    </div>
  )
}

function UpcomingHolidaysCard({
  holidays,
  title,
  noHolidaysLabel,
}: {
  holidays: DashboardSummary['upcoming_holidays']
  title: string
  noHolidaysLabel: string
}) {
  if (holidays === null) return null

  return (
    <DashboardCard title={title}>
      {holidays.length === 0 ? (
        <p className="py-6 text-center text-sm text-primary-600">{noHolidaysLabel}</p>
      ) : (
        <ul className="space-y-3">
          {holidays.map((holiday) => (
            <li key={holiday.id} className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-600">
                <span className="material-symbols-outlined !text-[20px]">event_busy</span>
              </div>
              <div>
                <span className="block text-sm font-medium text-primary-900">{holiday.name}</span>
                <span className="block text-xs text-primary-600">
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

function MaterialsNotice({ count }: { count: number }) {
  const { t } = useLocale()

  return (
    <Link
      to="/class-materials"
      className="flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700 hover:border-primary-400"
    >
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
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <path d="M14 2v6h6" />
      </svg>
      <p>{t['student.dashboard.newMaterials'].replace('{count}', String(count))}</p>
    </Link>
  )
}

export default function DashboardHomePage() {
  const { t } = useLocale()
  const { name, token } = useAuth()

  const [myMaterials, setMyMaterials] = useState<ChapterMaterial[]>([])
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [attendanceDays, setAttendanceDays] = useState<MyAttendanceDay[]>([])
  const [attendancePresentPercent, setAttendancePresentPercent] = useState<number | null>(null)

  useEffect(() => {
    if (!token) return

    let active = true
    const now = new Date()

    // Scoped entirely server-side to this student's own school/district/
    // shift/section/class/class_version tuple (ChapterMaterialController::
    // mine()) — an empty result here just means nothing new for their
    // exact class, not a loading/error state worth showing its own UI for.
    listMyChapterMaterials(token)
      .then((result) => {
        if (active) setMyMaterials(result.data)
      })
      .catch(() => {
        if (active) setMyMaterials([])
      })

    getDashboardSummary(token).then((result) => {
      if (active) setSummary(result.data)
    })

    getMyAttendance({ year: now.getFullYear(), month: now.getMonth() + 1 }, token).then((result) => {
      if (!active || !result.data) return
      setAttendanceDays(result.data.days)
      const { present, absent, leave } = result.data.summary
      const total = present + absent + leave
      setAttendancePresentPercent(total > 0 ? Math.round((present / total) * 100) : null)
    })

    return () => {
      active = false
    }
  }, [token])

  const quickLinks: QuickLink[] = [
    { to: '/books', title: t['student.dashboard.booksTitle'], desc: t['student.dashboard.booksDesc'] },
    {
      to: '/video-lecture',
      title: t['student.dashboard.videoLectureTitle'],
      desc: t['student.dashboard.videoLectureDesc'],
    },
    { to: '/exams', title: t['student.dashboard.examsTitle'], desc: t['student.dashboard.examsDesc'] },
  ]

  const statusLabel: Record<AttendanceStatus, string> = {
    present: t['student.dashboard.present'],
    absent: t['student.dashboard.absent'],
    leave: t['student.dashboard.leave'],
  }

  const welcome = name ? t['student.dashboard.welcome'].replace('{name}', name) : t['student.dashboard.welcomeGeneric']

  return (
    <section className="space-y-5 px-4 py-6 lg:px-6">
      <div>
        <h1 className="text-2xl font-bold text-primary-800">{t['student.dashboard.title']}</h1>
        <p className="mt-1 text-base font-medium text-primary-700">{welcome}</p>
        <p className="mt-1 text-sm text-primary-600">{t['student.dashboard.subtitle']}</p>
      </div>

      {myMaterials.length > 0 && <MaterialsNotice count={myMaterials.length} />}

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <StatTile
          icon="fact_check"
          accentClass="bg-success-50 text-success-600"
          label={t['student.dashboard.myAttendance']}
          value={attendancePresentPercent}
          suffix="%"
        />
        <StatTile
          icon="folder_open"
          accentClass="bg-primary-100 text-primary-600"
          label={t['student.dashboard.newMaterialsCount']}
          value={myMaterials.length}
        />
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <DashboardCard title={t['student.dashboard.attendanceTrend']}>
            <AttendanceTrendStrip days={attendanceDays} statusLabel={statusLabel} noDataLabel={t['student.dashboard.noAttendanceData']} />
          </DashboardCard>
        </div>
        <div className="lg:col-span-2">
          <UpcomingHolidaysCard
            holidays={summary?.upcoming_holidays ?? null}
            title={t['student.dashboard.upcomingHolidays']}
            noHolidaysLabel={t['student.dashboard.noUpcomingHolidays']}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {quickLinks.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="rounded-lg border border-primary-100 bg-white p-5 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">{link.title}</p>
            <p className="mt-1 text-sm text-primary-600">{link.desc}</p>
          </Link>
        ))}
      </div>
    </section>
  )
}
