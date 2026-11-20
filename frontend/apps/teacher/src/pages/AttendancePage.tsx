import { useEffect, useState } from 'react'
import {
  ApiError,
  getAttendanceReport,
  getAttendanceRoster,
  listAcademicYears,
  listMyAttendanceSections,
  recordAttendance,
  type AcademicYear,
  type AttendanceReport,
  type AttendanceRoster,
  type AttendanceRosterRow,
  type AttendanceSection,
  type AttendanceStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

// This app fixes the locale to 'en' (see `t` above), so generating these
// from a fixed reference date is simpler than adding 12 more i18n keys.
const MONTH_NAMES = Array.from({ length: 12 }, (_, index) => new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'long' }))

/**
 * First row carrying each distinct, non-null key — used to turn the flat
 * `mySections` list into the options for one level of the Academic Year
 * -> Class -> Class Version -> Shift cascade without a dedicated
 * endpoint per level.
 */
function uniqueByKey<T, K>(items: T[], keyFn: (item: T) => K | null): T[] {
  const seen = new Set<K>()
  const result: T[] = []

  for (const item of items) {
    const key = keyFn(item)
    if (key === null || seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

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

// "YYYY-MM-DD" parsed as local-midnight, not via `new Date(str)` (which
// reads a date-only ISO string as UTC midnight — shifting .getDay() by a
// day for any viewer west of UTC). Same fix as the student portal's own
// calendar.
function parseLocalDate(dateStr: string): Date {
  const [year, month, day] = dateStr.split('-').map(Number)
  return new Date(year, month - 1, day)
}

// Shared between the Take Attendance tab (editable, today-or-any-date) and
// the View/Edit modal opened from the Attendance List tab (a specific
// past date) — the per-student status-button row is identical either
// way, only `canEdit` changes.
function RosterTable({
  students,
  pending,
  canEdit,
  onChange,
}: {
  students: AttendanceRosterRow[]
  pending: Record<number, AttendanceStatus>
  canEdit: boolean
  onChange: (studentProfileId: number, status: AttendanceStatus) => void
}) {
  return (
    // max-h + overflow-y-auto fixes the roster's height regardless of
    // section size, instead of letting a large class push the save
    // button (and everything below it) off-screen; the header stays
    // `sticky` within that scroll area so column labels never scroll out
    // of view.
    <div className="max-h-[26rem] overflow-y-auto overflow-x-auto rounded-md bg-white shadow-sm">
      <table className="w-full min-w-[32rem] text-sm">
        <thead className="sticky top-0 z-10 bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
          <tr>
            <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colRoll']}</th>
            <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colStudent']}</th>
            <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colStatus']}</th>
          </tr>
        </thead>
        <tbody>
          {students.map((row) => {
            const current = pending[row.student_profile_id] ?? null

            return (
              <tr key={row.student_profile_id} className="border-t border-gray-100">
                <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                <td className="px-5 py-3.5 whitespace-nowrap">
                  <div className="flex gap-2">
                    {STATUS_OPTIONS.map((status) => (
                      <button
                        key={status}
                        type="button"
                        disabled={!canEdit}
                        onClick={() => onChange(row.student_profile_id, status)}
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
  )
}

function RosterModal({
  sectionId,
  date,
  mode,
  canRecord,
  onClose,
}: {
  sectionId: number
  date: string
  mode: 'view' | 'edit'
  canRecord: boolean
  onClose: () => void
}) {
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [roster, setRoster] = useState<AttendanceRoster | null>(null)
  const [pending, setPending] = useState<Record<number, AttendanceStatus>>({})
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)

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

  const canEdit = mode === 'edit' && canRecord

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
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t['teacher.attendance.modalTitle'].replace('{date}', date)}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">{t['teacher.attendance.modalTitle'].replace('{date}', date)}</h3>
          <button
            type="button"
            className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
            onClick={onClose}
            aria-label={t['management.table.close']}
          >
            ×
          </button>
        </div>

        <div className="p-5">
          {loading && <p className="text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

          {!loading && roster && roster.holiday_name && (
            <p className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700">
              {t['teacher.attendance.holidayNotice'].replace('{name}', roster.holiday_name)}
            </p>
          )}

          {!loading && roster && !roster.holiday_name && roster.students.length === 0 && (
            <p className="text-sm text-gray-500">{t['teacher.attendance.empty']}</p>
          )}

          {!loading && roster && !roster.holiday_name && roster.students.length > 0 && (
            <>
              {mode === 'view' && <p className="mb-3 text-sm text-gray-500">{t['teacher.attendance.viewModeNotice']}</p>}
              {mode === 'edit' && !canRecord && <p className="mb-3 text-sm text-gray-500">{t['teacher.attendance.viewOnlyNotice']}</p>}

              <RosterTable
                students={roster.students}
                pending={pending}
                canEdit={canEdit}
                onChange={(studentProfileId, status) => setPending((previous) => ({ ...previous, [studentProfileId]: status }))}
              />

              {canEdit && (
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
      </div>
    </div>
  )
}

/**
 * "Smart" in three ways: every visible day already shows whether
 * attendance was recorded for it (a dot) and whether it's a holiday
 * (greyed out, unclickable, same rule the backend enforces on
 * record()); it never lets the teacher pick a date past today — there's
 * nothing to take attendance FOR yet; and it stays locked to the current
 * calendar month (no prev/next paging) since editing a past month's
 * attendance is the Attendance List page's job, not this one's.
 *
 * Collapsed by default to just the month name — clicking it drops down
 * the full grid as a popover (closed again by picking a day, or by
 * clicking the backdrop) rather than permanently taking up page height.
 */
function SmartCalendar({
  sectionId,
  selectedDate,
  onSelect,
}: {
  sectionId: number
  selectedDate: string
  onSelect: (date: string) => void
}) {
  const { token } = useAuth()

  const [expanded, setExpanded] = useState(false)
  const [loading, setLoading] = useState(true)
  const [report, setReport] = useState<AttendanceReport | null>(null)

  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth() + 1

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)

    getAttendanceReport({ sectionId, year, month }, token)
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
  }, [token, sectionId, year, month])

  const recordedDates = new Set<string>()
  for (const student of report?.students ?? []) {
    for (const [date, status] of Object.entries(student.statuses)) {
      if (status) recordedDates.add(date)
    }
  }

  const dates = report?.dates ?? []
  const leadingBlanks = dates.length > 0 ? parseLocalDate(dates[0].date).getDay() : 0
  const cells: Array<{ date: string; day: number; holiday_name: string | null } | null> = [
    ...Array.from({ length: leadingBlanks }, () => null),
    ...dates,
  ]

  const todayStr = today()
  // "November 20" — the actual selected date, not just the month it falls
  // in, now that a specific day is pre-selected (today) by default.
  const triggerLabel = parseLocalDate(selectedDate).toLocaleDateString('en-US', { month: 'long', day: 'numeric' })

  function handlePick(date: string) {
    onSelect(date)
    setExpanded(false)
  }

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setExpanded((previous) => !previous)}
        className="flex h-11 min-w-[10rem] cursor-pointer items-center justify-between gap-2 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 transition-colors hover:border-primary-300"
      >
        <span className="flex items-center gap-2">
          <span className="material-symbols-outlined !text-[20px] leading-none text-gray-400">calendar_month</span>
          {triggerLabel}
        </span>
        <span className="material-symbols-outlined !text-[18px] leading-none text-gray-400">{expanded ? 'expand_less' : 'expand_more'}</span>
      </button>

      {expanded && (
        <>
          {/* Transparent backdrop, same pattern as RosterModal — clicking
              outside the popover closes it without picking a date. */}
          <div className="fixed inset-0 z-40" onClick={() => setExpanded(false)} />

          {/* Explicit width, not w-fit — fit-content doesn't resolve this
              grid's `fr` tracks correctly while absolutely positioned,
              which squeezed every day cell into a sliver and made
              neighbouring buttons overlap (confirmed: real, clickable
              buttons sitting on top of each other, not just a visual
              glitch). 7 cols * 2.5rem (w-10) + 6 gaps * 0.25rem (gap-1) +
              2rem padding (p-4) = 21rem, computed instead of guessed. */}
          <div className="absolute top-full left-0 z-50 mt-2 w-[21rem] rounded-md border border-gray-100 bg-white p-4 shadow-xl">
            <p className="text-center text-sm font-semibold text-gray-800">
              {new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
            </p>

            <div className="mt-3 grid grid-cols-7 gap-1">
              {/* 2023-01-01 is a Sunday — stable reference date for weekday names, same trick as the student portal's calendar. */}
              {Array.from({ length: 7 }, (_, index) => new Date(2023, 0, 1 + index)).map((date) => (
                <div key={date.getDay()} className="w-10 pb-1 text-center text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                  {date.toLocaleDateString('en-US', { weekday: 'short' })}
                </div>
              ))}

              {cells.map((cell, index) => {
                if (cell === null) return <div key={`blank-${index}`} className="h-10 w-10" />

                const isFuture = cell.date > todayStr
                const disabled = !!cell.holiday_name || isFuture

                return (
                  <button
                    key={cell.date}
                    type="button"
                    disabled={disabled}
                    onClick={() => handlePick(cell.date)}
                    title={cell.holiday_name ?? undefined}
                    className={`relative flex h-10 w-10 flex-col items-center justify-center rounded-md border text-xs font-medium transition-colors disabled:cursor-not-allowed ${
                      disabled
                        ? `border-gray-200 bg-gray-50 text-gray-400 ${cell.date === selectedDate ? 'ring-2 ring-primary-300 ring-offset-1' : ''}`
                        : cell.date === selectedDate
                          ? 'border-primary-500 bg-primary-500 text-white'
                          : cell.date === todayStr
                            ? 'border-primary-300 text-primary-700 hover:bg-primary-50'
                            : 'cursor-pointer border-gray-100 text-gray-700 hover:border-primary-300 hover:bg-primary-50'
                    }`}
                  >
                    <span>{cell.day}</span>
                    {recordedDates.has(cell.date) && (
                      <span
                        className={`absolute bottom-1 h-1.5 w-1.5 rounded-full ${
                          cell.date === selectedDate && !disabled ? 'bg-white' : 'bg-success-500'
                        }`}
                      />
                    )}
                  </button>
                )
              })}
            </div>

            {loading && <p className="mt-2 text-xs text-gray-400">{t['teacher.attendance.loading']}</p>}
          </div>
        </>
      )}
    </div>
  )
}

function TakeAttendanceTab({
  sections,
  sectionId,
  onSectionChange,
}: {
  sections: AttendanceSection[]
  sectionId: number
  onSectionChange: (sectionId: number) => void
}) {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const selectedSection = sections.find((section) => section.id === sectionId) ?? null
  const canRecord = selectedSection?.can_record ?? false

  const [date, setDate] = useState(today())
  const [loading, setLoading] = useState(true)
  const [roster, setRoster] = useState<AttendanceRoster | null>(null)
  const [pending, setPending] = useState<Record<number, AttendanceStatus>>({})
  const [isEditing, setIsEditing] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)
    setIsEditing(false)

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

  // Whether THIS roster already had at least one status saved when it was
  // loaded — read from `roster` (the last server snapshot), not `pending`
  // (which already mirrors those same statuses the moment they load, so
  // it can't tell "was this already recorded" from "did the teacher just
  // now pick one").
  const alreadyRecorded = roster?.students.some((row) => row.status !== null) ?? false
  // A holiday is never editable (the backend rejects record() outright
  // for one) — the roster still loads and displays for context, same as
  // any other date, it just can't be acted on.
  const canEdit = canRecord && !roster?.holiday_name && (!alreadyRecorded || isEditing)

  async function handleSave() {
    if (!token || !roster) return

    const records = Object.entries(pending).map(([studentProfileId, status]) => ({
      student_profile_id: Number(studentProfileId),
      status,
    }))

    if (records.length === 0) return

    const wasAlreadyRecorded = alreadyRecorded

    setSaving(true)

    try {
      const result = await recordAttendance({ section_id: sectionId, date, records }, token)
      setRoster(result.data)
      setIsEditing(false)
      showToast({ type: 'success', message: wasAlreadyRecorded ? t['teacher.attendance.updateSuccess'] : t['teacher.attendance.saveSuccess'] })
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.attendance.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  function handleCancelEdit() {
    setIsEditing(false)
    // Discards any unsaved picks made during this edit, back to the
    // server's own last-saved statuses.
    setPending(
      Object.fromEntries(
        (roster?.students ?? []).filter((row) => row.status !== null).map((row) => [row.student_profile_id, row.status as AttendanceStatus]),
      ),
    )
  }

  return (
    <div className="mt-4">
      <div className="flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <div className="relative">
          <span className="material-symbols-outlined pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 !text-[20px] leading-none text-gray-400">
            groups
          </span>
          <select
            className="h-11 min-w-[14rem] rounded-md border border-gray-200 bg-white py-0 pr-3 pl-10 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={sectionId}
            onChange={(event) => onSectionChange(Number(event.target.value))}
          >
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {[section.class_name, section.class_version, section.shift, section.name].filter(Boolean).join(' - ')}
              </option>
            ))}
          </select>
        </div>

        <SmartCalendar sectionId={sectionId} selectedDate={date} onSelect={setDate} />
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loading && roster && roster.holiday_name && (
        <p className="mt-6 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700">
          {t['teacher.attendance.holidayNotice'].replace('{name}', roster.holiday_name)}
        </p>
      )}

      {!loading && roster && roster.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.empty']}</p>
      )}

      {!loading && roster && roster.students.length > 0 && (
        <>
          {!canRecord && !roster.holiday_name && <p className="mt-4 text-sm text-gray-500">{t['teacher.attendance.viewOnlyNotice']}</p>}

          <div className="mt-4">
            <RosterTable
              students={roster.students}
              pending={pending}
              canEdit={canEdit}
              onChange={(studentProfileId, status) => setPending((previous) => ({ ...previous, [studentProfileId]: status }))}
            />
          </div>

          {canRecord && !roster.holiday_name && (
            <div className="mt-4 flex items-center gap-3">
              {!alreadyRecorded && (
                <button
                  type="button"
                  onClick={handleSave}
                  disabled={saving}
                  className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {saving ? t['teacher.attendance.saving'] : t['teacher.attendance.save']}
                </button>
              )}

              {alreadyRecorded && !isEditing && (
                <button
                  type="button"
                  onClick={() => setIsEditing(true)}
                  className="cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-primary-300"
                >
                  {t['management.table.edit']}
                </button>
              )}

              {alreadyRecorded && isEditing && (
                <>
                  <button
                    type="button"
                    onClick={handleSave}
                    disabled={saving}
                    className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {saving ? t['teacher.attendance.updating'] : t['teacher.attendance.update']}
                  </button>
                  <button
                    type="button"
                    onClick={handleCancelEdit}
                    disabled={saving}
                    className="cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {t['management.table.cancel']}
                  </button>
                </>
              )}
            </div>
          )}
        </>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}

/**
 * Shared by the Attendance List and Monthly Report pages: Academic Year
 * -> Class -> Class Version -> Shift -> Section cascading selects plus a
 * Month select (12 named options, no calendar widget), built entirely
 * from the teacher's own flat `mySections` list rather than a dedicated
 * endpoint per level.
 *
 * Academic Year only narrows which classes/sections are offered — once a
 * specific Section is resolved, the report's actual `year` always comes
 * from that section's own academic year (via its shift), never from this
 * filter directly. That's what lets "All academic years" stay a valid
 * choice: there's no single "current" year to fall back on, but every
 * concrete section still resolves to exactly one.
 */
function useSectionCascadeFilter() {
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [sections, setSections] = useState<AttendanceSection[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])

  const [academicYearId, setAcademicYearId] = useState<number | 'all'>('all')
  const [classId, setClassId] = useState<number | null>(null)
  const [classVersionId, setClassVersionId] = useState<number | null>(null)
  const [shiftId, setShiftId] = useState<number | null>(null)
  const [sectionId, setSectionId] = useState<number | null>(null)
  const [month, setMonth] = useState(new Date().getMonth() + 1)

  useEffect(() => {
    if (!token) return
    let active = true

    Promise.all([listMyAttendanceSections(token), listAcademicYears({ perPage: 200 })])
      .then(([sectionsResult, yearsResult]) => {
        if (!active) return
        setSections(sectionsResult.data)
        setAcademicYears(yearsResult.data)
        setAcademicYearId(yearsResult.data.find((year) => year.is_current)?.id ?? 'all')
      })
      .catch(() => {
        if (active) {
          setSections([])
          setAcademicYears([])
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token])

  const scopedSections = academicYearId === 'all' ? sections : sections.filter((section) => section.academic_year_id === academicYearId)

  // Each level's <select> uses this "effective" id (falling back to the
  // first still-valid option) rather than the raw state directly — that
  // way picking a new Academic Year/Class/.../Shift never leaves a
  // deeper select pointing at an option that's no longer offered.
  const classRows = uniqueByKey(scopedSections, (section) => section.class_id)
  const effectiveClassId = classRows.some((row) => row.class_id === classId) ? classId : (classRows[0]?.class_id ?? null)

  const versionRows = uniqueByKey(
    scopedSections.filter((section) => section.class_id === effectiveClassId),
    (section) => section.class_version_id,
  )
  const effectiveVersionId = versionRows.some((row) => row.class_version_id === classVersionId)
    ? classVersionId
    : (versionRows[0]?.class_version_id ?? null)

  const shiftRows = uniqueByKey(
    scopedSections.filter((section) => section.class_id === effectiveClassId && section.class_version_id === effectiveVersionId),
    (section) => section.shift_id,
  )
  const effectiveShiftId = shiftRows.some((row) => row.shift_id === shiftId) ? shiftId : (shiftRows[0]?.shift_id ?? null)

  const sectionRows = scopedSections.filter(
    (section) => section.class_id === effectiveClassId && section.class_version_id === effectiveVersionId && section.shift_id === effectiveShiftId,
  )
  const effectiveSectionId = sectionRows.some((row) => row.id === sectionId) ? sectionId : (sectionRows[0]?.id ?? null)

  const selectedSection = sections.find((section) => section.id === effectiveSectionId) ?? null
  const year = selectedSection
    ? Number(academicYears.find((academicYear) => academicYear.id === selectedSection.academic_year_id)?.name ?? new Date().getFullYear())
    : new Date().getFullYear()

  const selectClass = 'h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500'

  const filterBar = (
    <div className="flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-gray-700">{t['teacher.attendance.filterAcademicYear']}</span>
        <select
          className={selectClass}
          value={academicYearId}
          onChange={(event) => {
            const next = event.target.value
            setAcademicYearId(next === 'all' ? 'all' : Number(next))
            setClassId(null)
            setClassVersionId(null)
            setShiftId(null)
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
        <span className="font-medium text-gray-700">{t['management.fields.class']}</span>
        <select
          className={selectClass}
          value={effectiveClassId ?? ''}
          onChange={(event) => {
            setClassId(Number(event.target.value))
            setClassVersionId(null)
            setShiftId(null)
            setSectionId(null)
          }}
        >
          {classRows.map((row) => (
            <option key={row.class_id} value={row.class_id ?? ''}>
              {row.class_name}
            </option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-gray-700">{t['management.fields.classVersion']}</span>
        <select
          className={selectClass}
          value={effectiveVersionId ?? ''}
          onChange={(event) => {
            setClassVersionId(Number(event.target.value))
            setShiftId(null)
            setSectionId(null)
          }}
        >
          {versionRows.map((row) => (
            <option key={row.class_version_id} value={row.class_version_id ?? ''}>
              {row.class_version}
            </option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-gray-700">{t['management.fields.shift']}</span>
        <select
          className={selectClass}
          value={effectiveShiftId ?? ''}
          onChange={(event) => {
            setShiftId(Number(event.target.value))
            setSectionId(null)
          }}
        >
          {shiftRows.map((row) => (
            <option key={row.shift_id} value={row.shift_id ?? ''}>
              {row.shift}
            </option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-gray-700">{t['management.fields.section']}</span>
        <select className={selectClass} value={effectiveSectionId ?? ''} onChange={(event) => setSectionId(Number(event.target.value))}>
          {sectionRows.map((row) => (
            <option key={row.id} value={row.id}>
              {row.name}
            </option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-gray-700">{t['teacher.attendance.month']}</span>
        <select className={selectClass} value={month} onChange={(event) => setMonth(Number(event.target.value))}>
          {MONTH_NAMES.map((name, index) => (
            <option key={name} value={index + 1}>
              {name}
            </option>
          ))}
        </select>
      </label>
    </div>
  )

  return { filterBar, loading, hasSections: sections.length > 0, section: selectedSection, year, month }
}

// A history of this section's already-recorded days, each with quick
// View (read-only) / Edit (reuses RecordAttendanceRequest via the same
// modal, only shown when this section's own class teacher) actions —
// built from the monthly report's own data rather than a separate
// endpoint, since the report already has every date × student status for
// the month.
function AttendanceListPage() {
  const { token } = useAuth()
  const { filterBar, loading: loadingFilters, hasSections, section, year, month } = useSectionCascadeFilter()

  const [loading, setLoading] = useState(true)
  const [report, setReport] = useState<AttendanceReport | null>(null)
  const [modal, setModal] = useState<{ date: string; mode: 'view' | 'edit' } | null>(null)

  useEffect(() => {
    if (!token || !section) return
    let active = true
    setLoading(true)

    getAttendanceReport({ sectionId: section.id, year, month }, token)
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
  }, [token, section?.id, year, month])

  const rows = (report?.dates ?? [])
    .map((dateInfo) => {
      const counts: Record<AttendanceStatus, number> = { present: 0, absent: 0, leave: 0 }

      for (const student of report?.students ?? []) {
        const status = student.statuses[dateInfo.date]
        if (status) counts[status] += 1
      }

      return { date: dateInfo.date, counts, recorded: counts.present + counts.absent + counts.leave }
    })
    .filter((row) => row.recorded > 0)
    .reverse()

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.attendance.title']}</h1>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loadingFilters && !hasSections && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.noSections']}</p>}

      {!loadingFilters && hasSections && (
        <>
          <div className="mt-4">{filterBar}</div>

          {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

          {!loading && rows.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.listEmpty']}</p>}

          {!loading && rows.length > 0 && (
            <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
              <table className="w-full min-w-[32rem] text-sm">
                <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                  <tr>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.date']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colPresentCount']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colAbsentCount']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.attendance.colLeaveCount']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.actions']}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.date} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{row.date}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-success-600">{row.counts.present}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-danger-600">{row.counts.absent}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-amber-700">{row.counts.leave}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="flex gap-2">
                          <button
                            type="button"
                            onClick={() => setModal({ date: row.date, mode: 'view' })}
                            className="cursor-pointer rounded-md border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition-colors hover:border-primary-300"
                          >
                            {t['management.table.view']}
                          </button>
                          {section?.can_record && (
                            <button
                              type="button"
                              onClick={() => setModal({ date: row.date, mode: 'edit' })}
                              className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-400"
                            >
                              {t['management.table.edit']}
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {modal && section && (
            <RosterModal sectionId={section.id} date={modal.date} mode={modal.mode} canRecord={section.can_record} onClose={() => setModal(null)} />
          )}
        </>
      )}
    </section>
  )
}

function AttendanceReportPage() {
  const { token } = useAuth()
  const { filterBar, loading: loadingFilters, hasSections, section, year, month } = useSectionCascadeFilter()

  const [loading, setLoading] = useState(true)
  const [report, setReport] = useState<AttendanceReport | null>(null)

  useEffect(() => {
    if (!token || !section) return
    let active = true
    setLoading(true)

    getAttendanceReport({ sectionId: section.id, year, month }, token)
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
  }, [token, section?.id, year, month])

  return (
    <section className="px-4 py-6 lg:px-6 print:p-0">
      <h1 className="text-2xl font-bold text-primary-700 print:hidden">{t['teacher.attendance.title']}</h1>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loadingFilters && !hasSections && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.noSections']}</p>}

      {!loadingFilters && hasSections && (
        <>
          <div className="mt-4 print:hidden">{filterBar}</div>

          {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

          {!loading && report && report.students.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.empty']}</p>}

          {!loading && report && report.students.length > 0 && (
            <div className="mt-4 rounded-md bg-white p-5 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
              <div className="flex items-start justify-between gap-4 print:hidden">
                <div />
                <button
                  type="button"
                  onClick={() => window.print()}
                  className="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-primary-300"
                >
                  <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
                  {t['teacher.attendance.print']}
                </button>
              </div>

              <div className="mb-4 text-center text-sm text-gray-700">
                <p className="text-base font-semibold text-gray-900">{report.section.school_name}</p>
                {report.section.school_address && <p className="text-xs text-gray-500">{report.section.school_address}</p>}
                <p className="mt-1">
                  {[
                    report.section.academic_year,
                    report.section.class_name,
                    report.section.class_version,
                    report.section.shift,
                    report.section.name,
                  ]
                    .filter(Boolean)
                    .join('.')}
                </p>
                {report.section.class_teacher && (
                  <p className="text-xs text-gray-500">
                    {t['teacher.attendance.teacherLabel']}: {report.section.class_teacher}
                  </p>
                )}
                <p className="mt-1 text-xs font-medium text-gray-600">
                  {MONTH_NAMES[month - 1]} {year}
                </p>
              </div>

              {/* max-h + overflow-y-auto fixes the grid's height regardless
                  of how many students are in the section — the header row
                  stays `sticky top-0` the same way the first two columns
                  already stay `sticky left-*`, so both the day you're
                  looking at and the student it belongs to are always
                  visible no matter which direction you've scrolled. Every
                  sticky cell carries its own explicit background (not
                  inherited from `<thead>`) since a sticky cell paints
                  independently of its row/section once it's detached from
                  normal flow — without that, scrolled-past content would
                  show through it. */}
              <div className="max-h-[28rem] overflow-x-auto overflow-y-auto rounded-md border border-gray-100">
                <table className="w-full text-xs">
                  <thead className="font-medium tracking-wide text-gray-500 uppercase">
                    <tr>
                      <th className="sticky top-0 left-0 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                        {t['teacher.attendance.colRoll']}
                      </th>
                      <th className="sticky top-0 left-14 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                        {t['teacher.attendance.colStudent']}
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
        </>
      )}
    </section>
  )
}

// Take Attendance keeps its own simple flat section dropdown (no
// Academic Year/Class/Version/Shift cascade, no Month) — a teacher only
// ever records today-or-a-recent-date for one of their handful of
// sections, so narrowing by year/class the way List/Report need to isn't
// useful here.
function TakeAttendancePage() {
  const { token } = useAuth()

  const [loadingSections, setLoadingSections] = useState(true)
  const [sections, setSections] = useState<AttendanceSection[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

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

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.attendance.title']}</h1>

      {loadingSections && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.loading']}</p>}

      {!loadingSections && sections.length === 0 && <p className="mt-6 text-sm text-gray-500">{t['teacher.attendance.noSections']}</p>}

      {!loadingSections && sectionId !== null && sections.length > 0 && (
        <TakeAttendanceTab sections={sections} sectionId={sectionId} onSectionChange={setSectionId} />
      )}
    </section>
  )
}

// Which sub-page to render — driven by the route (see App.tsx's three
// /attendance/take, /attendance/list, /attendance/report routes and the
// sidebar's own Attendance submenu), not an in-page toggle, so the left
// sidebar is the single source of truth for which view is showing.
export default function AttendancePage({ tab }: { tab: 'take' | 'list' | 'report' }) {
  if (tab === 'list') return <AttendanceListPage />
  if (tab === 'report') return <AttendanceReportPage />
  return <TakeAttendancePage />
}
