import { useEffect, useMemo, useState, type FormEvent } from 'react'
import {
  ApiError,
  createChapterMaterial,
  deleteChapterMaterial,
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

export default function ClassMaterialsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<ChapterMaterialAssignment[]>([])
  const [myUploads, setMyUploads] = useState<ChapterMaterial[]>([])
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
  const [editStatus, setEditStatus] = useState('active')
  const [editFile, setEditFile] = useState<File | null>(null)
  const [savingEdit, setSavingEdit] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  function refreshMyUploads() {
    if (!token) return
    listMyUploadedChapterMaterials(token)
      .then((result) => setMyUploads(result.data))
      .catch(() => setMyUploads([]))
  }

  useEffect(() => {
    if (!token) return

    Promise.all([listMyChapterMaterialAssignments(token), listMyUploadedChapterMaterials(token)])
      .then(([assignmentResult, uploadsResult]) => {
        setAssignments(assignmentResult.data)
        setMyUploads(uploadsResult.data)
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

  useEffect(() => {
    if (!selectedAssignment || selectedAssignment.class_version_id === null) {
      setBooks([])
      setBookId(null)
      return
    }

    let active = true
    listBooks({ classVersionId: selectedAssignment.class_version_id, subjectId: selectedAssignment.subject_id })
      .then((result) => {
        if (active) setBooks(result.data)
      })
      .catch(() => {
        if (active) setBooks([])
      })

    return () => {
      active = false
    }
  }, [selectedAssignment])

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
    formData.append('pdf', file)

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
    setEditStatus(material.status)
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
    formData.append('status', editStatus)
    if (editFile) formData.append('pdf', editFile)

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

  function sectionLabel(row: ChapterMaterialAssignment) {
    return [row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')
  }

  function materialSectionLabel(material: ChapterMaterial) {
    const row = assignments.find((assignment) => assignment.section_id === material.section_id)
    return row ? sectionLabel(row) : '—'
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-gray-900">{t['teacher.nav.classMaterials']}</h1>

      {loadingFilters && <p className="mt-4 text-sm text-gray-500">{t['teacher.classMaterials.loading']}</p>}

      {!loadingFilters && (
        <form className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm" onSubmit={handleUpload}>
          <select
            className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
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

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
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

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
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

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
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

          <select
            className="h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={type}
            onChange={(event) => setType(event.target.value as MaterialType)}
          >
            <option value="primary">{t['teacher.classMaterials.typePrimary']}</option>
            <option value="supplementary">{t['teacher.classMaterials.typeSupplementary']}</option>
          </select>

          {type === 'supplementary' && (
            <input
              type="text"
              placeholder={t['teacher.classMaterials.titlePlaceholder']}
              className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
            />
          )}

          <input
            type="number"
            min={0}
            max={255}
            placeholder={t['teacher.classMaterials.orderIndex']}
            className="h-11 w-24 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={orderIndex}
            onChange={(event) => setOrderIndex(event.target.value)}
          />

          <input
            type="file"
            accept="application/pdf"
            className="h-11 text-sm text-gray-700"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
          />

          <button
            type="submit"
            disabled={uploading || sectionId === null || chapterId === null || !file}
            className="h-11 cursor-pointer rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {uploading ? t['teacher.classMaterials.uploading'] : t['teacher.classMaterials.upload']}
          </button>
        </form>
      )}

      <h2 className="mt-8 text-base font-semibold text-gray-800">{t['teacher.classMaterials.myUploads']}</h2>

      {myUploads.length === 0 ? (
        <p className="mt-3 text-sm text-gray-500">{t['teacher.classMaterials.empty']}</p>
      ) : (
        <div className="mt-3 overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colChapter']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSection']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colType']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colFile']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colStatus']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colActions']}</th>
              </tr>
            </thead>
            <tbody>
              {myUploads.map((material) => (
                <tr key={material.id} className="border-t border-gray-100 align-top">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.book?.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{materialSectionLabel(material)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                    {material.type === 'primary' ? t['teacher.classMaterials.typePrimary'] : t['teacher.classMaterials.typeSupplementary']}
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.original_filename ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {editingId === material.id ? (
                      <select
                        className="h-9 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                        value={editStatus}
                        onChange={(event) => setEditStatus(event.target.value)}
                      >
                        <option value="active">{t['teacher.classMaterials.statusActive']}</option>
                        <option value="archived">{t['teacher.classMaterials.statusArchived']}</option>
                      </select>
                    ) : material.status === 'active' ? (
                      t['teacher.classMaterials.statusActive']
                    ) : (
                      t['teacher.classMaterials.statusArchived']
                    )}
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {editingId === material.id ? (
                      <div className="flex flex-col gap-2">
                        {material.type === 'supplementary' && (
                          <input
                            type="text"
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
                        <input
                          type="file"
                          accept="application/pdf"
                          className="text-xs text-gray-600"
                          onChange={(event) => setEditFile(event.target.files?.[0] ?? null)}
                        />
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
                      <div className="flex gap-3">
                        <button
                          type="button"
                          onClick={() => startEdit(material)}
                          className="cursor-pointer text-xs font-medium text-primary-500 hover:text-primary-400"
                        >
                          {t['teacher.classMaterials.edit']}
                        </button>
                        <button
                          type="button"
                          disabled={deletingId === material.id}
                          onClick={() => {
                            if (window.confirm(t['teacher.classMaterials.deleteConfirm'])) handleDelete(material.id)
                          }}
                          className="cursor-pointer text-xs font-medium text-danger-500 hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                          {deletingId === material.id ? t['teacher.classMaterials.deleting'] : t['teacher.classMaterials.delete']}
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

      <ToastViewport toast={toast} />
    </div>
  )
}
