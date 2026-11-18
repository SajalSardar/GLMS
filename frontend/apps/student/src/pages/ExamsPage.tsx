import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ApiError,
  listClassVersions,
  listExams,
  listSubjects,
  type ClassVersion,
  type Exam,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

// Same pattern as BooksPage.tsx/VideosPage.tsx — see the comment there for
// why a translated `fallback` is used instead of the raw `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

export default function ExamsPage() {
  const [exams, setExams] = useState<Exam[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)

    // ExamController::index() already degrades to published+available-only
    // for an anonymous visitor (confirmed by reading
    // backend/app/Http/Controllers/Api/V1/ExamController.php), so no
    // client-side filtering is needed here.
    listExams()
      .then((result) => {
        if (active) setExams(result.data)
      })
      .catch((caught) => {
        if (active) {
          setExams([])
          setError(messageFromApiError(caught, t['student.exams.loadError']))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    // Exam only carries bare `subject_id`/`class_version_id` (confirmed
    // against the Exam interface in api-client/src/index.ts), so names are
    // resolved by fetching the subject/class-version lists once and
    // mapping by id — same pattern BooksPage uses to resolve school names.
    listSubjects({ perPage: 200 })
      .then((result) => {
        if (active) setSubjects(result.data)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    listClassVersions({ perPage: 500 })
      .then((result) => {
        if (active) setClassVersions(result.data)
      })
      .catch(() => {
        if (active) setClassVersions([])
      })

    return () => {
      active = false
    }
  }, [])

  function subjectNameFor(exam: Exam): string {
    return subjects.find((subject) => subject.id === exam.subject_id)?.name ?? ''
  }

  function classVersionNameFor(exam: Exam): string {
    return classVersions.find((classVersion) => classVersion.id === exam.class_version_id)?.name ?? ''
  }

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.exams.title']}</h1>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.exams.loading']}</p>}

      {!loading && error && <p className="mt-6 text-sm text-danger-600">{error}</p>}

      {!loading && !error && exams.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.exams.empty']}</p>
      )}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {exams.map((exam) => (
          <Link
            key={exam.id}
            to={`/exams/${exam.id}`}
            className="rounded-lg border border-primary-100 p-4 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">{exam.bn_name ?? exam.name}</p>
            <p className="mt-1 text-sm text-primary-600">{subjectNameFor(exam)}</p>
            <p className="mt-1 text-xs text-primary-500">{classVersionNameFor(exam)}</p>
          </Link>
        ))}
      </div>
    </section>
  )
}
