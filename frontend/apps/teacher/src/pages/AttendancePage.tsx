import { useEffect, useState } from 'react'
import {
  ApiError,
  getAttendanceReport,
  getAttendanceRoster,
  listMyAttendanceSections,
  recordAttendance,
  type AttendanceReport,
  type AttendanceRoster,
  type AttendanceSection,
  type AttendanceStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

const STATUS_OPTIONS: AttendanceStatus[] = ['present', 'absent', 'leave']

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: t['teacher.attendance.statusPresent'],
  absent: t['teacher.attendance.statusAbsent'],
  leave: t['teacher.attendance.statusLeave'],
}

const STATUS_BADGE: Record<AttendanceStatus, string> = {
  present: 'border-success-500 bg-success-50 text-success-600',
  absent: 'border-danger-500 bg-danger-50 text-danger-600',
  leave: 'border-amber-500 bg-amber-50 text-amber-700',
}

const REPORT_LETTER: Record<AttendanceStatus, string> = {
  present: t['teacher.attendance.reportStatusP'],
  absent: t['teacher.attendance.reportStatusA'],
  leave: t['teacher.attendance.reportStatusL'],
}

const REPORT_COLOR: Record<AttendanceStatus, string> = {
  present: 'text-success-600',
  absent: 'text-danger-600',
  leave: 'text-amber-700',
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function TakeAttendanceTab({ sectionId, canRecord }: { sectionId: number; canRecord: boolean }) {
  const { token } = useAuth()

  const [date, setDate] = useState(today())
  const [loading, setLoading] = useState(true)
  const [roster, setRoster] = useState<AttendanceRoster | null>(null)
  const [pending, setPending] = useState<Record<number, AttendanceStatus>>({})
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)
    setMessage(null)

    getAttendanceRoster({ sectionId, date }, token)
      .then((result) => {
        if (!active) return
        setRoster(result.data)
        setPending(
          Object.fromEntries(
            result.data.students.filter((row) => row.status !== null).map((row) => [row.student_profile_id, row.status as AttendanceStatus]),
          ),
        )
      })
      .catch(() => {
        if (active) setRoster(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, date])

  async function handleSave() {
    if (!token || !roster) return

    const records = Object.entries(pending).map(([studentProfileId, status]) => ({
      student_profile_id: Number(studentProfileId),
      status,
    }))

    if (records.length === 0) return

    setSaving(true)
    setMessage(null)

    try {
      const result = await recordAttendance({ section_id: sectionId, date, records }, token)
      setRoster(result.data)
      setMessage({ kind: 'success', text: t['teacher.attendance.saveSuccess'] })
    } catch (caught) {
      setMessage({ kind: 'error', text: caught instanceof ApiError ? caught.message : t['teacher.attendance.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['teacher.attendance.date']}</span>
          <input
            type="date"
            className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={date}
            onChange={(event) => setDate(event.target.value)}
          />
        </label>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loading && roster && roster.holiday_name && (
        <p className="mt-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700">
          {t['teacher.attendance.holidayNotice'].replace('{name}', roster.holiday_name)}
        </p>
      )}

      {!loading && roster && !roster.holiday_name && roster.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.empty']}</p>
      )}

      {!loading && roster && !roster.holiday_name && roster.students.length > 0 && (
        <>
          {!canRecord && <p className="mt-4 text-sm text-gray-500">{t['teacher.attendance.viewOnlyNotice']}</p>}

          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[36rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colStatus']}</th>
                </tr>
              </thead>
              <tbody>
                {roster.students.map((row, index) => {
                  const current = pending[row.student_profile_id] ?? null

                  return (
                    <tr key={row.student_profile_id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="flex gap-2">
                          {STATUS_OPTIONS.map((status) => (
                            <button
                              key={status}
                              type="button"
                              disabled={!canRecord}
                              onClick={() => setPending((previous) => ({ ...previous, [row.student_profile_id]: status }))}
                              className={`rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                                current === status ? STATUS_BADGE[status] : 'border-gray-200 text-gray-500 hover:border-primary-300'
                              }`}
                            >
                              {STATUS_LABEL[status]}
                            </button>
                          ))}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {canRecord && (
            <div className="mt-4 flex items-center gap-3">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving ? t['teacher.attendance.saving'] : t['teacher.attendance.save']}
              </button>
              {message && (
                <span className={`text-sm ${message.kind === 'success' ? 'text-success-600' : 'text-danger-600'}`}>{message.text}</span>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

function MonthlyReportTab({ sectionId }: { sectionId: number }) {
  const { token } = useAuth()

  const now = new Date()
  const [month, setMonth] = useState(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
  const [loading, setLoading] = useState(true)
  const [report, setReport] = useState<AttendanceReport | null>(null)

  useEffect(() => {
    if (!token) return
    const [yearPart, monthPart] = month.split('-').map(Number)
    if (!yearPart || !monthPart) return

    let active = true
    setLoading(true)

    getAttendanceReport({ sectionId, year: yearPart, month: monthPart }, token)
      .then((result) => {
        if (active) setReport(result.data)
      })
      .catch(() => {
        if (active) setReport(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, month])

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['teacher.attendance.month']}</span>
          <input
            type="month"
            className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={month}
            onChange={(event) => setMonth(event.target.value)}
          />
        </label>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loading && report && report.students.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.empty']}</p>}

      {!loading && report && report.students.length > 0 && (
        <div className="mt-4 rounded-md bg-white p-5 shadow-sm">
          <div className="mb-4 text-sm text-gray-700">
            <p className="font-semibold text-gray-900">{report.section.school_name}</p>
            {report.section.school_address && <p className="text-xs text-gray-500">{report.section.school_address}</p>}
            <p className="mt-1">
              {[report.section.academic_year, report.section.class_name, report.section.class_version, report.section.shift, report.section.name]
                .filter(Boolean)
                .join('.')}
            </p>
            {report.section.class_teacher && (
              <p className="text-xs text-gray-500">
                {t['teacher.attendance.teacherLabel']}: {report.section.class_teacher}
              </p>
            )}
          </div>

          <div className="overflow-x-auto rounded-md border border-gray-100">
            <table className="w-full text-xs">
              <thead className="bg-primary-50 font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="sticky left-0 z-10 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">{t['teacher.attendance.colRoll']}</th>
                  <th className="sticky left-14 z-10 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">{t['teacher.attendance.colStudent']}</th>
                  {report.dates.map((dateInfo) => (
                    <th
                      key={dateInfo.date}
                      title={dateInfo.holiday_name ?? undefined}
                      className={`px-2 py-2 text-center whitespace-nowrap ${dateInfo.holiday_name ? 'bg-amber-50 text-amber-700' : ''}`}
                    >
                      {dateInfo.day}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.students.map((row) => (
                  <tr key={row.student_profile_id} className="border-t border-gray-100">
                    <td className="sticky left-0 z-10 bg-white px-3 py-2 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                    <td className="sticky left-14 z-10 bg-white px-3 py-2 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                    {report.dates.map((dateInfo) => {
                      const status = row.statuses[dateInfo.date]

                      return (
                        <td key={dateInfo.date} className="px-2 py-2 text-center">
                          {status ? (
                            <span className={`font-semibold ${REPORT_COLOR[status]}`}>{REPORT_LETTER[status]}</span>
                          ) : dateInfo.holiday_name ? (
                            <span className="text-amber-500">•</span>
                          ) : (
                            <span className="text-gray-300">—</span>
                          )}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

export default function AttendancePage() {
  const { token } = useAuth()

  const [loadingSections, setLoadingSections] = useState(true)
  const [sections, setSections] = useState<AttendanceSection[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)
  const [tab, setTab] = useState<'take' | 'report'>('take')

  useEffect(() => {
    if (!token) return
    let active = true

    listMyAttendanceSections(token)
      .then((result) => {
        if (!active) return
        setSections(result.data)
        setSectionId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => {
        if (active) setSections([])
      })
      .finally(() => {
        if (active) setLoadingSections(false)
      })

    return () => {
      active = false
    }
  }, [token])

  const selectedSection = sections.find((section) => section.id === sectionId) ?? null

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.attendance.title']}</h1>

      {loadingSections && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loadingSections && sections.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.noSections']}</p>}

      {!loadingSections && sections.length > 0 && (
        <>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <select
              className="h-11 min-w-[14rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              value={sectionId ?? ''}
              onChange={(event) => setSectionId(Number(event.target.value))}
            >
              {sections.map((section) => (
                <option key={section.id} value={section.id}>
                  {[section.class_name, section.class_version, section.shift, section.name].filter(Boolean).join(' - ')}
                </option>
              ))}
            </select>

            <div className="flex overflow-hidden rounded-md border border-gray-200">
              <button
                type="button"
                onClick={() => setTab('take')}
                className={`cursor-pointer px-4 py-2 text-sm font-medium transition-colors ${
                  tab === 'take' ? 'bg-primary-500 text-white' : 'text-gray-600 hover:bg-primary-50'
                }`}
              >
                {t['teacher.attendance.tabTake']}
              </button>
              <button
                type="button"
                onClick={() => setTab('report')}
                className={`cursor-pointer px-4 py-2 text-sm font-medium transition-colors ${
                  tab === 'report' ? 'bg-primary-500 text-white' : 'text-gray-600 hover:bg-primary-50'
                }`}
              >
                {t['teacher.attendance.tabReport']}
              </button>
            </div>
          </div>

          {selectedSection && tab === 'take' && <TakeAttendanceTab sectionId={selectedSection.id} canRecord={selectedSection.can_record} />}
          {selectedSection && tab === 'report' && <MonthlyReportTab sectionId={selectedSection.id} />}
        </>
      )}
    </section>
  )
}
