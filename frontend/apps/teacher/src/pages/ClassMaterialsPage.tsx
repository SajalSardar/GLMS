import { useEffect, useMemo, useRef, useState, type DragEvent, type FormEvent } from 'react'
import {
  ApiError,
  createChapterMaterial,
  deleteChapterMaterial,
  getChapterMaterialDownloadLink,
  listAcademicYears,
  listBooks,
  listChapters,
  listMyChapterMaterialAssignments,
  listMyUploadedChapterMaterials,
  updateChapterMaterial,
  type Book,
  type Chapter,
  type ChapterMaterial,
  type ChapterMaterialAssignment,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

const ACCEPTED_FILE_TYPES = 'application/pdf,image/jpeg,image/png'

/**
 * First row carrying each distinct, non-null key — same technique
 * MarksEntryPage uses to turn a flat assignment list into cascading
 * filter options without a dedicated endpoint per level.
 */
function uniqueByKey<T, K>(items: T[], keyFn: (item: T) => K | null): T[] {
  const seen = new Set<K>()
  const result: T[] = []

  for (const item of items) {
    const key = keyFn(item)
    if (key === null || seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

type MaterialType = 'primary' | 'supplementary'

function fileTypeIcon(filename: string | null | undefined): string {
  const extension = filename?.split('.').pop()?.toLowerCase()
  return extension === 'jpg' || extension === 'jpeg' || extension === 'png' ? 'image' : 'picture_as_pdf'
}

function FileDropZone({
  file,
  placeholderName,
  onFileChange,
  label,
}: {
  file: File | null
  placeholderName?: string | null
  onFileChange: (file: File | null) => void
  label: string
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)

  const displayName = file?.name ?? placeholderName ?? null

  function handleDrop(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    setDragOver(false)
    const dropped = event.dataTransfer.files?.[0]
    if (dropped) onFileChange(dropped)
  }

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => inputRef.current?.click()}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') inputRef.current?.click()
      }}
      onDragOver={(event) => {
        event.preventDefault()
        setDragOver(true)
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={handleDrop}
      className={`flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-md border-2 border-dashed px-4 py-6 text-center transition-colors ${
        dragOver ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-primary-500'
      }`}
    >
      <span className="material-symbols-outlined !text-3xl text-primary-500">{fileTypeIcon(displayName) === 'image' ? 'image' : 'upload_file'}</span>
      <p className="max-w-full truncate text-sm font-medium text-gray-700">{displayName ?? label}</p>
      {!displayName && <p className="text-xs text-gray-400">{t['teacher.classMaterials.fileHint']}</p>}
      {file && <p className="text-xs text-primary-500">{t['teacher.classMaterials.fileChangeHint']}</p>}
      <input
        ref={inputRef}
        type="file"
        accept={ACCEPTED_FILE_TYPES}
        className="hidden"
        onChange={(event) => onFileChange(event.target.files?.[0] ?? null)}
      />
    </div>
  )
}

function StatusBadge({ status, onClick, busy }: { status: string; onClick: () => void; busy: boolean }) {
  const isActive = status === 'active'

  return (
    <button
      type="button"
      disabled={busy}
      onClick={onClick}
      title={t['teacher.classMaterials.statusToggleHint']}
      className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
        isActive ? 'bg-success-50 text-success-600 hover:bg-success-100' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'
      }`}
    >
      <span className="material-symbols-outlined !text-[14px] leading-none">{isActive ? 'check_circle' : 'archive'}</span>
      {isActive ? t['teacher.classMaterials.statusActive'] : t['teacher.classMaterials.statusArchived']}
    </button>
  )
}

export default function ClassMaterialsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<ChapterMaterialAssignment[]>([])
  const [myUploads, setMyUploads] = useState<ChapterMaterial[]>([])
  const [currentAcademicYearId, setCurrentAcademicYearId] = useState<number | null>(null)
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [books, setBooks] = useState<Book[]>([])
  const [bookId, setBookId] = useState<number | null>(null)
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [chapterId, setChapterId] = useState<number | null>(null)

  const [type, setType] = useState<MaterialType>('primary')
  const [title, setTitle] = useState('')
  const [orderIndex, setOrderIndex] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editTitle, setEditTitle] = useState('')
  const [editOrderIndex, setEditOrderIndex] = useState('')
  const [editFile, setEditFile] = useState<File | null>(null)
  const [savingEdit, setSavingEdit] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [viewingId, setViewingId] = useState<number | null>(null)
  const [statusTogglingId, setStatusTogglingId] = useState<number | null>(null)

  function refreshMyUploads() {
    if (!token) return
    listMyUploadedChapterMaterials(token)
      .then((result) => setMyUploads(result.data))
      .catch(() => setMyUploads([]))
  }

  useEffect(() => {
    if (!token) return

    Promise.all([
      listMyChapterMaterialAssignments(token),
      listMyUploadedChapterMaterials(token),
      listAcademicYears({ currentOnly: true }),
    ])
      .then(([assignmentResult, uploadsResult, yearResult]) => {
        setAssignments(assignmentResult.data)
        setMyUploads(uploadsResult.data)
        setCurrentAcademicYearId(yearResult.data[0]?.id ?? null)
      })
      .catch(() => {
        setAssignments([])
        setMyUploads([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])
  const subjectsForSection = useMemo(
    () => assignments.filter((row) => row.section_id === sectionId),
    [assignments, sectionId],
  )
  const selectedAssignment = useMemo(
    () => assignments.find((row) => row.section_id === sectionId && row.subject_id === subjectId) ?? null,
    [assignments, sectionId, subjectId],
  )

  // Only the current academic year's books, plus "evergreen" books that
  // have no academic_year_id at all — a distant year's book never shows
  // up here even though it technically matches the subject/class version.
  useEffect(() => {
    if (!selectedAssignment || selectedAssignment.class_version_id === null) {
      setBooks([])
      setBookId(null)
      return
    }

    let active = true
    listBooks({ classVersionId: selectedAssignment.class_version_id, subjectId: selectedAssignment.subject_id })
      .then((result) => {
        if (!active) return
        setBooks(result.data.filter((book) => book.academic_year_id === null || book.academic_year_id === currentAcademicYearId))
      })
      .catch(() => {
        if (active) setBooks([])
      })

    return () => {
      active = false
    }
  }, [selectedAssignment, currentAcademicYearId])

  useEffect(() => {
    if (bookId === null) {
      setChapters([])
      setChapterId(null)
      return
    }

    let active = true
    listChapters({ bookId })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [bookId])

  function resetUploadForm() {
    setType('primary')
    setTitle('')
    setOrderIndex('')
    setFile(null)
  }

  async function handleUpload(event: FormEvent) {
    event.preventDefault()
    if (!token || sectionId === null || chapterId === null || !file) return

    const formData = new FormData()
    formData.append('chapter_id', String(chapterId))
    formData.append('section_id', String(sectionId))
    formData.append('type', type)
    if (type === 'supplementary') formData.append('title', title)
    if (orderIndex.trim() !== '') formData.append('order_index', orderIndex)
    formData.append('file', file)

    setUploading(true)

    try {
      await createChapterMaterial(formData, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.uploadSuccess'] })
      resetUploadForm()
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.uploadError'] })
    } finally {
      setUploading(false)
    }
  }

  function startEdit(material: ChapterMaterial) {
    setEditingId(material.id)
    setEditTitle(material.title ?? '')
    setEditOrderIndex(material.order_index === null ? '' : String(material.order_index))
    setEditFile(null)
  }

  function cancelEdit() {
    setEditingId(null)
    setEditFile(null)
  }

  async function handleSaveEdit(materialId: number) {
    if (!token) return

    const formData = new FormData()
    formData.append('title', editTitle)
    if (editOrderIndex.trim() !== '') formData.append('order_index', editOrderIndex)
    if (editFile) formData.append('file', editFile)

    setSavingEdit(true)

    try {
      await updateChapterMaterial(materialId, formData, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.saveSuccess'] })
      setEditingId(null)
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.saveError'] })
    } finally {
      setSavingEdit(false)
    }
  }

  async function handleDelete(materialId: number) {
    if (!token) return

    setDeletingId(materialId)

    try {
      await deleteChapterMaterial(materialId, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.deleteSuccess'] })
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.deleteError'] })
    } finally {
      setDeletingId(null)
    }
  }

  async function handleStatusToggle(material: ChapterMaterial) {
    if (!token) return

    const formData = new FormData()
    formData.append('status', material.status === 'active' ? 'archived' : 'active')

    setStatusTogglingId(material.id)

    try {
      await updateChapterMaterial(material.id, formData, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.saveSuccess'] })
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.saveError'] })
    } finally {
      setStatusTogglingId(null)
    }
  }

  async function handleView(materialId: number) {
    if (!token) return

    setViewingId(materialId)

    try {
      const links = await getChapterMaterialDownloadLink(materialId, token)
      window.open(links.preview_url, '_blank', 'noopener,noreferrer')
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.viewError'] })
    } finally {
      setViewingId(null)
    }
  }

  function sectionLabel(row: ChapterMaterialAssignment) {
    return [row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')
  }

  function materialSectionLabel(material: ChapterMaterial) {
    const row = assignments.find((assignment) => assignment.section_id === material.section_id)
    return row ? sectionLabel(row) : '—'
  }

  const selectClass =
    'h-11 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'
  const labelClass = 'flex flex-col gap-1.5 text-sm'
  const labelTextClass = 'font-medium text-gray-700'

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">{t['teacher.nav.classMaterials']}</h1>
        <p className="mt-1 text-sm text-gray-500">{t['teacher.classMaterials.subtitle']}</p>
      </div>

      {loadingFilters && <p className="text-sm text-gray-500">{t['teacher.classMaterials.loading']}</p>}

      {!loadingFilters && (
        <form onSubmit={handleUpload} className="flex flex-col gap-4">
          <section className="rounded-md bg-white p-5 shadow-sm">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-800">
              <span className="material-symbols-outlined !text-[18px] text-primary-500">folder_open</span>
              {t['teacher.classMaterials.sectionWhere']}
            </h2>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.selectSection']}</span>
                <select
                  className={selectClass}
                  value={sectionId ?? ''}
                  onChange={(event) => {
                    setSectionId(event.target.value ? Number(event.target.value) : null)
                    setSubjectId(null)
                    setBookId(null)
                    setChapterId(null)
                  }}
                >
                  <option value="">{t['teacher.classMaterials.selectSection']}</option>
                  {sections.map((row) => (
                    <option key={row.section_id} value={row.section_id}>
                      {sectionLabel(row)}
                    </option>
                  ))}
                </select>
              </label>

              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.selectSubject']}</span>
                <select
                  className={selectClass}
                  value={subjectId ?? ''}
                  disabled={sectionId === null}
                  onChange={(event) => {
                    setSubjectId(event.target.value ? Number(event.target.value) : null)
                    setBookId(null)
                    setChapterId(null)
                  }}
                >
                  <option value="">{t['teacher.classMaterials.selectSubject']}</option>
                  {subjectsForSection.map((row) => (
                    <option key={row.subject_id} value={row.subject_id}>
                      {row.subject_name}
                    </option>
                  ))}
                </select>
              </label>

              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.selectBook']}</span>
                <select
                  className={selectClass}
                  value={bookId ?? ''}
                  disabled={subjectId === null}
                  onChange={(event) => {
                    setBookId(event.target.value ? Number(event.target.value) : null)
                    setChapterId(null)
                  }}
                >
                  <option value="">{t['teacher.classMaterials.selectBook']}</option>
                  {books.map((book) => (
                    <option key={book.id} value={book.id}>
                      {book.bn_name ?? book.name}
                    </option>
                  ))}
                </select>
              </label>

              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.selectChapter']}</span>
                <select
                  className={selectClass}
                  value={chapterId ?? ''}
                  disabled={bookId === null}
                  onChange={(event) => setChapterId(event.target.value ? Number(event.target.value) : null)}
                >
                  <option value="">{t['teacher.classMaterials.selectChapter']}</option>
                  {chapters.map((chapter) => (
                    <option key={chapter.id} value={chapter.id}>
                      {chapter.bn_name ?? chapter.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </section>

          <section className="rounded-md bg-white p-5 shadow-sm">
            <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-800">
              <span className="material-symbols-outlined !text-[18px] text-primary-500">upload_file</span>
              {t['teacher.classMaterials.sectionDetails']}
            </h2>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.materialType']}</span>
                <select className={selectClass} value={type} onChange={(event) => setType(event.target.value as MaterialType)}>
                  <option value="primary">{t['teacher.classMaterials.typePrimary']}</option>
                  <option value="supplementary">{t['teacher.classMaterials.typeSupplementary']}</option>
                </select>
              </label>

              {type === 'supplementary' && (
                <label className={labelClass}>
                  <span className={labelTextClass}>{t['teacher.classMaterials.titleLabel']}</span>
                  <input
                    type="text"
                    placeholder={t['teacher.classMaterials.titlePlaceholder']}
                    className={selectClass}
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                  />
                </label>
              )}

              <label className={labelClass}>
                <span className={labelTextClass}>{t['teacher.classMaterials.orderIndex']}</span>
                <input
                  type="number"
                  min={0}
                  max={255}
                  className={selectClass}
                  value={orderIndex}
                  onChange={(event) => setOrderIndex(event.target.value)}
                />
              </label>
            </div>

            <div className="mt-4">
              <span className={`${labelTextClass} mb-1.5 block`}>{t['teacher.classMaterials.fileLabel']}</span>
              <FileDropZone file={file} onFileChange={setFile} label={t['teacher.classMaterials.fileDropLabel']} />
            </div>

            <div className="mt-4 flex justify-end">
              <button
                type="submit"
                disabled={uploading || sectionId === null || chapterId === null || !file}
                className="inline-flex h-11 items-center gap-2 cursor-pointer rounded-md bg-primary-500 px-5 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <span className="material-symbols-outlined !text-[18px]">cloud_upload</span>
                {uploading ? t['teacher.classMaterials.uploading'] : t['teacher.classMaterials.upload']}
              </button>
            </div>
          </section>
        </form>
      )}

      <section>
        <h2 className="mb-3 flex items-center gap-2 text-base font-semibold text-gray-800">
          <span className="material-symbols-outlined !text-[20px] text-primary-500">inventory_2</span>
          {t['teacher.classMaterials.myUploads']}
        </h2>

        {myUploads.length === 0 ? (
          <p className="rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm">{t['teacher.classMaterials.empty']}</p>
        ) : (
          <div className="overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[52rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colFile']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colChapter']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSubject']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSection']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colType']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colActions']}</th>
                </tr>
              </thead>
              <tbody>
                {myUploads.map((material) => (
                  <tr key={material.id} className="border-t border-gray-100 align-top">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      <span className="inline-flex items-center gap-1.5">
                        <span className="material-symbols-outlined !text-[18px] text-gray-400">{fileTypeIcon(material.original_filename)}</span>
                        {material.original_filename ?? '—'}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.name ?? '—'}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.book?.subject?.name ?? '—'}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{materialSectionLabel(material)}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {material.type === 'primary' ? t['teacher.classMaterials.typePrimary'] : t['teacher.classMaterials.typeSupplementary']}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <StatusBadge
                        status={material.status}
                        busy={statusTogglingId === material.id}
                        onClick={() => handleStatusToggle(material)}
                      />
                    </td>
                    <td className="px-5 py-3.5">
                      {editingId === material.id ? (
                        <div className="flex flex-col gap-2">
                          {material.type === 'supplementary' && (
                            <input
                              type="text"
                              placeholder={t['teacher.classMaterials.titleLabel']}
                              className="h-9 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                              value={editTitle}
                              onChange={(event) => setEditTitle(event.target.value)}
                            />
                          )}
                          <input
                            type="number"
                            min={0}
                            max={255}
                            placeholder={t['teacher.classMaterials.orderIndex']}
                            className="h-9 w-24 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                            value={editOrderIndex}
                            onChange={(event) => setEditOrderIndex(event.target.value)}
                          />
                          <div className="w-56">
                            <FileDropZone
                              file={editFile}
                              placeholderName={material.original_filename}
                              onFileChange={setEditFile}
                              label={t['teacher.classMaterials.fileDropLabel']}
                            />
                          </div>
                          <p className="text-xs text-gray-400">{t['teacher.classMaterials.replaceFileNote']}</p>
                          <div className="flex gap-2">
                            <button
                              type="button"
                              disabled={savingEdit}
                              onClick={() => handleSaveEdit(material.id)}
                              className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                              {savingEdit ? t['teacher.classMaterials.saving'] : t['teacher.classMaterials.save']}
                            </button>
                            <button
                              type="button"
                              onClick={cancelEdit}
                              className="cursor-pointer rounded-md border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-primary-500"
                            >
                              {t['teacher.classMaterials.cancel']}
                            </button>
                          </div>
                        </div>
                      ) : (
                        <div className="flex items-center gap-3">
                          <button
                            type="button"
                            disabled={viewingId === material.id || material.status !== 'active'}
                            onClick={() => handleView(material.id)}
                            aria-label={t['teacher.classMaterials.view']}
                            title={t['teacher.classMaterials.view']}
                            className="cursor-pointer text-gray-500 transition-colors hover:text-primary-500 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            <span className="material-symbols-outlined !text-[18px]">visibility</span>
                          </button>
                          <button
                            type="button"
                            onClick={() => startEdit(material)}
                            aria-label={t['teacher.classMaterials.edit']}
                            title={t['teacher.classMaterials.edit']}
                            className="cursor-pointer text-gray-500 transition-colors hover:text-primary-500"
                          >
                            <span className="material-symbols-outlined !text-[18px]">edit</span>
                          </button>
                          <button
                            type="button"
                            disabled={deletingId === material.id}
                            onClick={() => {
                              if (window.confirm(t['teacher.classMaterials.deleteConfirm'])) handleDelete(material.id)
                            }}
                            aria-label={t['teacher.classMaterials.delete']}
                            title={t['teacher.classMaterials.delete']}
                            className="cursor-pointer text-danger-500 transition-colors hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            <span className="material-symbols-outlined !text-[18px]">
                              {deletingId === material.id ? 'hourglass_top' : 'delete'}
                            </span>
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <ToastViewport toast={toast} />
    </div>
  )
}
