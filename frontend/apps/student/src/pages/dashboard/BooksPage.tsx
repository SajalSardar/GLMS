import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { type Book, type SchoolClass, type Subject } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { allClasses, currentYearBooks, ownClassId, subjectsForClass } from '../../lib/resolveContent'
import { useLocale } from '../../lib/locale'
import Pagination from './components/Pagination'
import SearchableSelect from './components/SearchableSelect'

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

// Dashboard-only variant of the public website's BooksPage (src/pages/
// BooksPage.tsx) — same data/filtering logic, styled to match the
// management portal's list pages instead of the public site's. Kept as a
// separate component rather than a shared one with style branches, since
// the two contexts intentionally look nothing alike.
export default function BooksPage() {
  const { t } = useLocale()
  const { token, userId } = useAuth()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classesById, setClassesById] = useState<Map<number, SchoolClass>>(new Map())
  const [classId, setClassId] = useState<number | null>(null)

  // Once a logged-in student's own class resolves, the class filter locks
  // to it (shown as a badge, not a dropdown) — "own classes book" means
  // there's nothing else to pick. A student whose profile has no class
  // assigned yet keeps the full selector.
  const [ownClassLocked, setOwnClassLocked] = useState(false)

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

  useEffect(() => {
    if (!token || !userId) return

    let active = true

    ownClassId(userId, token)
      .then((result) => {
        if (!active || result === null) return
        setClassId(result)
        setOwnClassLocked(true)
      })
      .catch(() => {})

    return () => {
      active = false
    }
  }, [token, userId])

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

  const ownClass = ownClassLocked && classId !== null ? classesById.get(classId) : undefined

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.books.title']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <input
          type="search"
          className="h-11 min-w-[220px] flex-1 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          placeholder={t['student.books.searchPlaceholder']}
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            resetToFirstPage()
          }}
        />

        {ownClassLocked ? (
          <span className="inline-flex h-11 items-center rounded-md bg-primary-50 px-3 text-sm font-semibold text-primary-700">
            {t['student.books.yourClass'].replace('{class}', ownClass ? (ownClass.bn_name ?? ownClass.name) : '')}
          </span>
        ) : (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={classId !== null ? String(classId) : ''}
              onChange={(value) => {
                setClassId(value ? Number(value) : null)
                resetToFirstPage()
              }}
              placeholder={t['student.books.allClasses']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: t['student.books.allClasses'] },
                ...classes.map((schoolClass) => ({
                  value: String(schoolClass.id),
                  label: schoolClass.bn_name ?? schoolClass.name,
                })),
              ]}
            />
          </div>
        )}

        <div className="w-full sm:w-56">
          <SearchableSelect
            value={subjectId !== null ? String(subjectId) : ''}
            onChange={(value) => {
              setSubjectId(value ? Number(value) : null)
              resetToFirstPage()
            }}
            placeholder={t['student.books.allSubjects']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            disabled={!classId}
            options={[
              { value: '', label: t['student.books.allSubjects'] },
              ...subjects.map((subject) => ({ value: String(subject.id), label: subject.bn_name ?? subject.name })),
            ]}
          />
        </div>
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.books.loading']}</p>}

      {!loading && visibleBooks.length === 0 && <InfoAlert message={t['student.books.empty']} />}

      {!loading && visibleBooks.length > 0 && (
        <>
          <div className="mt-6 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[40rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.books.colName']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.books.colClass']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.books.colVersion']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.books.colAcademicYear']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.books.colAction']}</th>
                </tr>
              </thead>
              <tbody>
                {pagedBooks.map((book, index) => {
                  const schoolClass = book.class_version?.class_id ? classesById.get(book.class_version.class_id) : undefined

                  return (
                    <tr key={book.id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                        {(currentPage - 1) * PAGE_SIZE + index + 1}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className="font-medium text-primary-900">{book.bn_name ?? book.name}</span>
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                        {schoolClass ? (schoolClass.bn_name ?? schoolClass.name) : ''}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                        {book.class_version ? (book.class_version.bn_name ?? book.class_version.name) : ''}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{book.academic_year?.name ?? ''}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <Link
                          to={`/books/${book.id}`}
                          className="inline-flex cursor-pointer items-center gap-1.5 text-primary-600 hover:text-[#EB1F27]"
                        >
                          <span className="material-symbols-outlined !text-[18px] leading-none">visibility</span>
                          <span className="text-xs font-semibold">{t['student.books.viewDetails']}</span>
                        </Link>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={(targetPage) => setPage(targetPage)}
            visibleCount={pagedBooks.length}
            total={visibleBooks.length}
            showingLabel={t['management.table.showing']}
            ofLabel={t['management.table.of']}
            resultsLabel={t['management.table.results']}
            previousLabel={t['student.books.previousPage']}
            nextLabel={t['student.books.nextPage']}
          />
        </>
      )}
    </section>
  )
}
