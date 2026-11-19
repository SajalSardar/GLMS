import { useEffect, useState } from 'react'
import { getChapterMaterialDownloadLink, listMyChapterMaterials, type ChapterMaterial } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
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

interface ChapterGroup {
  chapterId: number
  chapterTitle: string
  bookName: string
  materials: ChapterMaterial[]
}

function groupByChapter(materials: ChapterMaterial[]): ChapterGroup[] {
  const groups = new Map<number, ChapterGroup>()

  for (const material of materials) {
    const chapter = material.chapter
    if (!chapter) continue

    const existing = groups.get(chapter.id)
    if (existing) {
      existing.materials.push(material)
      continue
    }

    const number = chapter.bn_chapter_number ?? chapter.chapter_number ?? ''
    const title = `${number} ${chapter.bn_name ?? chapter.name}`.trim()
    const bookName = chapter.book ? (chapter.book.bn_name ?? chapter.book.name) : ''

    groups.set(chapter.id, { chapterId: chapter.id, chapterTitle: title, bookName, materials: [material] })
  }

  return [...groups.values()]
}

export default function ClassMaterialsPage() {
  const { t } = useLocale()
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [materials, setMaterials] = useState<ChapterMaterial[]>([])
  const [materialLinkError, setMaterialLinkError] = useState<string | null>(null)

  useEffect(() => {
    if (!token) return

    let active = true
    setLoading(true)

    // Scoped entirely server-side to this student's own school/district/
    // shift/section/class/class_version (ChapterMaterialController::
    // mine()) — there is no class/subject picker here because there is
    // nothing to pick: a material only ever shows up here when it
    // exactly matches this student's own class.
    listMyChapterMaterials(token)
      .then((result) => {
        if (active) setMaterials(result.data)
      })
      .catch(() => {
        if (active) setMaterials([])
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token])

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

  const chapterGroups = groupByChapter(materials)

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.classMaterials.title']}</h1>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.classMaterials.loading']}</p>}

      {!loading && chapterGroups.length === 0 && <InfoAlert message={t['student.classMaterials.noMaterials']} />}

      {!loading && chapterGroups.length > 0 && (
        <div className="mt-6 flex flex-col gap-6">
          {chapterGroups.map((group) => (
            <div key={group.chapterId} className="overflow-hidden rounded-2xl border border-primary-100 bg-white">
              <div className="border-b border-primary-100 bg-primary-50/60 px-5 py-3">
                <p className="text-sm font-semibold text-primary-800">{group.chapterTitle}</p>
                {group.bookName && <p className="text-xs text-primary-500">{group.bookName}</p>}
              </div>

              <div className="flex flex-col gap-2 p-4">
                {group.materials.map((material) => {
                  const size = formatFileSize(material.size_bytes)
                  const meta = [material.original_filename, size].filter(Boolean).join(' · ')

                  return (
                    <div
                      key={material.id}
                      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-100 px-4 py-3"
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
            </div>
          ))}
        </div>
      )}

      {materialLinkError && <p className="mt-2 text-xs text-danger-600">{materialLinkError}</p>}
    </section>
  )
}
