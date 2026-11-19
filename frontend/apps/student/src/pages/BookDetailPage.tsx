import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { getBook, getBookPdfLinks, listChapters, type Book, type Chapter } from '@ttla/api-client'
import { useLocale } from '../lib/locale'

export default function BookDetailPage() {
  const { t } = useLocale()
  const { id } = useParams<{ id: string }>()
  const bookId = Number(id)

  const [book, setBook] = useState<Book | null>(null)
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [pdfError, setPdfError] = useState<string | null>(null)

  useEffect(() => {
    if (!bookId) {
      setLoading(false)
      setNotFound(true)
      return
    }

    let active = true
    setLoading(true)
    setNotFound(false)

    // BookController::show() only eager-loads ['classVersion', 'subject']
    // (confirmed by reading backend/app/Http/Controllers/Api/V1/BookController.php)
    // — it does not include chapters, so they're fetched separately here
    // rather than read off `book.chapters`.
    Promise.all([getBook(bookId), listChapters({ bookId })])
      .then(([bookResult, chaptersResult]) => {
        if (!active) return
        setBook(bookResult.data)
        setChapters(chaptersResult.data)
      })
      .catch(() => {
        if (active) setNotFound(true)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [bookId])

  async function handlePreview() {
    if (!book) return
    setPdfError(null)

    // window.open() must happen synchronously inside the click handler —
    // once we `await` anything first, the click's transient user-activation
    // is gone and browsers silently block the popup (no error, nothing
    // happens). So open a blank tab right away and point it at the real
    // URL once the fetch resolves. `tab` is null if even this synchronous
    // open gets blocked (e.g. a hardened popup blocker) — fall back to the
    // pre-fix behavior in that case rather than losing the link entirely.
    const tab = window.open('', '_blank')

    try {
      const links = await getBookPdfLinks(book.id)
      if (tab) {
        tab.location.href = links.preview_url
      } else {
        window.open(links.preview_url, '_blank')
      }
    } catch {
      if (tab) tab.close()
      setPdfError(t['student.books.pdfError'])
    }
  }

  async function handleDownload() {
    if (!book) return
    setPdfError(null)
    try {
      const links = await getBookPdfLinks(book.id)
      window.location.href = links.download_url
    } catch {
      setPdfError(t['student.books.pdfError'])
    }
  }

  if (loading) {
    return (
      <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
        <p className="text-sm text-primary-600">{t['student.books.loading']}</p>
      </section>
    )
  }

  if (notFound || !book) {
    return (
      <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
        <p className="text-sm text-primary-600">{t['student.books.notFound']}</p>
        <Link to="/books" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
          {t['student.books.backToList']}
        </Link>
      </section>
    )
  }

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <Link to="/books" className="text-sm font-semibold text-primary-700 underline">
        {t['student.books.backToList']}
      </Link>

      <h1 className="mt-4 text-2xl font-bold text-primary-800">{book.bn_name ?? book.name}</h1>
      <p className="mt-1 text-sm text-primary-600">{book.subject?.name}</p>
      {book.description && <p className="mt-3 text-sm text-primary-700">{book.description}</p>}

      <div className="mt-6 flex flex-wrap gap-3">
        {book.has_available_pdf ? (
          <>
            <button
              type="button"
              onClick={handlePreview}
              className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-[#EB1F27]"
            >
              {t['student.books.preview']}
            </button>
            <button
              type="button"
              onClick={handleDownload}
              className="cursor-pointer rounded-md border border-primary-300 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
            >
              {t['student.books.download']}
            </button>
          </>
        ) : (
          <p className="text-xs text-[color:var(--color-accent-dark)]">{t['student.books.noPdf']}</p>
        )}
      </div>

      {pdfError && <p className="mt-2 text-xs text-danger-600">{pdfError}</p>}

      <h2 className="mt-8 text-lg font-semibold text-primary-800">{t['student.books.chapters']}</h2>

      {chapters.length === 0 ? (
        <p className="mt-2 text-sm text-primary-600">{t['student.books.noChapters']}</p>
      ) : (
        <ol className="mt-3 flex flex-col gap-2">
          {chapters.map((chapter) => (
            <li
              key={chapter.id}
              className="rounded-md border border-primary-100 px-4 py-3 text-sm text-primary-800"
            >
              {chapter.bn_chapter_number ?? chapter.chapter_number ?? ''} {chapter.bn_name ?? chapter.name}
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
