import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { type Book, type SchoolClass, type Subject } from '@ttla/api-client'
import { allClasses, currentYearBooks, subjectsForClass } from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

const PAGE_SIZE = 10

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

export default function BooksPage() {
  const { t } = useLocale()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classesById, setClassesById] = useState<Map<number, SchoolClass>>(new Map())
  const [classId, setClassId] = useState<number | null>(null)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(null)

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [allBooks, setAllBooks] = useState<Book[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    allClasses()
      .then((result) => {
        setClasses(result)
        setClassesById(new Map(result.map((schoolClass) => [schoolClass.id, schoolClass])))
      })
      .catch(() => {
        setClasses([])
        setClassesById(new Map())
      })
  }, [])

  // The subject filter is class-wise, not the full national list: only
  // subjects actually taught for the selected class (across every school's
  // version of it) are offered. Picking a different class invalidates the
  // previously-chosen subject, since it may not apply to the new class.
  useEffect(() => {
    setSubjectId(null)

    let active = true

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    let active = true
    setLoading(true)

    currentYearBooks()
      .then((result) => {
        if (active) setAllBooks(result)
      })
      .catch(() => {
        if (active) setAllBooks([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  // All three filters apply client-side over the already-loaded current-
  // year book list — no refetch per filter change, same approach as
  // VideoLecturePage's class filter.
  const needle = search.trim().toLowerCase()
  const visibleBooks = allBooks.filter((book) => {
    if (classId !== null && book.class_version?.class_id !== classId) return false
    if (subjectId !== null && book.subject_id !== subjectId) return false
    if (needle && !book.name.toLowerCase().includes(needle) && !(book.bn_name ?? '').toLowerCase().includes(needle)) {
      return false
    }
    return true
  })

  const totalPages = Math.max(1, Math.ceil(visibleBooks.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const pagedBooks = visibleBooks.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  function resetToFirstPage() {
    setPage(1)
  }

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.books.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => {
            setClassId(event.target.value ? Number(event.target.value) : null)
            resetToFirstPage()
          }}
        >
          <option value="">{t['student.books.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.bn_name ?? schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={!classId}
          onChange={(event) => {
            setSubjectId(event.target.value ? Number(event.target.value) : null)
            resetToFirstPage()
          }}
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
          onChange={(event) => {
            setSearch(event.target.value)
            resetToFirstPage()
          }}
        />
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.books.loading']}</p>}

      {!loading && visibleBooks.length === 0 && <InfoAlert message={t['student.books.empty']} />}

      {!loading && visibleBooks.length > 0 && (
        <>
          <div className="mt-6 overflow-x-auto rounded-lg border border-primary-100">
            <table className="w-full min-w-[40rem] text-left text-sm">
              <thead className="bg-primary-50 text-primary-700">
                <tr>
                  <th className="px-4 py-3 font-semibold">{t['student.books.colName']}</th>
                  <th className="px-4 py-3 font-semibold">{t['student.books.colClass']}</th>
                  <th className="px-4 py-3 font-semibold">{t['student.books.colVersion']}</th>
                  <th className="px-4 py-3 font-semibold">{t['student.books.colAcademicYear']}</th>
                  <th className="px-4 py-3 font-semibold">{t['student.books.colAction']}</th>
                </tr>
              </thead>
              <tbody>
                {pagedBooks.map((book) => {
                  const schoolClass = book.class_version?.class_id ? classesById.get(book.class_version.class_id) : undefined

                  return (
                    <tr key={book.id} className="border-t border-primary-100">
                      <td className="px-4 py-3 text-primary-800">{book.bn_name ?? book.name}</td>
                      <td className="px-4 py-3 text-primary-700">
                        {schoolClass ? (schoolClass.bn_name ?? schoolClass.name) : ''}
                      </td>
                      <td className="px-4 py-3 text-primary-700">
                        {book.class_version ? (book.class_version.bn_name ?? book.class_version.name) : ''}
                      </td>
                      <td className="px-4 py-3 text-primary-700">{book.academic_year?.name ?? ''}</td>
                      <td className="px-4 py-3">
                        <Link
                          to={`/books/${book.id}`}
                          className="cursor-pointer rounded-md border border-primary-300 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
                        >
                          {t['student.books.viewDetails']}
                        </Link>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-center gap-3">
            <button
              type="button"
              onClick={() => setPage((previous) => Math.max(1, previous - 1))}
              disabled={currentPage <= 1}
              className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 disabled:cursor-default disabled:opacity-40"
            >
              {t['student.books.previousPage']}
            </button>
            <span className="text-sm text-primary-600">
              {t['student.books.pageOf'].replace('{current}', String(currentPage)).replace('{total}', String(totalPages))}
            </span>
            <button
              type="button"
              onClick={() => setPage((previous) => Math.min(totalPages, previous + 1))}
              disabled={currentPage >= totalPages}
              className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 disabled:cursor-default disabled:opacity-40"
            >
              {t['student.books.nextPage']}
            </button>
          </div>
        </>
      )}
    </section>
  )
}
