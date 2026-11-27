import { useEffect, useMemo, useState } from 'react'
import {
  getAcademicExamMarksheet,
  getAcademicExamSectionStudents,
  listAcademicExamTerms,
  listAcademicYears,
  listMyAcademicExamMarkAssignments,
  type AcademicExamMarkAssignment,
  type AcademicExamMarksheet,
  type AcademicExamSectionStudent,
  type AcademicExamTerm,
  type AcademicYear,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

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

// Two-step flow, matching the management portal's Publish Results screen:
// picking a section (plus exam term/academic year) shows that section's
// student roster with a View action, and clicking it shows one student's
// marksheet — rather than the old single-screen design that required a
// student picked from a second dropdown before anything rendered.
export default function MarksheetsPage() {
  const { token } = useAuth()

  const [assignments, setAssignments] = useState<AcademicExamMarkAssignment[]>([])
  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [years, setYears] = useState<AcademicYear[]>([])
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [students, setStudents] = useState<AcademicExamSectionStudent[]>([])
  const [studentsLoading, setStudentsLoading] = useState(false)

  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [selectedStudent, setSelectedStudent] = useState<AcademicExamSectionStudent | null>(null)
  const [loadingMarksheet, setLoadingMarksheet] = useState(false)
  const [marksheet, setMarksheet] = useState<AcademicExamMarksheet | null>(null)

  useEffect(() => {
    if (!token) return

    Promise.all([listMyAcademicExamMarkAssignments(token), listAcademicExamTerms(), listAcademicYears()])
      .then(([assignmentResult, termResult, yearResult]) => {
        setAssignments(assignmentResult.data)
        setTerms(termResult.data)
        setAcademicExamTermId((previous) => previous ?? termResult.data[0]?.id ?? null)
        setYears(yearResult.data)
        setAcademicYearId((previous) => previous ?? yearResult.data.find((year) => year.is_current)?.id ?? null)
      })
      .catch(() => {
        setAssignments([])
        setTerms([])
        setYears([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])

  useEffect(() => {
    if (!token || sectionId === null) {
      setStudents([])
      setSelectedStudent(null)
      return
    }

    let active = true
    setStudentsLoading(true)

    getAcademicExamSectionStudents({ sectionId }, token)
      .then((result) => {
        if (!active) return
        setStudents(result.data.students)
      })
      .catch(() => {
        if (active) setStudents([])
      })
      .finally(() => {
        if (active) setStudentsLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId])

  useEffect(() => {
    if (!token || !selectedStudent || academicExamTermId === null || academicYearId === null) {
      setMarksheet(null)
      return
    }

    let active = true
    setLoadingMarksheet(true)

    getAcademicExamMarksheet({ studentProfileId: selectedStudent.student_profile_id, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (active) setMarksheet(result.data)
      })
      .catch(() => {
        if (active) setMarksheet(null)
      })
      .finally(() => {
        if (active) setLoadingMarksheet(false)
      })

    return () => {
      active = false
    }
  }, [token, selectedStudent, academicExamTermId, academicYearId])

  function backToList() {
    setSelectedStudent(null)
    setMarksheet(null)
  }

  const canViewMarksheet = academicExamTermId !== null && academicYearId !== null

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-xl font-semibold text-gray-900 print:hidden">{t['teacher.nav.marksheets']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm print:hidden">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={sectionId ?? ''}
          onChange={(event) => setSectionId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksheets.selectSection']}</option>
          {sections.map((row) => (
            <option key={row.section_id} value={row.section_id}>
              {[row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicExamTermId ?? ''}
          onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksheets.selectTerm']}</option>
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
          <option value="">{t['teacher.marksheets.selectYear']}</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
            </option>
          ))}
        </select>

        {marksheet && (
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
            {t['teacher.marksheets.print']}
          </button>
        )}
      </div>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.loading']}</p>}

      {!loadingFilters && sectionId === null && (
        <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.selectSectionPrompt']}</p>
      )}

      {!loadingFilters && sectionId !== null && !selectedStudent && (
        <div className="mt-4 print:hidden">
          {!canViewMarksheet && <p className="mb-3 text-sm text-gray-500">{t['teacher.marksheets.pickTermYearHint']}</p>}

          <div className="overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[24rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colRoll']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colStudent']}</th>
                  <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colActions']}</th>
                </tr>
              </thead>
              <tbody>
                {studentsLoading ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-6 text-center text-gray-500">
                      {t['teacher.marksheets.loading']}
                    </td>
                  </tr>
                ) : students.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-4 py-6 text-center text-gray-500">
                      {t['teacher.marksheets.noStudents']}
                    </td>
                  </tr>
                ) : (
                  students.map((student) => (
                    <tr key={student.student_profile_id} className="border-t border-gray-100">
                      <td className="px-4 py-3 whitespace-nowrap text-gray-500">{student.roll_number ?? '—'}</td>
                      <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{student.name ?? '—'}</td>
                      <td className="px-4 py-3 whitespace-nowrap">
                        <button
                          type="button"
                          disabled={!canViewMarksheet}
                          className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500 disabled:cursor-not-allowed disabled:opacity-40"
                          onClick={() => setSelectedStudent(student)}
                          aria-label={t['teacher.marksheets.view']}
                        >
                          <span className="material-symbols-outlined !text-[20px]">visibility</span>
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {selectedStudent && (
        <div className="mt-4">
          <button
            type="button"
            onClick={backToList}
            className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-primary-300 print:hidden"
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
            {t['teacher.marksheets.backToList']}
          </button>

          <h2 className="mt-3 text-lg font-semibold text-gray-900 print:hidden">
            {selectedStudent.roll_number ? `${selectedStudent.roll_number} - ` : ''}
            {selectedStudent.name}
          </h2>

          {loadingMarksheet && <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.loading']}</p>}

          {!loadingMarksheet && marksheet && marksheet.subjects.length === 0 && (
            <p className="mt-6 text-sm text-gray-500 print:hidden">{t['teacher.marksheets.noMarksRecorded']}</p>
          )}

          {!loadingMarksheet && marksheet && marksheet.subjects.length > 0 && (
            <div className="mt-4 rounded-md bg-white p-6 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
              <div className="overflow-x-auto rounded-md border border-gray-100">
                <table className="w-full min-w-[32rem] text-sm">
                  <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                    <tr>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colSubject']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colCq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colMcq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colPractical']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colTotal']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['teacher.marksheets.colGrade']}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {marksheet.subjects.map((subject) => (
                      <tr key={subject.subject_id} className="border-t border-gray-100">
                        <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{subject.subject_name}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.cq ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.mcq ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.practical ?? '—'}</td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">
                          {subject.total_obtained} / {subject.total_full}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-600">{subject.grade_name ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.gpa']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">{marksheet.gpa.toFixed(2)}</p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.result']}</p>
                  <p className={`mt-1 text-lg font-bold ${marksheet.result === 'pass' ? 'text-success-600' : 'text-danger-600'}`}>
                    {marksheet.result === 'pass' ? t['teacher.marksheets.resultPass'] : t['teacher.marksheets.resultFail']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.sectionPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.section_position !== null
                      ? `${marksheet.section_position} / ${marksheet.section_total_students}`
                      : t['teacher.marksheets.positionNotAvailable']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['teacher.marksheets.classPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.class_position !== null
                      ? `${marksheet.class_position} / ${marksheet.class_total_students}`
                      : t['teacher.marksheets.positionNotAvailable']}
                  </p>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
