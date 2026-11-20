import { useEffect, useState } from 'react'
import {
  getAttendanceReport,
  listAcademicYears,
  listSchools,
  listSections,
  type AcademicYear,
  type AttendanceReport,
  type AttendanceStatus,
  type School,
  type Section,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

// Used only to render the month-picker's 3x4 grid of month names.
const MONTH_NAMES = Array.from({ length: 12 }, (_, index) => new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'short' }))

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

/**
 * Collapsed to just "{Month} {Year}" — clicking it drops down a 3x4 grid
 * of month names for a browsable year (prev/next arrows), rather than a
 * native <input type="month">, whose picker UI varies by browser/OS. No
 * date-level restriction here (unlike the teacher portal's day-level
 * SmartCalendar for recording) — a report can be pulled for any month,
 * past or future.
 */
function SmartMonthPicker({
  year,
  month,
  onChange,
}: {
  year: number
  month: number
  onChange: (year: number, month: number) => void
}) {
  const [expanded, setExpanded] = useState(false)
  const [viewYear, setViewYear] = useState(year)

  function toggle() {
    if (!expanded) setViewYear(year)
    setExpanded((previous) => !previous)
  }

  function pick(selectedMonth: number) {
    onChange(viewYear, selectedMonth)
    setExpanded(false)
  }

  const label = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })

  return (
    <div className="relative">
      <button
        type="button"
        onClick={toggle}
        className="flex h-11 min-w-[12rem] cursor-pointer items-center justify-between gap-2 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 transition-colors hover:border-primary-300"
      >
        <span className="flex items-center gap-2">
          <span className="material-symbols-outlined !text-[20px] leading-none text-gray-400">calendar_month</span>
          {label}
        </span>
        <span className="material-symbols-outlined !text-[18px] leading-none text-gray-400">{expanded ? 'expand_less' : 'expand_more'}</span>
      </button>

      {expanded && (
        <>
          {/* Transparent backdrop — clicking outside the popover closes it without picking a month, same pattern used elsewhere. */}
          <div className="fixed inset-0 z-40" onClick={() => setExpanded(false)} />

          <div className="absolute top-full left-0 z-50 mt-2 w-64 rounded-md border border-gray-100 bg-white p-4 shadow-xl">
            <div className="flex items-center justify-between">
              <button
                type="button"
                onClick={() => setViewYear((previous) => previous - 1)}
                aria-label="Previous year"
                className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:border-primary-300 hover:text-primary-700"
              >
                ‹
              </button>
              <span className="text-sm font-semibold text-gray-800">{viewYear}</span>
              <button
                type="button"
                onClick={() => setViewYear((previous) => previous + 1)}
                aria-label="Next year"
                className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:border-primary-300 hover:text-primary-700"
              >
                ›
              </button>
            </div>

            <div className="mt-3 grid grid-cols-3 gap-2">
              {MONTH_NAMES.map((name, index) => {
                const monthNumber = index + 1
                const isSelected = viewYear === year && monthNumber === month

                return (
                  <button
                    key={name}
                    type="button"
                    onClick={() => pick(monthNumber)}
                    className={`cursor-pointer rounded-md border px-2 py-2 text-xs font-medium transition-colors ${
                      isSelected ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-100 text-gray-700 hover:border-primary-300 hover:bg-primary-50'
                    }`}
                  >
                    {name}
                  </button>
                )
              })}
            </div>
          </div>
        </>
      )}
    </div>
  )
}

// Report-only — school_admin/national_admin get visibility into every
// section's attendance (per the feature request), not the ability to
// record it; recording stays locked to each section's own class teacher
// in the teacher portal (see AttendancePolicy::recordSection()).
export default function AttendanceReportPage() {
  const { token } = useAuth()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | 'all'>('all')

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState<AttendanceReport | null>(null)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listAcademicYears({ perPage: 200 })
      .then((result) => {
        setAcademicYears(result.data)
        setAcademicYearId(result.data.find((year) => year.is_current)?.id ?? 'all')
      })
      .catch(() => setAcademicYears([]))
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
        setSectionId(null)
      })
      .catch(() => {
        if (active) setSections([])
      })

    return () => {
      active = false
    }
  }, [schoolId])

  // Academic Year only narrows this dropdown's options (useful once a
  // school has several years' worth of sections) — it never drives the
  // report's own year, which always comes from the Month picker instead.
  const scopedSections = academicYearId === 'all' ? sections : sections.filter((section) => section.shift?.academic_year_id === academicYearId)
  const effectiveSectionId = scopedSections.some((section) => section.id === sectionId) ? sectionId : (scopedSections[0]?.id ?? null)

  useEffect(() => {
    if (!token || !effectiveSectionId) {
      setReport(null)
      return
    }

    let active = true
    setLoading(true)

    getAttendanceReport({ sectionId: effectiveSectionId, year, month }, token)
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
  }, [token, effectiveSectionId, year, month])

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.attendance.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.attendance.selectSchool']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((school) => ({ value: String(school.id), label: school.bn_name ?? school.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectAcademicYear']}</span>
          <select
            className={`${inputClass} min-w-[9rem]`}
            value={academicYearId}
            onChange={(event) => {
              const next = event.target.value
              setAcademicYearId(next === 'all' ? 'all' : Number(next))
              setSectionId(null)
            }}
          >
            <option value="all">{t['management.fields.allAcademicYears']}</option>
            {academicYears.map((academicYear) => (
              <option key={academicYear.id} value={academicYear.id}>
                {academicYear.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSection']}</span>
          <select
            className={`${inputClass} min-w-[14rem]`}
            value={effectiveSectionId ?? ''}
            onChange={(event) => setSectionId(Number(event.target.value))}
            disabled={scopedSections.length === 0}
          >
            {scopedSections.length === 0 && <option value="">{t['management.attendance.selectSectionPlaceholder']}</option>}
            {scopedSections.map((section) => (
              <option key={section.id} value={section.id}>
                {sectionLabel(section)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.month']}</span>
          <SmartMonthPicker
            year={year}
            month={month}
            onChange={(nextYear, nextMonth) => {
              setYear(nextYear)
              setMonth(nextMonth)
            }}
          />
        </label>
      </div>

      {!effectiveSectionId && <p className="mt-6 text-sm text-gray-500">{t['management.attendance.noSection']}</p>}

      {effectiveSectionId && loading && <p className="mt-6 text-sm text-gray-500">{t['management.attendance.loading']}</p>}

      {effectiveSectionId && !loading && report && report.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['management.attendance.empty']}</p>
      )}

      {effectiveSectionId && !loading && report && report.students.length > 0 && (
        <div className="mt-4 rounded-md bg-white p-5 shadow-sm">
          <div className="mb-4 text-center text-sm text-gray-700">
            <p className="text-base font-semibold text-gray-900">{report.section.school_name}</p>
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

          <div className="max-h-[28rem] overflow-x-auto overflow-y-auto rounded-md border border-gray-100">
            <table className="w-full text-xs">
              <thead className="font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="sticky top-0 left-0 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                    {t['management.attendance.colRoll']}
                  </th>
                  <th className="sticky top-0 left-14 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                    {t['management.attendance.colStudent']}
                  </th>
                  {report.dates.map((dateInfo) => (
                    <th
                      key={dateInfo.date}
                      title={dateInfo.holiday_name ?? undefined}
                      className={`sticky top-0 z-10 px-2 py-2 text-center whitespace-nowrap ${
                        dateInfo.holiday_name ? 'bg-amber-50 text-amber-700' : 'bg-primary-50'
                      }`}
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
