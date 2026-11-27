import { Fragment, useEffect, useState } from 'react'
import {
  ApiError,
  getAcademicExamSectionMarksheet,
  listAcademicExamTerms,
  listAcademicYears,
  listSchools,
  listSections,
  publishAcademicExamResults,
  unpublishAcademicExamResults,
  type AcademicExamSectionGrid,
  type AcademicExamTerm,
  type AcademicYear,
  type School,
  type Section,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function sectionLabel(section: Section): string {
  return [section.shift?.class_version?.class?.name, section.shift?.class_version?.name, section.shift?.name, section.name]
    .filter(Boolean)
    .join(' - ')
}

function markCell(obtained: number | null, full: number | null): string {
  if (full === null) return '—'
  return obtained === null ? '—' : String(obtained)
}

// Report/action-only — recording marks itself stays in the teacher
// portal (see AcademicExamMarkPolicy); this page only reads a section's
// full tabulation sheet and fires the publish action, same split as
// AttendanceReportPage's own relationship to Take Attendance.
//
// Two-step flow: a default list of every section (current academic year)
// with a View action, then a per-section detail screen — exam term tabs
// over the whole class's marks grid, with Publish/Unpublish at the
// bottom. Picking a row out of a list rather than requiring every filter
// to be filled in first (the old single-screen design) is what makes
// "see which classes are ready to publish" a glanceable default view.
export default function PublishResultsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [years, setYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionsLoading, setSectionsLoading] = useState(false)

  const [terms, setTerms] = useState<AcademicExamTerm[]>([])

  const [selectedSection, setSelectedSection] = useState<Section | null>(null)
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)

  const [grid, setGrid] = useState<AcademicExamSectionGrid | null>(null)
  const [gridLoading, setGridLoading] = useState(false)
  const [publishing, setPublishing] = useState(false)

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listAcademicExamTerms()
      .then((result) => {
        setTerms(result.data)
        setAcademicExamTermId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setTerms([]))

    listAcademicYears({ perPage: 200 })
      .then((result) => {
        setYears(result.data)
        setAcademicYearId((previous) => previous ?? result.data.find((year) => year.is_current)?.id ?? null)
      })
      .catch(() => setYears([]))
  }, [])

  useEffect(() => {
    if (!schoolId) {
      setSections([])
      return
    }

    let active = true
    setSectionsLoading(true)

    listSections({ schoolId, perPage: 200 })
      .then((result) => {
        if (active) setSections(result.data)
      })
      .catch(() => {
        if (active) setSections([])
      })
      .finally(() => {
        if (active) setSectionsLoading(false)
      })

    return () => {
      active = false
    }
  }, [schoolId])

  const scopedSections = academicYearId === null ? sections : sections.filter((section) => section.shift?.academic_year_id === academicYearId)

  function backToList() {
    setSelectedSection(null)
    setGrid(null)
  }

  useEffect(() => {
    if (!token || !selectedSection || academicExamTermId === null || academicYearId === null) {
      setGrid(null)
      return
    }

    let active = true
    setGridLoading(true)

    getAcademicExamSectionMarksheet({ sectionId: selectedSection.id, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (active) setGrid(result.data)
      })
      .catch(() => {
        if (active) setGrid(null)
      })
      .finally(() => {
        if (active) setGridLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, selectedSection, academicExamTermId, academicYearId])

  async function handlePublish() {
    if (!token || !selectedSection || academicExamTermId === null || academicYearId === null) return

    setPublishing(true)

    try {
      await publishAcademicExamResults({ section_id: selectedSection.id, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId }, token)
      showToast({ type: 'success', message: t['management.publishResults.publishSuccess'] })
      setGrid((previous) => (previous ? { ...previous, is_published: true } : previous))
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.publishResults.publishError'] })
    } finally {
      setPublishing(false)
    }
  }

  async function handleUnpublish() {
    if (!token || !selectedSection || academicExamTermId === null || academicYearId === null) return
    if (!window.confirm(t['management.publishResults.unpublishConfirm'])) return

    setPublishing(true)

    try {
      await unpublishAcademicExamResults({ section_id: selectedSection.id, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId }, token)
      showToast({ type: 'success', message: t['management.publishResults.unpublishSuccess'] })
      setGrid((previous) => (previous ? { ...previous, is_published: false } : previous))
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.publishResults.unpublishError'] })
    } finally {
      setPublishing(false)
    }
  }

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.publishResults.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => {
              setSchoolId(Number(value))
              backToList()
            }}
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
            value={academicYearId ?? ''}
            onChange={(event) => {
              setAcademicYearId(event.target.value ? Number(event.target.value) : null)
              backToList()
            }}
          >
            <option value="">—</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {!selectedSection && (
        <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[28rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.publishResults.colClass']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.actions']}</th>
              </tr>
            </thead>
            <tbody>
              {sectionsLoading ? (
                <tr>
                  <td colSpan={2} className="px-5 py-6 text-center text-gray-500">
                    {t['management.table.loading']}
                  </td>
                </tr>
              ) : scopedSections.length === 0 ? (
                <tr>
                  <td colSpan={2} className="px-5 py-6 text-center text-gray-500">
                    {t['management.publishResults.noSections']}
                  </td>
                </tr>
              ) : (
                scopedSections.map((section) => (
                  <tr key={section.id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{sectionLabel(section)}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <button
                        type="button"
                        className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                        onClick={() => setSelectedSection(section)}
                        aria-label={t['management.table.view']}
                      >
                        <span className="material-symbols-outlined !text-md">visibility</span>
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      {selectedSection && (
        <div className="mt-4">
          <button type="button" onClick={backToList} className={`${buttonNeutral} inline-flex items-center gap-1.5`}>
            <span className="material-symbols-outlined !text-md">arrow_back</span>
            {t['management.publishResults.backToList']}
          </button>

          <h2 className="mt-3 text-lg font-semibold text-gray-900">{sectionLabel(selectedSection)}</h2>

          <div className="mt-3 flex flex-wrap gap-2">
            {terms.map((term) => (
              <button
                key={term.id}
                type="button"
                onClick={() => setAcademicExamTermId(term.id)}
                className={`cursor-pointer rounded-md border px-4 py-2 text-sm font-medium transition-colors ${
                  academicExamTermId === term.id
                    ? 'border-primary-500 bg-primary-500 text-white'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'
                }`}
              >
                {term.bn_name ?? term.name}
              </button>
            ))}
          </div>

          {gridLoading && <p className="mt-6 text-sm text-gray-500">{t['management.publishResults.loading']}</p>}

          {!gridLoading && grid && (
            <>
              <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
                <table className="w-full min-w-[40rem] text-sm">
                  <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                    <tr>
                      <th rowSpan={2} className="px-4 py-3 text-left whitespace-nowrap align-bottom">
                        {t['management.publishResults.colRoll']}
                      </th>
                      <th rowSpan={2} className="px-4 py-3 text-left whitespace-nowrap align-bottom">
                        {t['management.publishResults.colStudent']}
                      </th>
                      {grid.subjects.map((subject) => (
                        <th key={subject.subject_id} colSpan={3} className="border-l border-gray-100 px-4 py-2 text-center whitespace-nowrap">
                          {subject.subject_name}
                        </th>
                      ))}
                      <th rowSpan={2} className="border-l border-gray-100 px-4 py-3 text-right whitespace-nowrap align-bottom">
                        {t['management.publishResults.colTotal']}
                      </th>
                    </tr>
                    <tr>
                      {grid.subjects.map((subject) => (
                        <Fragment key={subject.subject_id}>
                          <th className="border-l border-gray-100 px-3 py-1.5 text-center whitespace-nowrap">CQ</th>
                          <th className="px-3 py-1.5 text-center whitespace-nowrap">MCQ</th>
                          <th className="px-3 py-1.5 text-center whitespace-nowrap">Practical</th>
                        </Fragment>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {grid.students.length === 0 ? (
                      <tr>
                        <td colSpan={2 + grid.subjects.length * 3 + 1} className="px-5 py-6 text-center text-gray-500">
                          {t['management.publishResults.noStudents']}
                        </td>
                      </tr>
                    ) : (
                      grid.students.map((student) => (
                        <tr key={student.student_profile_id} className="border-t border-gray-100">
                          <td className="px-4 py-3 whitespace-nowrap text-gray-500">{student.roll_number ?? '—'}</td>
                          <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{student.name ?? '—'}</td>
                          {grid.subjects.map((subject) => {
                            const cell = student.subjects[subject.subject_id]

                            return (
                              <Fragment key={subject.subject_id}>
                                <td className="border-l border-gray-100 px-3 py-3 text-center text-gray-700">
                                  {markCell(cell?.cq ?? null, cell?.cq_full ?? null)}
                                </td>
                                <td className="px-3 py-3 text-center text-gray-700">{markCell(cell?.mcq ?? null, cell?.mcq_full ?? null)}</td>
                                <td className="px-3 py-3 text-center text-gray-700">
                                  {markCell(cell?.practical ?? null, cell?.practical_full ?? null)}
                                </td>
                              </Fragment>
                            )
                          })}
                          <td className="border-l border-gray-100 px-4 py-3 text-right font-semibold whitespace-nowrap text-gray-900">
                            {student.total_obtained} / {student.total_full}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <div className="mt-4">
                {grid.is_published ? (
                  <div className="flex items-center gap-3">
                    <span className="rounded-full bg-success-50 px-3 py-1.5 text-sm font-medium text-success-600">
                      {t['management.publishResults.alreadyPublished']}
                    </span>
                    <button type="button" onClick={handleUnpublish} disabled={publishing} className={buttonNeutral}>
                      {t['management.publishResults.unpublish']}
                    </button>
                  </div>
                ) : (
                  <button type="button" onClick={handlePublish} disabled={publishing} className={buttonPrimary}>
                    {publishing ? t['management.publishResults.publishing'] : t['management.publishResults.publish']}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
