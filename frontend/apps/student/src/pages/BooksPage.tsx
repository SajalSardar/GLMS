import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  listBooks,
  listClasses,
  listClassVersions,
  listSchools,
  listSubjects,
  type Book,
  type School,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

async function booksForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Book[]> {
  if (!classId) return []

  const versionsResult = await listClassVersions({ classId })
  const versions = versionsResult.data

  const bookLists = await Promise.all(
    versions.map((version) => listBooks({ classVersionId: version.id, subjectId: subjectId ?? undefined })),
  )

  return bookLists.flatMap((result) => result.data)
}

export default function BooksPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classId, setClassId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [books, setBooks] = useState<Book[]>([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    // ClassVersion only carries a bare `school_id` (no nested `school`
    // relation — confirmed against the ClassVersion interface in
    // api-client/src/index.ts), so school names for the per-school book
    // labels below are resolved by fetching the school list once and
    // mapping by id, same as management's ClassVersionsPage.tsx does.
    listSchools({ perPage: 500 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  useEffect(() => {
    let active = true
    setLoading(true)

    booksForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setBooks(result)
      })
      .catch(() => {
        if (active) setBooks([])
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
              {schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={subjectId ?? ''}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.books.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.name}
            </option>
          ))}
        </select>
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.books.loading']}</p>}

      {!loading && !classId && <p className="mt-6 text-sm text-primary-600">{t['student.books.pickClass']}</p>}

      {!loading && classId && books.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.books.empty']}</p>
      )}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {books.map((book) => (
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
