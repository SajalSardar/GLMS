import { useEffect, useState } from 'react'
import {
  getMyAcademicExamResult,
  listMyAcademicExamResultTerms,
  type AcademicExamMarksheet,
  type MyAcademicExamResultTerm,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useActiveStudentProfile } from '../../lib/useActiveStudentProfile'
import { useLocale } from '../../lib/locale'

// List-then-detail, same single-page pattern used elsewhere in this app
// rather than a second route — selecting a term swaps the list for its
// marksheet in place, with a Back link to return.
export default function ResultsPage() {
  const { token } = useAuth()
  const { studentProfileId, isGuardian } = useActiveStudentProfile()
  const { t } = useLocale()

  const [loadingTerms, setLoadingTerms] = useState(true)
  const [terms, setTerms] = useState<MyAcademicExamResultTerm[]>([])

  const [selectedTerm, setSelectedTerm] = useState<MyAcademicExamResultTerm | null>(null)
  const [loadingMarksheet, setLoadingMarksheet] = useState(false)
  const [marksheet, setMarksheet] = useState<AcademicExamMarksheet | null>(null)

  useEffect(() => {
    if (!token || (isGuardian && !studentProfileId)) return
    let active = true
    setLoadingTerms(true)

    listMyAcademicExamResultTerms(token, studentProfileId ?? undefined)
      .then((result) => {
        if (active) setTerms(result.data)
      })
      .catch(() => {
        if (active) setTerms([])
      })
      .finally(() => {
        if (active) setLoadingTerms(false)
      })

    return () => {
      active = false
    }
  }, [token, studentProfileId, isGuardian])

  useEffect(() => {
    if (!token || !selectedTerm || (isGuardian && !studentProfileId)) {
      setMarksheet(null)
      return
    }

    let active = true
    setLoadingMarksheet(true)

    getMyAcademicExamResult(
      {
        academicExamTermId: selectedTerm.academic_exam_term_id,
        academicYearId: selectedTerm.academic_year_id,
        studentProfileId: studentProfileId ?? undefined,
      },
      token,
    )
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
  }, [token, selectedTerm, studentProfileId, isGuardian])

  return (
    <section className="px-4 py-6 lg:px-6">
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-2xl font-bold text-primary-800 print:hidden">{t['student.results.title']}</h1>

      {!selectedTerm && (
        <div className="mt-6 print:hidden">
          {loadingTerms && <p className="text-sm text-primary-600">{t['student.results.loading']}</p>}

          {!loadingTerms && terms.length === 0 && <p className="text-sm text-primary-600">{t['student.results.empty']}</p>}

          {!loadingTerms && terms.length > 0 && (
            <ul className="flex flex-col gap-3">
              {terms.map((term) => (
                <li key={`${term.academic_exam_term_id}-${term.academic_year_id}`}>
                  <button
                    type="button"
                    onClick={() => setSelectedTerm(term)}
                    className="flex w-full cursor-pointer items-center justify-between rounded-md bg-white p-4 text-left shadow-sm transition-colors hover:border-primary-300"
                  >
                    <span className="font-semibold text-primary-800">{term.term_name}</span>
                    <span className="material-symbols-outlined text-primary-400">chevron_right</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {selectedTerm && (
        <>
          <div className="mt-4 flex items-center justify-between print:hidden">
            <button type="button" onClick={() => setSelectedTerm(null)} className="text-sm font-semibold text-primary-700 underline">
              ← {t['student.results.back']}
            </button>

            {marksheet && (
              <button
                type="button"
                onClick={() => window.print()}
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
              >
                {t['student.results.print']}
              </button>
            )}
          </div>

          {loadingMarksheet && <p className="mt-6 text-sm text-primary-600 print:hidden">{t['student.results.loading']}</p>}

          {!loadingMarksheet && marksheet && marksheet.subjects.length === 0 && (
            <p className="mt-6 text-sm text-primary-600 print:hidden">{t['student.results.noMarksRecorded']}</p>
          )}

          {!loadingMarksheet && marksheet && marksheet.subjects.length > 0 && (
            <div className="mt-4 rounded-md bg-white p-6 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
              <div className="overflow-x-auto rounded-md border border-gray-100">
                <table className="w-full min-w-[32rem] text-sm">
                  <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                    <tr>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colSubject']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colCq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colMcq']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colPractical']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colTotal']}</th>
                      <th className="px-4 py-3 text-left whitespace-nowrap">{t['student.results.colGrade']}</th>
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
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.gpa']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">{marksheet.gpa.toFixed(2)}</p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.result']}</p>
                  <p className={`mt-1 text-lg font-bold ${marksheet.result === 'pass' ? 'text-success-600' : 'text-danger-600'}`}>
                    {marksheet.result === 'pass' ? t['student.results.resultPass'] : t['student.results.resultFail']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.sectionPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.section_position !== null
                      ? `${marksheet.section_position} / ${marksheet.section_total_students}`
                      : t['student.results.positionNotAvailable']}
                  </p>
                </div>
                <div className="rounded-md border border-gray-100 p-3">
                  <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">{t['student.results.classPosition']}</p>
                  <p className="mt-1 text-lg font-bold text-gray-900">
                    {marksheet.class_position !== null
                      ? `${marksheet.class_position} / ${marksheet.class_total_students}`
                      : t['student.results.positionNotAvailable']}
                  </p>
                </div>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  )
}
