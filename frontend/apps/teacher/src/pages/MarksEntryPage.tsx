import { useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  getAcademicExamMarkRoster,
  listAcademicExamTerms,
  listAcademicYears,
  listMyAcademicExamMarkAssignments,
  recordAcademicExamMarks,
  type AcademicExamMarkAssignment,
  type AcademicExamMarkRoster,
  type AcademicExamTerm,
  type AcademicYear,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

/**
 * First row carrying each distinct, non-null key — same technique
 * AttendancePage uses to turn a flat assignment list into cascading
 * filter options without a dedicated endpoint per level.
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

type PendingMarks = Record<number, { cq: string; mcq: string; practical: string }>

function toNullableInt(value: string): number | null {
  return value.trim() === '' ? null : Number(value)
}

export default function MarksEntryPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<AcademicExamMarkAssignment[]>([])
  const [years, setYears] = useState<AcademicYear[]>([])
  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)

  const [loadingRoster, setLoadingRoster] = useState(false)
  const [roster, setRoster] = useState<AcademicExamMarkRoster | null>(null)
  const [pending, setPending] = useState<PendingMarks>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!token) return

    Promise.all([listMyAcademicExamMarkAssignments(token), listAcademicYears(), listAcademicExamTerms()])
      .then(([assignmentResult, yearResult, termResult]) => {
        setAssignments(assignmentResult.data)
        setYears(yearResult.data)
        setTerms(termResult.data)
      })
      .catch(() => {
        setAssignments([])
        setYears([])
        setTerms([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])
  const subjectsForSection = useMemo(() => assignments.filter((row) => row.section_id === sectionId), [assignments, sectionId])

  useEffect(() => {
    if (!token || sectionId === null || subjectId === null || academicYearId === null || academicExamTermId === null) {
      setRoster(null)
      return
    }

    let active = true
    setLoadingRoster(true)

    getAcademicExamMarkRoster({ sectionId, subjectId, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (!active) return
        setRoster(result.data)
        setPending(
          Object.fromEntries(
            result.data.students.map((row) => [
              row.student_profile_id,
              {
                cq: row.cq_marks_obtained === null ? '' : String(row.cq_marks_obtained),
                mcq: row.mcq_marks_obtained === null ? '' : String(row.mcq_marks_obtained),
                practical: row.practical_marks_obtained === null ? '' : String(row.practical_marks_obtained),
              },
            ]),
          ),
        )
      })
      .catch(() => {
        if (active) setRoster(null)
      })
      .finally(() => {
        if (active) setLoadingRoster(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, subjectId, academicExamTermId, academicYearId])

  async function handleSave() {
    if (!token || !roster || sectionId === null || subjectId === null || academicYearId === null || academicExamTermId === null) return

    const records = Object.entries(pending).map(([studentProfileId, marks]) => ({
      student_profile_id: Number(studentProfileId),
      cq_marks_obtained: toNullableInt(marks.cq),
      mcq_marks_obtained: toNullableInt(marks.mcq),
      practical_marks_obtained: toNullableInt(marks.practical),
    }))

    setSaving(true)

    try {
      const result = await recordAcademicExamMarks(
        { section_id: sectionId, subject_id: subjectId, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId, records },
        token,
      )
      setRoster(result.data)
      showToast({ type: 'success', message: t['teacher.marksEntry.saveSuccess'] })
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.marksEntry.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  function updateMark(studentProfileId: number, component: 'cq' | 'mcq' | 'practical', value: string) {
    setPending((previous) => ({
      ...previous,
      [studentProfileId]: { ...(previous[studentProfileId] ?? { cq: '', mcq: '', practical: '' }), [component]: value },
    }))
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-gray-900">{t['teacher.nav.marksEntry']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={sectionId ?? ''}
          onChange={(event) => {
            setSectionId(event.target.value ? Number(event.target.value) : null)
            setSubjectId(null)
          }}
        >
          <option value="">{t['teacher.marksEntry.selectSection']}</option>
          {sections.map((row) => (
            <option key={row.section_id} value={row.section_id}>
              {[row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={sectionId === null}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectSubject']}</option>
          {subjectsForSection.map((row) => (
            <option key={row.subject_id} value={row.subject_id}>
              {row.subject_name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicExamTermId ?? ''}
          onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectTerm']}</option>
          {terms.map((term) => (
            <option key={term.id} value={term.id}>
              {term.bn_name ?? term.name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicYearId ?? ''}
          onChange={(event) => setAcademicYearId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectYear']}</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
            </option>
          ))}
        </select>
      </div>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.loading']}</p>}

      {!loadingFilters && (sectionId === null || subjectId === null || academicExamTermId === null || academicYearId === null) && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.pickAllFilters']}</p>
      )}

      {loadingRoster && <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.loading']}</p>}

      {!loadingRoster && roster && roster.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.empty']}</p>
      )}

      {!loadingRoster && roster && roster.students.length > 0 && (
        <>
          {!roster.can_record && <p className="mt-4 text-sm text-gray-500">{t['teacher.marksEntry.viewOnlyNotice']}</p>}

          <div className="mt-4 max-h-[26rem] overflow-y-auto overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[40rem] text-sm">
              <thead className="sticky top-0 z-10 bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.marksEntry.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.marksEntry.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colCq']} {roster.cq_full_marks !== null ? `(/${roster.cq_full_marks})` : ''}
                  </th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colMcq']} {roster.mcq_full_marks !== null ? `(/${roster.mcq_full_marks})` : ''}
                  </th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colPractical']} {roster.practical_full_marks !== null ? `(/${roster.practical_full_marks})` : ''}
                  </th>
                </tr>
              </thead>
              <tbody>
                {roster.students.map((row) => {
                  const marks = pending[row.student_profile_id] ?? { cq: '', mcq: '', practical: '' }

                  return (
                    <tr key={row.student_profile_id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.cq_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.cq_full_marks === null}
                          value={marks.cq}
                          onChange={(event) => updateMark(row.student_profile_id, 'cq', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.mcq_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.mcq_full_marks === null}
                          value={marks.mcq}
                          onChange={(event) => updateMark(row.student_profile_id, 'mcq', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.practical_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.practical_full_marks === null}
                          value={marks.practical}
                          onChange={(event) => updateMark(row.student_profile_id, 'practical', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {roster.can_record && (
            <div className="mt-4">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving ? t['teacher.marksEntry.saving'] : t['teacher.marksEntry.save']}
              </button>
            </div>
          )}
        </>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}
