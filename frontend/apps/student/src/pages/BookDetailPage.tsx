import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  getBook,
  getBookPdfLinks,
  getChapterMaterialDownloadLink,
  listChapterMaterials,
  listChapters,
  type Book,
  type Chapter,
  type ChapterMaterial,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../lib/locale'

function formatFileSize(bytes: number | null): string | null {
  if (bytes === null) return null
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function ChevronIcon({ expanded }: { expanded: boolean }) {
  return (
    <svg
      width={18}
      height={18}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className={`flex-shrink-0 text-primary-500 transition-transform ${expanded ? 'rotate-180' : ''}`}
    >
      <path d="M6 9l6 6 6-6" />
    </svg>
  )
}

function DocumentIcon() {
  return (
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
      className="flex-shrink-0 text-primary-500"
    >
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
    </svg>
  )
}

export default function BookDetailPage() {
  const { t } = useLocale()
  const { token } = useAuth()
  const { id } = useParams<{ id: string }>()
  const bookId = Number(id)

  const [book, setBook] = useState<Book | null>(null)
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [pdfError, setPdfError] = useState<string | null>(null)

  const [expandedChapterIds, setExpandedChapterIds] = useState<Set<number>>(new Set())
  const [materialsByChapter, setMaterialsByChapter] = useState<
    Record<number, ChapterMaterial[] | 'loading' | 'error'>
  >({})
  const [materialLinkError, setMaterialLinkError] = useState<string | null>(null)

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

  function toggleChapter(chapterId: number) {
    setExpandedChapterIds((previous) => {
      const next = new Set(previous)
      if (next.has(chapterId)) {
        next.delete(chapterId)
      } else {
        next.add(chapterId)
      }
      return next
    })

    // Materials are fetched once per chapter, on first expand, and cached
    // here for the rest of the page's lifetime — re-collapsing/expanding
    // never refetches.
    if (materialsByChapter[chapterId] !== undefined) return

    setMaterialsByChapter((previous) => ({ ...previous, [chapterId]: 'loading' }))

    listChapterMaterials({ chapterId })
      .then((result) => {
        setMaterialsByChapter((previous) => ({
          ...previous,
          [chapterId]: result.data.filter((material) => material.status === 'active'),
        }))
      })
      .catch(() => {
        setMaterialsByChapter((previous) => ({ ...previous, [chapterId]: 'error' }))
      })
  }

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

  async function handleMaterialOpen(material: ChapterMaterial, action: 'preview' | 'download') {
    if (!token) return
    setMaterialLinkError(null)

    const tab = action === 'preview' ? window.open('', '_blank') : null

    try {
      const links = await getChapterMaterialDownloadLink(material.id, token)
      if (action === 'preview') {
        const url = links.preview_url
        if (tab) {
          tab.location.href = url
        } else {
          window.open(url, '_blank')
        }
      } else {
        window.location.href = links.download_url
      }
    } catch {
      if (tab) tab.close()
      setMaterialLinkError(t['student.books.materialLinkError'])
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

      <div className="mt-4 flex flex-col gap-5 rounded-2xl border border-primary-100 bg-white p-6 shadow-sm sm:flex-row sm:items-start">
        <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
          <svg
            width={28}
            height={28}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
          </svg>
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-primary-800">{book.bn_name ?? book.name}</h1>
            {book.subject && (
              <span className="rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700">
                {book.subject.bn_name ?? book.subject.name}
              </span>
            )}
          </div>

          {book.description && <p className="mt-2 text-sm text-primary-600">{book.description}</p>}

          <div className="mt-4 flex flex-wrap gap-3">
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
        </div>
      </div>

      <h2 className="mt-8 text-lg font-semibold text-primary-800">{t['student.books.chapters']}</h2>

      {chapters.length === 0 ? (
        <p className="mt-2 text-sm text-primary-600">{t['student.books.noChapters']}</p>
      ) : (
        <div className="mt-3 divide-y divide-primary-100 overflow-hidden rounded-2xl border border-primary-100 bg-white">
          {chapters.map((chapter) => {
            const expanded = expandedChapterIds.has(chapter.id)
            const materials = materialsByChapter[chapter.id]

            return (
              <div key={chapter.id}>
                <button
                  type="button"
                  onClick={() => toggleChapter(chapter.id)}
                  className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left hover:bg-primary-50"
                >
                  <span className="flex min-w-0 items-center gap-3">
                    <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700">
                      {chapter.bn_chapter_number ?? chapter.chapter_number ?? ''}
                    </span>
                    <span className="truncate text-sm font-semibold text-primary-800">
                      {chapter.bn_name ?? chapter.name}
                    </span>
                  </span>
                  <ChevronIcon expanded={expanded} />
                </button>

                {expanded && (
                  <div className="border-t border-primary-100 bg-primary-50/40 px-5 py-4">
                    {materials === 'loading' && (
                      <p className="text-sm text-primary-600">{t['student.books.materialsLoading']}</p>
                    )}
                    {materials === 'error' && (
                      <p className="text-sm text-danger-600">{t['student.books.materialsLoadError']}</p>
                    )}
                    {Array.isArray(materials) && materials.length === 0 && (
                      <p className="text-sm text-primary-600">{t['student.books.noMaterials']}</p>
                    )}
                    {Array.isArray(materials) && materials.length > 0 && (
                      <div className="flex flex-col gap-2">
                        {materials.map((material) => {
                          const size = formatFileSize(material.size_bytes)
                          const meta = [material.original_filename, size].filter(Boolean).join(' · ')

                          return (
                            <div
                              key={material.id}
                              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-100 bg-white px-4 py-3"
                            >
                              <span className="flex min-w-0 items-center gap-3">
                                <DocumentIcon />
                                <span className="min-w-0">
                                  <span className="block truncate text-sm font-medium text-primary-800">
                                    {material.title}
                                  </span>
                                  {meta && <span className="block text-xs text-primary-500">{meta}</span>}
                                </span>
                              </span>

                              {token ? (
                                <span className="flex flex-shrink-0 gap-2">
                                  <button
                                    type="button"
                                    onClick={() => handleMaterialOpen(material, 'preview')}
                                    className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#EB1F27]"
                                  >
                                    {t['student.books.preview']}
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => handleMaterialOpen(material, 'download')}
                                    className="cursor-pointer rounded-md border border-primary-300 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
                                  >
                                    {t['student.books.download']}
                                  </button>
                                </span>
                              ) : (
                                <span className="flex-shrink-0 text-xs text-primary-500">
                                  {t['student.books.materialLoginRequired']}
                                </span>
                              )}
                            </div>
                          )
                        })}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {materialLinkError && <p className="mt-2 text-xs text-danger-600">{materialLinkError}</p>}
    </section>
  )
}
