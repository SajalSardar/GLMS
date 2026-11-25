import { useEffect, useState, type ReactNode } from 'react'
import {
  ApiError,
  createExam,
  deleteExam,
  getExam,
  listAcademicYears,
  listChapters,
  listExams,
  listMyChapterMaterialAssignments,
  listQuestions,
  randomizeExam,
  setExamQuestions,
  setExamRandomChapters,
  updateExam,
  type AcademicYear,
  type Chapter,
  type ChapterMaterialAssignment,
  type Exam,
  type ExamSelectionMode,
  type Question,
  type QuestionDifficulty,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import DateTimePickerField from '../components/DateTimePickerField'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

const selectClass =
  'h-11 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'
const buttonPrimary =
  'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60'
const buttonNeutral =
  'inline-flex h-11 cursor-pointer items-center rounded-md border border-gray-200 px-4 text-sm font-medium text-gray-600 transition-colors hover:border-primary-500'

// Mirrors GeoField in the management portal's GeoCrudTable — same label +
// required-asterisk + error-message shape, kept local here since that
// component lives only in the management app, not a shared package.
function Field({
  label,
  required,
  error,
  children,
}: {
  label: string
  required?: boolean
  error?: string
  children: ReactNode
}) {
  return (
    <label className="flex flex-col gap-1.5 text-sm">
      <span className="font-medium text-gray-700">
        {label}
        {required && <span className="text-danger-500"> *</span>}
      </span>
      {children}
      {error && (
        <span className="text-xs text-danger-500" role="alert">
          {error}
        </span>
      )}
    </label>
  )
}

/**
 * First row carrying each distinct (class_version_id, subject_id) pair —
 * same technique ClassMaterialsPage's uniqueByKey uses to turn a flat
 * assignment list into picker options without a dedicated endpoint.
 */
function uniqueAssignments(items: ChapterMaterialAssignment[]): ChapterMaterialAssignment[] {
  const seen = new Set<string>()
  const result: ChapterMaterialAssignment[] = []

  for (const item of items) {
    if (item.class_version_id === null || item.subject_id === null) continue
    const key = `${item.class_version_id}:${item.subject_id}`
    if (seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

function assignmentLabel(assignment: ChapterMaterialAssignment): string {
  return `${assignment.class_name ?? ''} — ${assignment.class_version ?? ''} — ${assignment.subject_name}`
}

function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? error.message ?? fallback
  }

  return fallback
}

function useAssignmentChapters(classId: number | null, subjectId: number | null): Chapter[] {
  const [chapters, setChapters] = useState<Chapter[]>([])

  useEffect(() => {
    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    listChapters({ classId, subjectId })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  return chapters
}

function ManageExamQuestionsModal({ examId, onClose }: { examId: number; onClose: () => void }) {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [exam, setExam] = useState<Exam | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [attachedIds, setAttachedIds] = useState<number[]>([])
  const [selectedChapterIds, setSelectedChapterIds] = useState<number[]>([])
  const [chapterId, setChapterId] = useState('')
  const [availableQuestions, setAvailableQuestions] = useState<Question[]>([])

  useEffect(() => {
    if (!token) return

    setLoading(true)
    getExam(examId, token)
      .then((result) => {
        const loaded = result.data
        setExam(loaded)
        setAttachedIds((loaded.questions ?? []).slice().sort((a, b) => a.order_index - b.order_index).map((link) => link.question.id))
        setSelectedChapterIds((loaded.random_chapters ?? []).map((chapter) => chapter.id))
      })
      .catch(() => setExam(null))
      .finally(() => setLoading(false))
  }, [examId, token])

  const classId = exam?.class_version?.class_id ?? null
  const subjectId = exam?.subject_id ?? null
  const chapters = useAssignmentChapters(classId, subjectId)

  useEffect(() => {
    if (!chapterId) {
      setAvailableQuestions([])
      return
    }

    let active = true
    listQuestions({ chapterId: Number(chapterId) }, token ?? undefined)
      .then((result) => {
        if (active) setAvailableQuestions(result.data)
      })
      .catch(() => {
        if (active) setAvailableQuestions([])
      })

    return () => {
      active = false
    }
  }, [chapterId, token])

  function moveAttached(index: number, direction: -1 | 1) {
    setAttachedIds((prev) => {
      const next = [...prev]
      const target = index + direction
      if (target < 0 || target >= next.length) return prev
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  async function saveQuestions() {
    if (!token) return

    setSaving(true)
    try {
      const result = await setExamQuestions(examId, attachedIds, token)
      setExam(result.data)
      showToast({ type: 'success', message: t['teacher.classExams.saveSuccess'] })
    } catch (error) {
      showToast({ type: 'error', message: messageFromApiError(error, t['teacher.classExams.saveError']) })
    } finally {
      setSaving(false)
    }
  }

  async function saveChapters() {
    if (!token) return

    setSaving(true)
    try {
      const result = await setExamRandomChapters(examId, selectedChapterIds, token)
      setExam(result.data)
      showToast({ type: 'success', message: t['teacher.classExams.saveSuccess'] })
    } catch (error) {
      showToast({ type: 'error', message: messageFromApiError(error, t['teacher.classExams.saveError']) })
    } finally {
      setSaving(false)
    }
  }

  async function draw() {
    if (!token) return

    setSaving(true)
    try {
      const result = await randomizeExam(examId, token)
      setExam(result.data)
      setAttachedIds((result.data.questions ?? []).map((link) => link.question.id))
      showToast({ type: 'success', message: t['teacher.classExams.drawSuccess'] })
    } catch (error) {
      showToast({ type: 'error', message: messageFromApiError(error, t['teacher.classExams.drawError']) })
    } finally {
      setSaving(false)
    }
  }

  const attachedQuestions = exam?.questions ?? []
  const attachedById = new Map(attachedQuestions.map((link) => [link.question.id, link.question]))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">
            {t['teacher.classExams.manageQuestions']}
            {exam ? ` — ${exam.name}` : ''}
          </h3>
          <button type="button" className="cursor-pointer text-xl leading-none text-gray-400 hover:text-gray-600" onClick={onClose}>
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 overflow-y-auto p-5">
          {loading && <p className="text-sm text-gray-500">{t['teacher.classExams.loading']}</p>}

          {!loading && exam && exam.selection_mode === 'manual' && (
            <>
              <div>
                <p className="mb-2 text-sm font-medium text-gray-700">{t['teacher.classExams.attachedQuestions']}</p>
                {attachedIds.length === 0 && <p className="text-sm text-gray-500">{t['teacher.classExams.noAttachedQuestions']}</p>}
                <ul className="flex flex-col gap-2">
                  {attachedIds.map((id, index) => {
                    const question = attachedById.get(id)
                    return (
                      <li key={id} className="flex items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm">
                        <span className="flex-1">{question?.question_text ?? `#${id}`}</span>
                        <div className="flex items-center gap-1">
                          <button type="button" className="text-gray-400 hover:text-gray-700" onClick={() => moveAttached(index, -1)}>
                            <span className="material-symbols-outlined !text-[18px]">arrow_upward</span>
                          </button>
                          <button type="button" className="text-gray-400 hover:text-gray-700" onClick={() => moveAttached(index, 1)}>
                            <span className="material-symbols-outlined !text-[18px]">arrow_downward</span>
                          </button>
                          <button
                            type="button"
                            className="text-danger-500 hover:text-danger-600"
                            onClick={() => setAttachedIds((prev) => prev.filter((existing) => existing !== id))}
                          >
                            <span className="material-symbols-outlined !text-[18px]">close</span>
                          </button>
                        </div>
                      </li>
                    )
                  })}
                </ul>
              </div>

              <select className={selectClass} value={chapterId} onChange={(event) => setChapterId(event.target.value)}>
                <option value="">{t['teacher.classExams.selectChapter']}</option>
                {chapters.map((chapter) => (
                  <option key={chapter.id} value={chapter.id}>
                    {chapter.name}
                  </option>
                ))}
              </select>

              {chapterId && (
                <ul className="flex flex-col gap-1.5">
                  {availableQuestions.length === 0 && <p className="text-sm text-gray-500">{t['teacher.classExams.empty']}</p>}
                  {availableQuestions.map((question) => (
                    <li key={question.id} className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={attachedIds.includes(question.id)}
                        onChange={(event) =>
                          setAttachedIds((prev) =>
                            event.target.checked ? [...prev, question.id] : prev.filter((id) => id !== question.id),
                          )
                        }
                      />
                      {question.question_text}
                    </li>
                  ))}
                </ul>
              )}

              <button type="button" className={buttonPrimary} onClick={saveQuestions} disabled={saving}>
                {saving ? t['teacher.classExams.saving'] : t['teacher.classExams.save']}
              </button>
            </>
          )}

          {!loading && exam && exam.selection_mode !== 'manual' && (
            <>
              <p className="text-sm text-gray-600">
                {t['teacher.classExams.randomSummary']
                  .replace('{count}', String(exam.random_question_count ?? 0))
                  .replace('{difficulty}', exam.random_difficulty ?? t['teacher.classExams.anyDifficulty'])}
              </p>

              <div>
                <p className="mb-2 text-sm font-medium text-gray-700">{t['teacher.classExams.randomChapters']}</p>
                <p className="mb-2 text-xs text-gray-500">{t['teacher.classExams.randomChaptersHint']}</p>
                <ul className="flex flex-col gap-1.5">
                  {chapters.map((chapter) => (
                    <li key={chapter.id} className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={selectedChapterIds.includes(chapter.id)}
                        onChange={(event) =>
                          setSelectedChapterIds((prev) =>
                            event.target.checked ? [...prev, chapter.id] : prev.filter((id) => id !== chapter.id),
                          )
                        }
                      />
                      {chapter.name}
                    </li>
                  ))}
                </ul>
              </div>

              <div className="flex gap-3">
                <button type="button" className={buttonNeutral} onClick={saveChapters} disabled={saving}>
                  {saving ? t['teacher.classExams.saving'] : t['teacher.classExams.saveChapters']}
                </button>

                {exam.selection_mode === 'fixed_random' && (
                  <button type="button" className={buttonPrimary} onClick={draw} disabled={saving}>
                    {t['teacher.classExams.drawNow']}
                  </button>
                )}
              </div>

              {exam.selection_mode === 'fixed_random' && attachedQuestions.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-medium text-gray-700">{t['teacher.classExams.attachedQuestions']}</p>
                  <ul className="flex flex-col gap-1.5">
                    {attachedQuestions.map((link) => (
                      <li key={link.id} className="text-sm text-gray-700">
                        {link.question.question_text}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>

        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
          <button type="button" className={buttonNeutral} onClick={onClose}>
            {t['teacher.classExams.close']}
          </button>
        </div>
      </div>

      <ToastViewport toast={toast} />
    </div>
  )
}

export default function ClassExamsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<ChapterMaterialAssignment[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])
  const [exams, setExams] = useState<Exam[]>([])
  const [loading, setLoading] = useState(true)

  const [formOpen, setFormOpen] = useState(false)
  const [editingExam, setEditingExam] = useState<Exam | null>(null)
  const [manageExamId, setManageExamId] = useState<number | null>(null)

  const [assignmentKey, setAssignmentKey] = useState('')
  const [academicYearId, setAcademicYearId] = useState('')
  const [name, setName] = useState('')
  const [bnName, setBnName] = useState('')
  const [selectionMode, setSelectionMode] = useState<ExamSelectionMode>('manual')
  const [timeLimit, setTimeLimit] = useState('')
  const [maxAttempts, setMaxAttempts] = useState('')
  const [passingScore, setPassingScore] = useState('')
  const [availableFrom, setAvailableFrom] = useState('')
  const [availableUntil, setAvailableUntil] = useState('')
  const [randomCount, setRandomCount] = useState('')
  const [randomDifficulty, setRandomDifficulty] = useState('')
  const [marksPerQuestion, setMarksPerQuestion] = useState('')
  const [status, setStatus] = useState<'draft' | 'published'>('draft')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  function loadExams() {
    if (!token) return

    setLoading(true)
    listExams({}, token)
      .then((result) => setExams(result.data))
      .catch(() => setExams([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    if (!token) return

    listMyChapterMaterialAssignments(token)
      .then((result) => setAssignments(uniqueAssignments(result.data)))
      .catch(() => setAssignments([]))

    loadExams()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  useEffect(() => {
    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  function openCreateForm() {
    setEditingExam(null)
    setAssignmentKey('')
    setAcademicYearId('')
    setName('')
    setBnName('')
    setSelectionMode('manual')
    setTimeLimit('')
    setMaxAttempts('')
    setPassingScore('')
    setAvailableFrom('')
    setAvailableUntil('')
    setRandomCount('')
    setRandomDifficulty('')
    setMarksPerQuestion('')
    setStatus('draft')
    setFormError(null)
    setFormOpen(true)
  }

  function openEditForm(exam: Exam) {
    setEditingExam(exam)
    setAssignmentKey(exam.class_version_id && exam.subject_id ? `${exam.class_version_id}:${exam.subject_id}` : '')
    setAcademicYearId(String(exam.academic_year_id))
    setName(exam.name)
    setBnName(exam.bn_name ?? '')
    setSelectionMode(exam.selection_mode)
    setTimeLimit(exam.time_limit_minutes !== null ? String(exam.time_limit_minutes) : '')
    setMaxAttempts(exam.max_attempts !== null ? String(exam.max_attempts) : '')
    setPassingScore(exam.passing_score_percent !== null ? String(exam.passing_score_percent) : '')
    setAvailableFrom(exam.available_from ?? '')
    setAvailableUntil(exam.available_until ?? '')
    setRandomCount(exam.random_question_count !== null ? String(exam.random_question_count) : '')
    setRandomDifficulty(exam.random_difficulty ?? '')
    setMarksPerQuestion(exam.marks_per_question !== null ? String(exam.marks_per_question) : '')
    setStatus(exam.status === 'published' ? 'published' : 'draft')
    setFormError(null)
    setFormOpen(true)
  }

  async function handleSubmit() {
    if (!token) return

    const [classVersionId, subjectId] = assignmentKey.split(':').map(Number)

    setSaving(true)
    setFormError(null)

    try {
      if (editingExam) {
        await updateExam(
          editingExam.id,
          {
            name,
            bn_name: bnName || null,
            time_limit_minutes: timeLimit ? Number(timeLimit) : null,
            max_attempts: maxAttempts ? Number(maxAttempts) : null,
            passing_score_percent: passingScore ? Number(passingScore) : null,
            available_from: availableFrom || null,
            available_until: availableUntil || null,
            random_question_count: randomCount ? Number(randomCount) : null,
            random_difficulty: (randomDifficulty as QuestionDifficulty) || null,
            marks_per_question: marksPerQuestion ? Number(marksPerQuestion) : null,
            status,
          },
          token,
        )
      } else {
        await createExam(
          {
            class_version_id: classVersionId,
            subject_id: subjectId,
            academic_year_id: Number(academicYearId),
            name,
            bn_name: bnName || null,
            selection_mode: selectionMode,
            time_limit_minutes: timeLimit ? Number(timeLimit) : null,
            max_attempts: maxAttempts ? Number(maxAttempts) : null,
            passing_score_percent: passingScore ? Number(passingScore) : null,
            available_from: availableFrom || null,
            available_until: availableUntil || null,
            random_question_count: randomCount ? Number(randomCount) : null,
            random_difficulty: (randomDifficulty as QuestionDifficulty) || null,
            marks_per_question: marksPerQuestion ? Number(marksPerQuestion) : null,
          },
          token,
        )
      }

      setFormOpen(false)
      showToast({ type: 'success', message: t['teacher.classExams.saveSuccess'] })
      loadExams()
    } catch (error) {
      setFormError(messageFromApiError(error, t['teacher.classExams.saveError']))
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(exam: Exam) {
    if (!token || !window.confirm(t['teacher.classExams.deleteConfirm'])) return

    try {
      await deleteExam(exam.id, token)
      showToast({ type: 'success', message: t['teacher.classExams.deleteSuccess'] })
      loadExams()
    } catch (error) {
      showToast({ type: 'error', message: messageFromApiError(error, t['teacher.classExams.deleteError']) })
    }
  }

  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-primary-700">{t['teacher.classExams.title']}</h1>
        <button type="button" className={buttonPrimary} onClick={openCreateForm}>
          <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
          {t['teacher.classExams.addNew']}
        </button>
      </div>

      {loading && <p className="text-sm text-gray-500">{t['teacher.classExams.loading']}</p>}

      {!loading && exams.length === 0 && (
        <p className="rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm">{t['teacher.classExams.empty']}</p>
      )}

      {!loading && exams.length > 0 && (
        <div className="overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classExams.colName']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classExams.colMode']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classExams.colStatus']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classExams.colActions']}</th>
              </tr>
            </thead>
            <tbody>
              {exams.map((exam) => (
                <tr key={exam.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{exam.name}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{t[`teacher.classExams.mode.${exam.selection_mode}`]}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <span
                      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        exam.status === 'published' ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
                      }`}
                    >
                      {exam.status === 'published' ? t['teacher.classExams.published'] : t['teacher.classExams.draft']}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        className="cursor-pointer text-gray-500 hover:text-primary-500"
                        onClick={() => setManageExamId(exam.id)}
                        aria-label={t['teacher.classExams.manageQuestions']}
                        title={t['teacher.classExams.manageQuestions']}
                      >
                        <span className="material-symbols-outlined !text-[18px]">checklist</span>
                      </button>
                      <button
                        type="button"
                        className="cursor-pointer text-gray-500 hover:text-primary-500"
                        onClick={() => openEditForm(exam)}
                        aria-label={t['teacher.classExams.edit']}
                        title={t['teacher.classExams.edit']}
                      >
                        <span className="material-symbols-outlined !text-[18px]">edit</span>
                      </button>
                      {exam.status !== 'published' && (
                        <button
                          type="button"
                          className="cursor-pointer text-danger-500 hover:text-danger-600"
                          onClick={() => handleDelete(exam)}
                          aria-label={t['teacher.classExams.delete']}
                          title={t['teacher.classExams.delete']}
                        >
                          <span className="material-symbols-outlined !text-[18px]">delete</span>
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {formOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5">
          <div className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-xl flex-col overflow-hidden rounded-md bg-white shadow-2xl" role="dialog" aria-modal="true">
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
              <h3 className="text-base font-semibold text-gray-800">
                {editingExam ? t['teacher.classExams.edit'] : t['teacher.classExams.addNew']}
              </h3>
              <button type="button" className="cursor-pointer text-xl leading-none text-gray-400 hover:text-gray-600" onClick={() => setFormOpen(false)}>
                ×
              </button>
            </div>

            <div className="flex flex-col gap-4 overflow-y-auto p-5">
              {editingExam ? (
                <Field label={t['teacher.classExams.colClassSubject']}>
                  <input
                    className={`${selectClass} cursor-not-allowed opacity-60`}
                    value={`${editingExam.class_version?.name ?? ''} — ${editingExam.subject?.name ?? ''}`}
                    disabled
                  />
                </Field>
              ) : (
                <Field label={t['teacher.classExams.colClassSubject']} required>
                  <select className={selectClass} value={assignmentKey} onChange={(event) => setAssignmentKey(event.target.value)}>
                    <option value="">{t['teacher.classExams.selectClassSubject']}</option>
                    {assignments.map((assignment) => (
                      <option key={`${assignment.class_version_id}:${assignment.subject_id}`} value={`${assignment.class_version_id}:${assignment.subject_id}`}>
                        {assignmentLabel(assignment)}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              {!editingExam && (
                <Field label={t['management.books.academicYear']} required>
                  <select className={selectClass} value={academicYearId} onChange={(event) => setAcademicYearId(event.target.value)}>
                    <option value="">{t['management.books.academicYear']}</option>
                    {academicYears.map((year) => (
                      <option key={year.id} value={year.id}>
                        {year.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              {!editingExam && (
                <Field label={t['teacher.classExams.colMode']} required>
                  <select className={selectClass} value={selectionMode} onChange={(event) => setSelectionMode(event.target.value as ExamSelectionMode)}>
                    <option value="manual">{t['teacher.classExams.mode.manual']}</option>
                    <option value="fixed_random">{t['teacher.classExams.mode.fixed_random']}</option>
                    <option value="per_attempt_random">{t['teacher.classExams.mode.per_attempt_random']}</option>
                  </select>
                </Field>
              )}

              {editingExam && (
                <Field label={t['teacher.classExams.colStatus']}>
                  <select className={selectClass} value={status} onChange={(event) => setStatus(event.target.value as 'draft' | 'published')}>
                    <option value="draft">{t['teacher.classExams.draft']}</option>
                    <option value="published">{t['teacher.classExams.published']}</option>
                  </select>
                </Field>
              )}

              <Field label={t['management.fields.name']} required>
                <input className={selectClass} value={name} onChange={(event) => setName(event.target.value)} />
              </Field>

              <Field label={t['management.fields.bnName']}>
                <input className={selectClass} value={bnName} onChange={(event) => setBnName(event.target.value)} />
              </Field>

              <Field label={t['management.exams.timeLimit']}>
                <input type="number" min={1} className={selectClass} value={timeLimit} onChange={(event) => setTimeLimit(event.target.value)} />
              </Field>

              <Field label={t['management.exams.maxAttempts']}>
                <input type="number" min={1} className={selectClass} value={maxAttempts} onChange={(event) => setMaxAttempts(event.target.value)} />
              </Field>

              <Field label={t['management.exams.passingScore']}>
                <input type="number" min={0} max={100} className={selectClass} value={passingScore} onChange={(event) => setPassingScore(event.target.value)} />
              </Field>

              <Field label={t['management.exams.availableFrom']}>
                <DateTimePickerField
                  value={availableFrom}
                  onChange={setAvailableFrom}
                  placeholder={t['management.fields.selectDate']}
                  todayLabel={t['management.table.today']}
                  clearLabel={t['management.table.clear']}
                />
              </Field>

              <Field label={t['management.exams.availableUntil']}>
                <DateTimePickerField
                  value={availableUntil}
                  onChange={setAvailableUntil}
                  // StoreExamRequest/UpdateExamRequest enforce
                  // 'after:available_from' on the full datetime; this only
                  // narrows the day (DateTimePickerField has no notion of
                  // the paired time input when bounding the calendar), so
                  // picking the same day as available_from but an earlier
                  // time still only gets caught server-side.
                  minDate={availableFrom ? availableFrom.split('T')[0] : undefined}
                  placeholder={t['management.fields.selectDate']}
                  todayLabel={t['management.table.today']}
                  clearLabel={t['management.table.clear']}
                />
              </Field>

              {selectionMode !== 'manual' && (
                <>
                  <Field label={t['management.exams.randomCount']} required>
                    <input type="number" min={1} className={selectClass} value={randomCount} onChange={(event) => setRandomCount(event.target.value)} />
                  </Field>

                  <Field label={t['management.exams.randomDifficulty']}>
                    <select className={selectClass} value={randomDifficulty} onChange={(event) => setRandomDifficulty(event.target.value)}>
                      <option value="">{t['management.exams.anyDifficulty']}</option>
                      <option value="easy">{t['management.examQuestions.difficulty.easy']}</option>
                      <option value="medium">{t['management.examQuestions.difficulty.medium']}</option>
                      <option value="hard">{t['management.examQuestions.difficulty.hard']}</option>
                    </select>
                  </Field>
                </>
              )}

              {selectionMode === 'per_attempt_random' && (
                <Field label={t['management.exams.marksPerQuestion']} required>
                  <input type="number" min={1} className={selectClass} value={marksPerQuestion} onChange={(event) => setMarksPerQuestion(event.target.value)} />
                </Field>
              )}

              {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
            </div>

            <div className="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
              <button type="button" className={buttonNeutral} onClick={() => setFormOpen(false)} disabled={saving}>
                {t['teacher.classExams.cancel']}
              </button>
              <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={saving || (!editingExam && !assignmentKey)}>
                {saving ? t['teacher.classExams.saving'] : t['teacher.classExams.save']}
              </button>
            </div>
          </div>
        </div>
      )}

      {manageExamId !== null && <ManageExamQuestionsModal examId={manageExamId} onClose={() => setManageExamId(null)} />}

      <ToastViewport toast={toast} />
    </section>
  )
}
