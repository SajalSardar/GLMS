import { useEffect, useState } from 'react'
import { getChapterMaterialDownloadLink, listMyChapterMaterials, type ChapterMaterial, type Subject } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import Pagination from '../components/Pagination'
import { useLocale } from '../lib/locale'

const PAGE_SIZE = 10

function formatFileSize(bytes: number | null): string | null {
  if (bytes === null) return null
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(isoString: string): string {
  return new Date(isoString).toLocaleDateString()
}

function chapterLabel(material: ChapterMaterial): string {
  const chapter = material.chapter
  if (!chapter) return ''
  const number = chapter.bn_chapter_number ?? chapter.chapter_number ?? ''
  return `${number} ${chapter.bn_name ?? chapter.name}`.trim()
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

export default function ClassMaterialsPage() {
  const { t } = useLocale()
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [materials, setMaterials] = useState<ChapterMaterial[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [materialLinkError, setMaterialLinkError] = useState<string | null>(null)

  useEffect(() => {
    if (!token) return

    let active = true
    setLoading(true)

    // Scoped entirely server-side to this student's own school/district/
    // shift/section/class/class_version (ChapterMaterialController::
    // mine()), already ordered newest-first — there is no class picker
    // here because there is nothing to pick: a material only ever shows
    // up when it exactly matches this student's own class.
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

  // Every subject actually present in this student's own materials — not
  // the full national subject list, since most subjects won't have
  // anything here anyway.
  const subjects = [
    ...new Map(
      materials
        .map((material) => material.chapter?.book?.subject)
        .filter((subject): subject is Subject => subject !== null && subject !== undefined)
        .map((subject) => [subject.id, subject] as const),
    ).values(),
  ].sort((a, b) => a.name.localeCompare(b.name))

  // Order is preserved from the API response (already newest-first).
  const filteredMaterials =
    subjectId === null
      ? materials
      : materials.filter((material) => material.chapter?.book?.subject_id === subjectId)

  const totalPages = Math.max(1, Math.ceil(filteredMaterials.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleMaterials = filteredMaterials.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="mx-auto max-w-5xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.classMaterials.title']}</h1>

      {!loading && materials.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <select
            className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm"
            value={subjectId ?? ''}
            onChange={(event) => {
              setSubjectId(event.target.value ? Number(event.target.value) : null)
              setPage(1)
            }}
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

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.classMaterials.loading']}</p>}

      {!loading && materials.length === 0 && <InfoAlert message={t['student.classMaterials.noMaterials']} />}

      {!loading && materials.length > 0 && (
        <div className="mt-6 overflow-x-auto rounded-lg border border-gray-100">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead>
              <tr>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colMaterial']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colChapter']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colSubject']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colStatus']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colCreatedAt']}
                </th>
                <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                  {t['student.classMaterials.colAction']}
                </th>
              </tr>
            </thead>
            <tbody>
              {visibleMaterials.map((material) => {
                const size = formatFileSize(material.size_bytes)
                const meta = [material.original_filename, size].filter(Boolean).join(' · ')
                const subject = material.chapter?.book?.subject

                return (
                  <tr key={material.id}>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
                      <span className="block font-medium text-primary-900">{material.title}</span>
                      {meta && <span className="block text-xs text-primary-500">{meta}</span>}
                    </td>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                      {chapterLabel(material)}
                    </td>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                      {subject ? (subject.bn_name ?? subject.name) : ''}
                    </td>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
                      <span className="rounded-sm bg-success-50 px-2 py-0.5 text-xs font-medium text-success-600">
                        {material.status === 'active' ? t['student.classMaterials.statusActive'] : material.status}
                      </span>
                    </td>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                      {formatDate(material.created_at)}
                    </td>
                    <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
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
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {!loading && filteredMaterials.length > 0 && (
        <Pagination
          currentPage={currentPage}
          totalPages={totalPages}
          onPrevious={() => setPage((previous) => Math.max(1, previous - 1))}
          onNext={() => setPage((previous) => Math.min(totalPages, previous + 1))}
          previousLabel={t['student.classMaterials.previousPage']}
          nextLabel={t['student.classMaterials.nextPage']}
          pageOfLabel={t['student.classMaterials.pageOf']}
        />
      )}

      {materialLinkError && <p className="mt-2 text-xs text-danger-600">{materialLinkError}</p>}
    </section>
  )
}
