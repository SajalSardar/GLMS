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
import { booksForClassAndSubject, subjectsForClass } from '../lib/resolveContent'

const t = getCommonStrings('bn')

// Same shape as the messageFromApiError() helper in ExamRunnerPage.tsx,
// but with a translated `fallback` instead of the raw `error.message` —
// a plain fetch/network
// failure on a list page has no useful backend-supplied text (unlike the
// exam flows, where a specific 422 message is itself meaningful to show),
// so falling back to a generic translated string avoids surfacing an
// untranslated technical message for this case.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    // No `?? error.message` here: apiFetch() always supplies a message
    // (falling back to a raw "Request to X failed with status Y" string
    // when the response body has none), so including it would make the
    // translated `fallback` below unreachable for any real HTTP error —
    // exactly the untranslated-technical-message case this helper exists
    // to avoid.
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

export default function BooksPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classId, setClassId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [search, setSearch] = useState('')
  const [books, setBooks] = useState<Book[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    // ClassVersion only carries a bare `school_id` (no nested `school`
    // relation — confirmed against the ClassVersion interface in
    // api-client/src/index.ts), so school names for the per-school book
    // labels below are resolved by fetching the school list once and
    // mapping by id, same as management's ClassVersionsPage.tsx does.
    listSchools({ perPage: 500 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  // The subject list is class-wise, not the full national list: only
  // subjects actually taught for the selected class (across every
  // school's version of it) are offered. Picking a different class
  // invalidates the previously-chosen subject, since it may not apply to
  // the new class.
  useEffect(() => {
    setSubjectId(null)

    subjectsForClass(classId)
      .then((result) => setSubjects(result))
      .catch(() => setSubjects([]))
  }, [classId])

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)

    booksForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setBooks(result)
      })
      .catch((caught) => {
        if (active) {
          setBooks([])
          setError(messageFromApiError(caught, t['student.books.loadError']))
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

  // listBooks has no `search` query param, and the cross-school merge
  // already happens client-side in booksForClassAndSubject — so this is a
  // simple client-side substring filter over the already-resolved list,
  // not a server-side search.
  const visibleBooks = search.trim()
    ? books.filter((book) => {
        const needle = search.trim().toLowerCase()
        return (
          book.name.toLowerCase().includes(needle) || (book.bn_name ?? '').toLowerCase().includes(needle)
        )
      })
    : books

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.books.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.books.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.bn_name ?? schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={!classId}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.books.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.bn_name ?? subject.name}
            </option>
          ))}
        </select>

        <input
          type="text"
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          placeholder={t['student.books.searchPlaceholder']}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.books.loading']}</p>}

      {!loading && error && <p className="mt-6 text-sm text-danger-600">{error}</p>}

      {!loading && !error && !classId && (
        <p className="mt-6 text-sm text-primary-600">{t['student.books.pickClass']}</p>
      )}

      {!loading && !error && classId && visibleBooks.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.books.empty']}</p>
      )}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {visibleBooks.map((book) => (
          <Link
            key={book.id}
            to={`/books/${book.id}`}
            className="rounded-lg border border-primary-100 p-4 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">{book.bn_name ?? book.name}</p>
            <p className="mt-1 text-sm text-primary-600">{book.subject?.name}</p>
            <p className="mt-1 text-xs text-primary-500">{schoolNameFor(book)}</p>
            {!book.has_available_pdf && (
              <p className="mt-2 text-xs text-[color:var(--color-accent-dark)]">{t['student.books.noPdf']}</p>
            )}
          </Link>
        ))}
      </div>
    </section>
  )
}
