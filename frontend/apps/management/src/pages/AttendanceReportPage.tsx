import { useEffect, useState } from 'react'
import {
  getAttendanceReport,
  listSchools,
  listSections,
  type AttendanceReport,
  type AttendanceStatus,
  type School,
  type Section,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

const REPORT_LETTER: Record<AttendanceStatus, string> = {
  present: t['management.attendance.statusPresent'],
  absent: t['management.attendance.statusAbsent'],
  leave: t['management.attendance.statusLeave'],
}

const REPORT_COLOR: Record<AttendanceStatus, string> = {
  present: 'text-success-600',
  absent: 'text-danger-600',
  leave: 'text-amber-700',
}

function sectionLabel(section: Section): string {
  return [section.shift?.class_version?.class?.name, section.shift?.class_version?.name, section.shift?.name, section.name]
    .filter(Boolean)
    .join(' - ')
}

// Report-only — school_admin/national_admin get visibility into every
// section's attendance (per the feature request), not the ability to
// record it; recording stays locked to each section's own class teacher
// in the teacher portal (see AttendancePolicy::recordSection()).
export default function AttendanceReportPage() {
  const { token } = useAuth()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)
  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const now = new Date()
  const [month, setMonth] = useState(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`)
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState<AttendanceReport | null>(null)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))
  }, [])

  useEffect(() => {
    if (!schoolId) {
      setSections([])
      return
    }

    let active = true

    listSections({ schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setSections(result.data)
        setSectionId(result.data[0]?.id ?? null)
      })
      .catch(() => {
        if (active) setSections([])
      })

    return () => {
      active = false
    }
  }, [schoolId])

  useEffect(() => {
    if (!token || !sectionId) {
      setReport(null)
      return
    }

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
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.attendance.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <select
            className="h-11 min-w-[14rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={schoolId ?? ''}
            onChange={(event) => setSchoolId(Number(event.target.value))}
          >
            {schools.map((school) => (
              <option key={school.id} value={school.id}>
                {school.bn_name ?? school.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSection']}</span>
          <select
            className="h-11 min-w-[14rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={sectionId ?? ''}
            onChange={(event) => setSectionId(Number(event.target.value))}
            disabled={sections.length === 0}
          >
            {sections.length === 0 && <option value="">{t['management.attendance.selectSectionPlaceholder']}</option>}
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {sectionLabel(section)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.month']}</span>
          <input
            type="month"
            className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={month}
            onChange={(event) => setMonth(event.target.value)}
          />
        </label>
      </div>

      {!sectionId && <p className="mt-6 text-sm text-gray-500">{t['management.attendance.noSection']}</p>}

      {sectionId && loading && <p className="mt-6 text-sm text-gray-500">{t['management.attendance.loading']}</p>}

      {sectionId && !loading && report && report.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['management.attendance.empty']}</p>
      )}

      {sectionId && !loading && report && report.students.length > 0 && (
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
                {t['management.attendance.teacherLabel']}: {report.section.class_teacher}
              </p>
            )}
          </div>

          <div className="overflow-x-auto rounded-md border border-gray-100">
            <table className="w-full text-xs">
              <thead className="bg-primary-50 font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="sticky left-0 z-10 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">{t['management.attendance.colRoll']}</th>
                  <th className="sticky left-14 z-10 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                    {t['management.attendance.colStudent']}
                  </th>
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
    </section>
  )
}
