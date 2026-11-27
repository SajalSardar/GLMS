import { DndContext, useDraggable, useDroppable, type DragEndEvent } from '@dnd-kit/core'
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import DOMPurify from 'dompurify'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  createQuestionPaper,
  getQuestionPaper,
  listAcademicQuestions,
  listChapters,
  listClasses,
  listSubjects,
  listVersions,
  updateQuestionPaper,
  type AcademicQuestion,
  type AcademicQuestionType,
  type Chapter,
  type SchoolClass,
  type Subject,
  type Version,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  removeCanvasItem,
  reorderCanvasItems,
  type CanvasItem,
} from './questionBuilderCanvas'

const t = getCommonStrings('en')

const selectClass =
  'h-11 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'
const buttonPrimary =
  'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60'
const buttonNeutral =
  'inline-flex h-11 cursor-pointer items-center rounded-md border border-gray-200 px-4 text-sm font-medium text-gray-600 transition-colors hover:border-primary-500'

// Question text is authored as rich HTML in the management portal's
// editor — Tailwind's preflight strips default list styling, so bullet/
// numbered lists need their own markers restored for display here too.
const richQuestionTextStyle = `
  .rich-question-text p { margin: 0 0 0.35em; }
  .rich-question-text p:last-child { margin-bottom: 0; }
  .rich-question-text ul { list-style: disc; padding-left: 1.25rem; }
  .rich-question-text ol { list-style: decimal; padding-left: 1.25rem; }
`

function BankQuestionCard({ question }: { question: AcademicQuestion }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: `bank-${question.id}`,
    data: { type: 'bank-question', question },
  })

  const style = transform ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` } : undefined

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      className={`cursor-grab rounded-md border border-gray-200 bg-white p-3 text-sm shadow-sm active:cursor-grabbing ${isDragging ? 'opacity-50' : ''}`}
    >
      <div className="rich-question-text" dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(question.question_text) }} />
      <p className="mt-1 text-xs text-gray-400">
        {question.question_type === 'cq' ? t['teacher.questionBuilder.cqLabel'] : t['teacher.questionBuilder.mcqLabel']} ·{' '}
        {question.marks}
      </p>
    </div>
  )
}

function CanvasItemCard({ item, index, onRemove }: { item: CanvasItem; index: number; onRemove: () => void }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.uid })
  const style = { transform: CSS.Transform.toString(transform), transition }

  if (item.type === 'heading') {
    return (
      <div
        ref={setNodeRef}
        style={style}
        {...attributes}
        {...listeners}
        className={`flex items-center justify-between rounded-md bg-gray-100 px-3 py-2 ${isDragging ? 'opacity-50' : ''}`}
      >
        <span className="text-sm font-semibold text-gray-700">{item.headingText}</span>
        {/* The card's own pointerdown is dnd-kit's drag-activation listener
            (spread via {...listeners} above) — without stopping it here
            first, every click on this button is captured as a (zero-
            distance) drag-and-drop instead of reaching onClick, so the
            button silently never removes anything. */}
        <button
          type="button"
          onPointerDown={(event) => event.stopPropagation()}
          onClick={onRemove}
          className="cursor-pointer text-danger-500 hover:text-danger-600"
        >
          <span className="material-symbols-outlined !text-[18px]">close</span>
        </button>
      </div>
    )
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      className={`flex items-start justify-between gap-3 rounded-md border border-gray-200 bg-white p-3 text-sm ${isDragging ? 'opacity-50' : ''}`}
    >
      <div>
        <p className="font-medium">{index + 1}.</p>
        <div
          className="rich-question-text"
          dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(item.question?.question_text ?? '') }}
        />
        <p className="mt-1 text-xs text-gray-400">
          {item.question?.marks} {t['teacher.questionBuilder.marksFilter']}
        </p>
      </div>
      <button
        type="button"
        onPointerDown={(event) => event.stopPropagation()}
        onClick={onRemove}
        className="shrink-0 cursor-pointer text-danger-500 hover:text-danger-600"
      >
        <span className="material-symbols-outlined !text-[18px]">close</span>
      </button>
    </div>
  )
}

function CanvasDropZone({ items, onRemove }: { items: CanvasItem[]; onRemove: (uid: string) => void }) {
  const { setNodeRef, isOver } = useDroppable({ id: 'canvas' })

  return (
    <div
      ref={setNodeRef}
      className={`flex min-h-[200px] flex-col gap-2 rounded-md border-2 border-dashed p-4 transition-colors ${
        isOver ? 'border-primary-500 bg-primary-50' : 'border-gray-200'
      }`}
    >
      {items.length === 0 && <p className="text-sm text-gray-400">{t['teacher.questionBuilder.dragHint']}</p>}

      <SortableContext items={items.map((item) => item.uid)} strategy={verticalListSortingStrategy}>
        {items.map((item, index) => (
          <CanvasItemCard key={item.uid} item={item} index={index} onRemove={() => onRemove(item.uid)} />
        ))}
      </SortableContext>
    </div>
  )
}

export default function QuestionBuilderPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()
  const { paperId } = useParams<{ paperId?: string }>()
  const navigate = useNavigate()
  const isEditing = Boolean(paperId)
  const [loadingPaper, setLoadingPaper] = useState(isEditing)

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [versions, setVersions] = useState<Version[]>([])

  const [filterClassId, setFilterClassId] = useState('')
  const [filterVersionId, setFilterVersionId] = useState('')
  const [filterSubjectId, setFilterSubjectId] = useState('')
  const [filterType, setFilterType] = useState('')
  const [filterMarks, setFilterMarks] = useState('')
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [selectedChapterIds, setSelectedChapterIds] = useState<Set<number>>(new Set())
  const [bankQuestions, setBankQuestions] = useState<AcademicQuestion[]>([])
  const [loadingBank, setLoadingBank] = useState(false)

  const [title, setTitle] = useState('')
  const [schoolClassId, setSchoolClassId] = useState('')
  const [subjectId, setSubjectId] = useState('')
  const [timeDuration, setTimeDuration] = useState('')
  const [fullMarks, setFullMarks] = useState('')
  const [instructions, setInstructions] = useState('')
  const [items, setItems] = useState<CanvasItem[]>([])
  const [headingDraft, setHeadingDraft] = useState('')
  const [addingHeading, setAddingHeading] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 }).then((result) => setClasses(result.data)).catch(() => setClasses([]))
    listSubjects({ perPage: 200 }).then((result) => setSubjects(result.data)).catch(() => setSubjects([]))

    // Defaults to Bangla (falling back to whatever version sorts first)
    // so the bank's version filter starts on the medium most schools use,
    // while staying a normal, changeable <select> — not locked to it.
    listVersions({ perPage: 10 })
      .then((result) => {
        setVersions(result.data)
        setFilterVersionId((previous) => previous || String(result.data.find((version) => version.name === 'Bangla')?.id ?? result.data[0]?.id ?? ''))
      })
      .catch(() => setVersions([]))
  }, [])

  // Narrows the bank by chapter once Class/Version/Subject are all picked
  // — same (class, version, subject) -> chapter cascade the management
  // portal's Question Bank page uses for its own chapter picker. Checking
  // none means "every chapter" (see loadBank()'s filter below); the
  // checked set is cleared whenever the cascade's own inputs change,
  // since a chapter checked under one subject has no meaning under
  // another.
  useEffect(() => {
    setSelectedChapterIds(new Set())

    if (!filterClassId || !filterVersionId || !filterSubjectId) {
      setChapters([])
      return
    }

    let active = true

    listChapters({ classId: Number(filterClassId), versionId: Number(filterVersionId), subjectId: Number(filterSubjectId) })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [filterClassId, filterVersionId, filterSubjectId])

  function toggleChapter(chapterId: number) {
    setSelectedChapterIds((previous) => {
      const next = new Set(previous)
      if (next.has(chapterId)) {
        next.delete(chapterId)
      } else {
        next.add(chapterId)
      }
      return next
    })
  }

  // Edit mode: populate every field (including the canvas) from the
  // existing paper. QuestionPaperItemResource's nested academic_question
  // already matches CanvasQuestionSummary exactly — no reshaping needed
  // beyond wrapping each item with a fresh client-side uid.
  useEffect(() => {
    if (!token || !paperId) return

    getQuestionPaper(Number(paperId), token)
      .then((result) => {
        const paper = result.data
        setTitle(paper.title)
        setSchoolClassId(String(paper.school_class_id))
        setSubjectId(String(paper.subject_id))
        setTimeDuration(paper.time_duration_minutes !== null ? String(paper.time_duration_minutes) : '')
        setFullMarks(paper.full_marks !== null ? String(paper.full_marks) : '')
        setInstructions(paper.instructions ?? '')
        setItems(
          (paper.items ?? []).map((item) => ({
            uid: crypto.randomUUID(),
            type: item.item_type,
            question: item.academic_question ?? undefined,
            headingText: item.heading_text ?? undefined,
          })),
        )
      })
      .catch(() => showToast({ type: 'error', message: t['teacher.questionBuilder.saveError'] }))
      .finally(() => setLoadingPaper(false))
  }, [token, paperId])

  function loadBank() {
    setLoadingBank(true)

    listAcademicQuestions(
      {
        schoolClassId: filterClassId ? Number(filterClassId) : undefined,
        versionId: filterVersionId ? Number(filterVersionId) : undefined,
        subjectId: filterSubjectId ? Number(filterSubjectId) : undefined,
        questionType: (filterType || undefined) as AcademicQuestionType | undefined,
        marks: filterMarks ? Number(filterMarks) : undefined,
      },
      token ?? undefined,
    )
      .then((result) =>
        setBankQuestions(
          // No chapter checked means "every chapter" (the pre-checkbox
          // behavior); the bank's own filters already narrowed the API
          // response, so this is a plain client-side narrow-further, not
          // a second fetch.
          selectedChapterIds.size === 0 ? result.data : result.data.filter((question) => selectedChapterIds.has(question.chapter_id)),
        ),
      )
      .catch(() => setBankQuestions([]))
      .finally(() => setLoadingBank(false))
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    if (!over) return

    const activeData = active.data.current as { type?: string; question?: AcademicQuestion } | undefined

    if (activeData?.type === 'bank-question' && activeData.question) {
      // Dropped a bank-question card onto the canvas — either its empty
      // drop zone or one of its existing sortable items both report
      // `over`, and either way the question is appended to the end;
      // reordering happens as a separate, later drag (the branch below).
      const isOverCanvas = over.id === 'canvas' || items.some((item) => item.uid === over.id)
      if (!isOverCanvas) return

      setItems((prev) => addQuestionToCanvas(prev, activeData.question as AcademicQuestion))
      return
    }

    setItems((prev) => reorderCanvasItems(prev, String(active.id), String(over.id)))
  }

  function confirmHeading() {
    setItems((prev) => addHeadingToCanvas(prev, headingDraft))
    setHeadingDraft('')
    setAddingHeading(false)
  }

  const computedFullMarks = items.reduce(
    (sum, item) => sum + (item.type === 'question' ? (item.question?.marks ?? 0) : 0),
    0,
  )

  async function handleSave() {
    if (!token || !schoolClassId || !subjectId || items.length === 0) return

    setSaving(true)

    const payload = {
      title,
      school_class_id: Number(schoolClassId),
      subject_id: Number(subjectId),
      time_duration_minutes: timeDuration ? Number(timeDuration) : null,
      full_marks: fullMarks ? Number(fullMarks) : computedFullMarks || null,
      instructions: instructions || null,
      items: items.map((item) =>
        item.type === 'question'
          ? { type: 'question' as const, academic_question_id: item.question?.id }
          : { type: 'heading' as const, heading_text: item.headingText },
      ),
    }

    try {
      if (isEditing && paperId) {
        await updateQuestionPaper(Number(paperId), payload, token)
        showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
        navigate('/my-question-papers')
        return
      }

      await createQuestionPaper(payload, token)

      showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
      setTitle('')
      setTimeDuration('')
      setFullMarks('')
      setInstructions('')
      setItems([])
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.questionBuilder.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  if (loadingPaper) {
    return <p className="px-4 py-6 text-sm text-gray-500 lg:px-6">{t['teacher.questionBuilder.loading']}</p>
  }

  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <style>{richQuestionTextStyle}</style>
      <h1 className="text-2xl font-bold text-primary-700">
        {isEditing ? t['teacher.questionBuilder.editTitle'] : t['teacher.questionBuilder.title']}
      </h1>

      <DndContext onDragEnd={handleDragEnd}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="flex flex-col gap-3 rounded-md bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.bankTitle']}</h2>

            <div className="flex flex-wrap gap-2">
              <select className={selectClass} value={filterClassId} onChange={(event) => setFilterClassId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectClass']}</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={filterVersionId} onChange={(event) => setFilterVersionId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectVersion']}</option>
                {versions.map((version) => (
                  <option key={version.id} value={version.id}>
                    {version.bn_name ?? version.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={filterSubjectId} onChange={(event) => setFilterSubjectId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectSubject']}</option>
                {subjects.map((subject) => (
                  <option key={subject.id} value={subject.id}>
                    {subject.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={filterType} onChange={(event) => setFilterType(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.allTypes']}</option>
                <option value="cq">{t['teacher.questionBuilder.cqLabel']}</option>
                <option value="mcq">{t['teacher.questionBuilder.mcqLabel']}</option>
              </select>
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.marksFilter']}
                className={`${selectClass} w-28`}
                value={filterMarks}
                onChange={(event) => setFilterMarks(event.target.value)}
              />
              <button type="button" onClick={loadBank} disabled={loadingBank} className={buttonPrimary}>
                {t['teacher.questionBuilder.loadQuestions']}
              </button>
            </div>

            {chapters.length > 0 && (
              <div className="flex flex-col gap-1.5 rounded-md border border-gray-200 p-3">
                <span className="text-xs font-semibold tracking-wide text-gray-500 uppercase">
                  {t['teacher.questionBuilder.chaptersLabel']}
                </span>
                <div className="flex max-h-32 flex-col gap-1 overflow-y-auto">
                  {chapters.map((chapter) => (
                    <label key={chapter.id} className="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={selectedChapterIds.has(chapter.id)}
                        onChange={() => toggleChapter(chapter.id)}
                        className="h-4 w-4 cursor-pointer accent-primary-500"
                      />
                      {chapter.bn_name ?? chapter.name}
                    </label>
                  ))}
                </div>
              </div>
            )}

            <div className="flex flex-col gap-2">
              {loadingBank && <p className="text-sm text-gray-500">{t['teacher.questionBuilder.loading']}</p>}
              {!loadingBank && bankQuestions.length === 0 && (
                <p className="text-sm text-gray-500">{t['teacher.questionBuilder.empty']}</p>
              )}
              {bankQuestions.map((question) => (
                <BankQuestionCard key={question.id} question={question} />
              ))}
            </div>
          </div>

          <div className="flex flex-col gap-3 rounded-md bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.paperTitle']}</h2>

            <input
              className={selectClass}
              placeholder={t['teacher.questionBuilder.titleLabel']}
              value={title}
              onChange={(event) => setTitle(event.target.value)}
            />

            <div className="flex gap-2">
              <select className={selectClass} value={schoolClassId} onChange={(event) => setSchoolClassId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectClass']}</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={subjectId} onChange={(event) => setSubjectId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectSubject']}</option>
                {subjects.map((subject) => (
                  <option key={subject.id} value={subject.id}>
                    {subject.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex gap-2">
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.timeDuration']}
                className={selectClass}
                value={timeDuration}
                onChange={(event) => setTimeDuration(event.target.value)}
              />
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.fullMarks']}
                className={selectClass}
                value={fullMarks}
                onChange={(event) => setFullMarks(event.target.value)}
              />
            </div>

            <textarea
              className={`${selectClass} h-auto py-2`}
              rows={2}
              placeholder={t['teacher.questionBuilder.instructions']}
              value={instructions}
              onChange={(event) => setInstructions(event.target.value)}
            />

            <CanvasDropZone items={items} onRemove={(uid) => setItems((prev) => removeCanvasItem(prev, uid))} />

            {addingHeading ? (
              <div className="flex gap-2">
                <input
                  className={selectClass}
                  placeholder={t['teacher.questionBuilder.headingPlaceholder']}
                  value={headingDraft}
                  onChange={(event) => setHeadingDraft(event.target.value)}
                />
                <button type="button" onClick={confirmHeading} className={buttonPrimary}>
                  {t['teacher.questionBuilder.addHeading']}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setAddingHeading(false)
                    setHeadingDraft('')
                  }}
                  className={buttonNeutral}
                >
                  {t['teacher.questionBuilder.cancelHeading']}
                </button>
              </div>
            ) : (
              <button type="button" onClick={() => setAddingHeading(true)} className={buttonNeutral}>
                {t['teacher.questionBuilder.insertHeading']}
              </button>
            )}

            <button
              type="button"
              onClick={handleSave}
              disabled={saving || !schoolClassId || !subjectId || items.length === 0}
              className={buttonPrimary}
            >
              {saving
                ? isEditing
                  ? t['teacher.questionBuilder.updating']
                  : t['teacher.questionBuilder.saving']
                : isEditing
                  ? t['teacher.questionBuilder.update']
                  : t['teacher.questionBuilder.save']}
            </button>
          </div>
        </div>
      </DndContext>

      <ToastViewport toast={toast} />
    </section>
  )
}
