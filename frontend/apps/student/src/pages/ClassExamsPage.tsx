import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { ownStudentDashboard } from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

interface ExamRow {
  examId: number
  examName: string
  subjectName: string
}

function InfoAlert({ message }: { message: string }) {
  return (
    <div className="mt-6 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700">
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
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v5" />
        <circle cx="12" cy="8" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

export default function ClassExamsPage() {
  const { t } = useLocale()
  const { token, userId } = useAuth()
  const navigate = useNavigate()

  const [loading, setLoading] = useState(true)
  const [noClass, setNoClass] = useState(false)
  const [exams, setExams] = useState<ExamRow[]>([])

  useEffect(() => {
    if (!token || !userId) return

    let active = true
    setLoading(true)

    ownStudentDashboard(userId, token)
      .then((dashboard) => {
        if (!active) return

        if (!dashboard || dashboard.class_version_id === null) {
          setNoClass(true)
          setExams([])
          return
        }

        // "Untaken" means never attempted at all — attempts_used is 0.
        // StudentDashboardBuilder already scoped this list to the
        // student's own class_version_id/academic_year_id and excluded
        // practice exams, so no further filtering is needed here.
        const rows = dashboard.subjects.flatMap((subject) =>
          subject.exams
            .filter((exam) => exam.attempts_used === 0)
            .map((exam) => ({ examId: exam.exam_id, examName: exam.exam_name, subjectName: subject.subject_name })),
        )
        setExams(rows)
      })
      .catch(() => {
        if (active) setExams([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, userId])

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.classExams.title']}</h1>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.classExams.loading']}</p>}

      {!loading && noClass && <InfoAlert message={t['student.classExams.noClass']} />}

      {!loading && !noClass && exams.length === 0 && <InfoAlert message={t['student.classExams.empty']} />}

      {!loading && !noClass && exams.length > 0 && (
        <div className="mt-6 overflow-x-auto rounded-lg border border-gray-100">
          <table className="w-full min-w-[32rem] text-left text-sm">
            <thead>
              <tr>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classExams.colExam']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classExams.colSubject']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classExams.colAction']}
                </th>
              </tr>
            </thead>
            <tbody>
              {exams.map((exam) => (
                <tr key={exam.examId}>
                  <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
                    <span className="font-medium text-primary-900">{exam.examName}</span>
                  </td>
                  <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                    {exam.subjectName}
                  </td>
                  <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
                    <button
                      type="button"
                      onClick={() => navigate(`/exams/${exam.examId}`)}
                      className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#EB1F27]"
                    >
                      {t['student.classExams.startExam']}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
