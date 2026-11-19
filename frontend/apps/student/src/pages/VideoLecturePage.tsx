import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ApiError,
  listClasses,
  listSchools,
  type Book,
  type School,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import {
  chaptersWithVideosForClassAndSubject,
  subjectsForClass,
  type ChapterWithVideoCount,
} from '../lib/resolveContent'

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

export default function VideoLecturePage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classId, setClassId] = useState<number | null>(null)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectsLoading, setSubjectsLoading] = useState(false)
  const [subjectsError, setSubjectsError] = useState<string | null>(null)

  const [selectedSubject, setSelectedSubject] = useState<Subject | null>(null)

  const [entries, setEntries] = useState<ChapterWithVideoCount[]>([])
  const [chaptersLoading, setChaptersLoading] = useState(false)
  const [chaptersError, setChaptersError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    // Same reason as BooksPage: ClassVersion carries only a bare
    // `school_id`, no nested `school` relation, so school names for the
    // per-school chapter labels below are resolved by fetching the school
    // list once and mapping by id.
    listSchools({ perPage: 500 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  // Picking a different class invalidates whichever subject was chosen
  // for the previous class (it may not even apply to the new one), so
  // drop back to the subject-card step rather than leaving a stale
  // chapter list showing underneath a new subject.
  useEffect(() => {
    setSelectedSubject(null)
    setEntries([])

    if (!classId) {
      setSubjects([])
      return
    }

    let active = true
    setSubjectsLoading(true)
    setSubjectsError(null)

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch((caught) => {
        if (active) {
          setSubjects([])
          setSubjectsError(messageFromApiError(caught, t['student.videoLecture.loadError']))
        }
      })
      .finally(() => {
        if (active) setSubjectsLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    if (!classId || !selectedSubject) {
      setEntries([])
      return
    }

    let active = true
    setChaptersLoading(true)
    setChaptersError(null)

    chaptersWithVideosForClassAndSubject(classId, selectedSubject.id)
      .then((result) => {
        if (active) setEntries(result)
      })
      .catch((caught) => {
        if (active) {
          setEntries([])
          setChaptersError(messageFromApiError(caught, t['student.videoLecture.loadError']))
        }
      })
      .finally(() => {
        if (active) setChaptersLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, selectedSubject])

  function schoolNameFor(book: Book): string {
    const schoolId = book.class_version?.school_id
    if (!schoolId) return ''
    return schools.find((school) => school.id === schoolId)?.name ?? ''
  }

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.videoLecture.title']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.videoLecture.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.name}
            </option>
          ))}
        </select>

        {selectedSubject && (
          <button
            type="button"
            onClick={() => setSelectedSubject(null)}
            className="cursor-pointer text-sm font-semibold text-primary-700 underline"
          >
            ← {selectedSubject.bn_name ?? selectedSubject.name}
          </button>
        )}
      </div>

      {!classId && <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.pickClass']}</p>}

      {classId && !selectedSubject && (
        <>
          {subjectsLoading && (
            <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.loading']}</p>
          )}
          {!subjectsLoading && subjectsError && (
            <p className="mt-6 text-sm text-danger-600">{subjectsError}</p>
          )}
          {!subjectsLoading && !subjectsError && subjects.length === 0 && (
            <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.noSubjects']}</p>
          )}

          <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {subjects.map((subject) => (
              <button
                key={subject.id}
                type="button"
                onClick={() => setSelectedSubject(subject)}
                className="cursor-pointer rounded-lg border border-primary-100 p-5 text-center shadow-sm transition hover:border-primary-300 hover:shadow"
              >
                <p className="font-semibold text-primary-800">{subject.bn_name ?? subject.name}</p>
              </button>
            ))}
          </div>
        </>
      )}

      {classId && selectedSubject && (
        <>
          {chaptersLoading && (
            <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.loading']}</p>
          )}
          {!chaptersLoading && chaptersError && (
            <p className="mt-6 text-sm text-danger-600">{chaptersError}</p>
          )}
          {!chaptersLoading && !chaptersError && entries.length === 0 && (
            <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.empty']}</p>
          )}

          <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {entries.map(({ chapter, book }) => (
              <Link
                key={chapter.id}
                to={`/video-lecture/${chapter.id}`}
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
        </>
      )}
    </section>
  )
}
