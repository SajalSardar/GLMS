import { useEffect, useState } from 'react'
import {
  getChapterMaterialDownloadLink,
  listChapterMaterials,
  type ChapterMaterial,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { allClasses, chaptersForClassAndSubject, ownClassId, subjectsForClass, type ChapterWithBook } from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

function formatFileSize(bytes: number | null): string | null {
  if (bytes === null) return null
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
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

function chapterTitle(entry: ChapterWithBook): string {
  const number = entry.chapter.bn_chapter_number ?? entry.chapter.chapter_number ?? ''
  const name = entry.chapter.bn_name ?? entry.chapter.name
  return `${number} ${name}`.trim()
}

export default function ClassMaterialsPage() {
  const { t } = useLocale()
  const { token, userId } = useAuth()

  const [classesById, setClassesById] = useState<Map<number, SchoolClass>>(new Map())
  const [classId, setClassId] = useState<number | null>(null)
  const [classResolved, setClassResolved] = useState(false)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(null)

  const [chapters, setChapters] = useState<ChapterWithBook[]>([])
  const [chaptersLoading, setChaptersLoading] = useState(false)

  const [expandedChapterIds, setExpandedChapterIds] = useState<Set<number>>(new Set())
  const [materialsByChapter, setMaterialsByChapter] = useState<
    Record<number, ChapterMaterial[] | 'loading' | 'error'>
  >({})
  const [materialLinkError, setMaterialLinkError] = useState<string | null>(null)

  useEffect(() => {
    allClasses()
      .then((result) => setClassesById(new Map(result.map((schoolClass) => [schoolClass.id, schoolClass]))))
      .catch(() => setClassesById(new Map()))
  }, [])

  useEffect(() => {
    if (!token || !userId) return

    let active = true

    ownClassId(userId, token)
      .then((result) => {
        if (!active) return
        setClassId(result)
        setClassResolved(true)
      })
      .catch(() => {
        if (active) setClassResolved(true)
      })

    return () => {
      active = false
    }
  }, [token, userId])

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
    setExpandedChapterIds(new Set())
    setMaterialsByChapter({})

    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    setChaptersLoading(true)

    chaptersForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setChapters(result)
      })
      .catch(() => {
        if (active) setChapters([])
      })
      .finally(() => {
        if (active) setChaptersLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

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
    // here for the rest of the page's lifetime.
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
      setMaterialLinkError(t['student.classMaterials.materialLinkError'])
    }
  }

  const ownClass = classId !== null ? classesById.get(classId) : undefined

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.classMaterials.title']}</h1>

      {classResolved && classId !== null && (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <span className="inline-flex h-10 items-center rounded-md bg-primary-50 px-3 text-sm font-semibold text-primary-700">
            {t['student.classMaterials.yourClass'].replace('{class}', ownClass ? (ownClass.bn_name ?? ownClass.name) : '')}
          </span>

          <select
            className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm"
            value={subjectId ?? ''}
            onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">{t['student.classMaterials.allSubjects']}</option>
            {subjects.map((subject) => (
              <option key={subject.id} value={subject.id}>
                {subject.bn_name ?? subject.name}
              </option>
            ))}
          </select>
        </div>
      )}

      {classResolved && classId === null && <InfoAlert message={t['student.classMaterials.noClass']} />}

      {classResolved && classId !== null && !subjectId && (
        <InfoAlert message={t['student.classMaterials.pickSubject']} />
      )}

      {chaptersLoading && <p className="mt-6 text-sm text-primary-600">{t['student.classMaterials.loading']}</p>}

      {!chaptersLoading && subjectId && chapters.length === 0 && (
        <InfoAlert message={t['student.classMaterials.noChapters']} />
      )}

      {!chaptersLoading && subjectId && chapters.length > 0 && (
        <div className="mt-6 divide-y divide-primary-100 overflow-hidden rounded-2xl border border-primary-100 bg-white">
          {chapters.map((entry) => {
            const expanded = expandedChapterIds.has(entry.chapter.id)
            const materials = materialsByChapter[entry.chapter.id]
            const bookName = entry.book.bn_name ?? entry.book.name

            return (
              <div key={entry.chapter.id}>
                <button
                  type="button"
                  onClick={() => toggleChapter(entry.chapter.id)}
                  className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left hover:bg-primary-50"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-primary-800">
                      {chapterTitle(entry)}
                    </span>
                    <span className="block text-xs text-primary-500">{bookName}</span>
                  </span>
                  <ChevronIcon expanded={expanded} />
                </button>

                {expanded && (
                  <div className="border-t border-primary-100 bg-primary-50/40 px-5 py-4">
                    {materials === 'loading' && (
                      <p className="text-sm text-primary-600">{t['student.classMaterials.materialsLoading']}</p>
                    )}
                    {materials === 'error' && (
                      <p className="text-sm text-danger-600">{t['student.classMaterials.materialsLoadError']}</p>
                    )}
                    {Array.isArray(materials) && materials.length === 0 && (
                      <p className="text-sm text-primary-600">{t['student.classMaterials.noMaterials']}</p>
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

                              <span className="flex flex-shrink-0 gap-2">
                                <button
                                  type="button"
                                  onClick={() => handleMaterialOpen(material, 'preview')}
                                  className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#EB1F27]"
                                >
                                  {t['student.classMaterials.preview']}
                                </button>
                                <button
                                  type="button"
                                  onClick={() => handleMaterialOpen(material, 'download')}
                                  className="cursor-pointer rounded-md border border-primary-300 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
                                >
                                  {t['student.classMaterials.download']}
                                </button>
                              </span>
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
