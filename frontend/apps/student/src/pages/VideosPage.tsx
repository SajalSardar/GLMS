import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ApiError,
  listClasses,
  listSchools,
  listSubjects,
  type Book,
  type School,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import { chaptersWithVideosForClassAndSubject, type ChapterWithVideoCount } from '../lib/resolveContent'

const t = getCommonStrings('bn')

// Same pattern as BooksPage.tsx — see the comment there for why a
// translated `fallback` is used instead of the raw `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

export default function VideosPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classId, setClassId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [entries, setEntries] = useState<ChapterWithVideoCount[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    // Same reason as BooksPage: ClassVersion carries only a bare
    // `school_id`, no nested `school` relation, so school names for the
    // per-school chapter labels below are resolved by fetching the school
    // list once and mapping by id.
    listSchools({ perPage: 500 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)

    chaptersWithVideosForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setEntries(result)
      })
      .catch((caught) => {
        if (active) {
          setEntries([])
          setError(messageFromApiError(caught, t['student.videos.loadError']))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  function schoolNameFor(book: Book): string {
    const schoolId = book.class_version?.school_id
    if (!schoolId) return ''
    return schools.find((school) => school.id === schoolId)?.name ?? ''
  }

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.videos.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.videos.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={subjectId ?? ''}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.videos.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.name}
            </option>
          ))}
        </select>
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.videos.loading']}</p>}

      {!loading && error && <p className="mt-6 text-sm text-danger-600">{error}</p>}

      {!loading && !error && !classId && (
        <p className="mt-6 text-sm text-primary-600">{t['student.videos.pickClass']}</p>
      )}

      {!loading && !error && classId && entries.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.videos.empty']}</p>
      )}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {entries.map(({ chapter, book }) => (
          <Link
            key={chapter.id}
            to={`/videos/${chapter.id}`}
            className="rounded-lg border border-primary-100 p-4 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">
              {chapter.bn_chapter_number ?? chapter.chapter_number ?? ''} {chapter.bn_name ?? chapter.name}
            </p>
            <p className="mt-1 text-sm text-primary-600">{book.bn_name ?? book.name}</p>
            <p className="mt-1 text-xs text-primary-500">{schoolNameFor(book)}</p>
          </Link>
        ))}
      </div>
    </section>
  )
}
