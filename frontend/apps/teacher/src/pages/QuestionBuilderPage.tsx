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
import RichTextEditor from '../components/RichTextEditor'
import { useToast, ToastViewport } from '../components/Toast'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  addTextToCanvas,
  indentItem,
  outdentItem,
  removeCanvasItem,
  reorderCanvasItems,
  setQuestionMarks,
  setQuestionOption,
  setQuestionSerialLabel,
  setQuestionText,
  type CanvasItem,
} from './questionBuilderCanvas'

const t = getCommonStrings('en')

const selectClass =
  'h-11 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'
const buttonPrimary =
  'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60'
const buttonNeutral =
  'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md border border-gray-200 px-4 text-sm font-medium text-gray-600 transition-colors hover:border-primary-500'
const iconButton =
  'flex h-7 w-7 cursor-pointer items-center justify-center rounded text-gray-500 transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-30'

// Question text is authored as rich HTML — Tailwind's preflight strips
// default list styling, so bullet/numbered lists need their own markers
// restored for display here (the bank card's read-only preview; the
// canvas's own copy is edited live through RichTextEditor instead).
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

// A small, unobtrusive remove button shared by every canvas item card —
// stopping pointerdown here is required: the card's own pointerdown is
// dnd-kit's drag-activation listener (spread via {...listeners} on the
// card), so without stopping it first, every click on this button (or
// any editable field inside the card) is captured as a (zero-distance)
// drag-and-drop instead of reaching its own handler.
function RemoveButton({ onRemove, className }: { onRemove: () => void; className?: string }) {
  return (
    <button
      type="button"
      onPointerDown={(event) => event.stopPropagation()}
      onClick={onRemove}
      className={`cursor-pointer text-danger-500 hover:text-danger-600 ${className ?? ''}`}
    >
      <span className="material-symbols-outlined !text-[18px]">close</span>
    </button>
  )
}

function IndentControls({ onIndent, onOutdent, indentLevel }: { onIndent: () => void; onOutdent: () => void; indentLevel: number }) {
  return (
    <div className="flex items-center" onPointerDown={(event) => event.stopPropagation()}>
      <button
        type="button"
        onClick={onOutdent}
        disabled={indentLevel === 0}
        aria-label={t['teacher.questionBuilder.outdent']}
        className={iconButton}
      >
        <span className="material-symbols-outlined !text-[18px]">format_indent_decrease</span>
      </button>
      <button type="button" onClick={onIndent} aria-label={t['teacher.questionBuilder.indent']} className={iconButton}>
        <span className="material-symbols-outlined !text-[18px]">format_indent_increase</span>
      </button>
    </div>
  )
}

function CanvasItemCard({
  item,
  index,
  onRemove,
  onMarksChange,
  onSerialChange,
  onQuestionTextChange,
  onOptionChange,
  onIndent,
  onOutdent,
}: {
  item: CanvasItem
  index: number
  onRemove: () => void
  onMarksChange: (value: string) => void
  onSerialChange: (value: string) => void
  onQuestionTextChange: (html: string) => void
  onOptionChange: (optionIndex: number, value: string) => void
  onIndent: () => void
  onOutdent: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.uid })
  const style = { transform: CSS.Transform.toString(transform), transition, marginLeft: `${item.indentLevel * 1.5}rem` }

  // Headings always render centered — a real exam paper's section
  // headings (গদ্য, ব্যাকরণ, ...) are centered, never left-aligned — so
  // the remove/indent controls are overlaid via absolute positioning
  // rather than sharing a flex row with the heading text, which would
  // pull the text off-center toward whichever side has more room.
  if (item.type === 'heading') {
    return (
      <div
        ref={setNodeRef}
        style={style}
        {...attributes}
        {...listeners}
        className={`relative flex items-center justify-center rounded-md bg-gray-100 px-3 py-2 ${isDragging ? 'opacity-50' : ''}`}
      >
        <span className="text-center text-sm font-semibold text-gray-700">{item.text}</span>
        <div className="absolute right-3 flex items-center gap-1">
          <IndentControls indentLevel={item.indentLevel} onIndent={onIndent} onOutdent={onOutdent} />
          <RemoveButton onRemove={onRemove} />
        </div>
      </div>
    )
  }

  // A plain inserted text block — e.g. a note or extra instruction
  // between questions — reads like ordinary paper text: left-aligned,
  // normal weight, no section-heading styling and no question numbering.
  if (item.type === 'text') {
    return (
      <div
        ref={setNodeRef}
        style={style}
        {...attributes}
        {...listeners}
        className={`flex items-start justify-between gap-3 rounded-md border border-gray-200 bg-white p-3 text-sm text-gray-700 ${isDragging ? 'opacity-50' : ''}`}
      >
        <p>{item.text}</p>
        <div className="flex shrink-0 items-center gap-1">
          <IndentControls indentLevel={item.indentLevel} onIndent={onIndent} onOutdent={onOutdent} />
          <RemoveButton onRemove={onRemove} />
        </div>
      </div>
    )
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      className={`flex flex-col gap-2 rounded-md border border-gray-200 bg-white p-3 text-sm ${isDragging ? 'opacity-50' : ''}`}
    >
      <div className="flex items-center justify-between gap-2">
        <div
          className="flex min-w-0 items-center gap-2"
          onPointerDown={(event) => event.stopPropagation()}
          onKeyDown={(event) => event.stopPropagation()}
        >
          <input
            type="text"
            value={item.serialLabel ?? String(index + 1)}
            onChange={(event) => onSerialChange(event.target.value)}
            className="w-14 rounded border border-gray-200 px-1.5 py-0.5 text-sm font-medium outline-none focus:border-primary-500"
          />
          <span className="font-medium">.</span>
          <span className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 uppercase">
            {item.questionType === 'mcq' ? t['teacher.questionBuilder.mcqLabel'] : t['teacher.questionBuilder.cqLabel']}
          </span>
          <div className="flex items-center gap-1.5 text-xs text-gray-400">
            <input
              type="number"
              min={0}
              value={item.marks ?? ''}
              onChange={(event) => onMarksChange(event.target.value)}
              className="w-16 rounded border border-gray-200 px-1.5 py-0.5 text-xs outline-none focus:border-primary-500"
            />
            <span>{t['teacher.questionBuilder.marksFilter']}</span>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <IndentControls indentLevel={item.indentLevel} onIndent={onIndent} onOutdent={onOutdent} />
          <RemoveButton onRemove={onRemove} />
        </div>
      </div>

      {/* dnd-kit's keyboard sensor activates a drag on Space/Enter for
         ANY keydown that bubbles up to this card's {...listeners}, since
         this card never wires up setActivatorNodeRef to scope that check
         to a dedicated handle — stopping propagation here is required or
         typing a space into the question text editor gets eaten as a
         drag-activation key instead of reaching the editor. */}
      <div onPointerDown={(event) => event.stopPropagation()} onKeyDown={(event) => event.stopPropagation()}>
        <RichTextEditor value={item.questionText ?? ''} onChange={onQuestionTextChange} />
      </div>

      {item.questionType === 'mcq' && (
        <div
          className="grid grid-cols-2 gap-2"
          onPointerDown={(event) => event.stopPropagation()}
          onKeyDown={(event) => event.stopPropagation()}
        >
          {[0, 1, 2, 3].map((optionIndex) => (
            <input
              key={optionIndex}
              value={item.options?.[optionIndex] ?? ''}
              onChange={(event) => onOptionChange(optionIndex, event.target.value)}
              placeholder={`${t['teacher.questionBuilder.optionPlaceholder']} ${optionIndex + 1}`}
              className="rounded border border-gray-200 px-2 py-1 text-sm outline-none focus:border-primary-500"
            />
          ))}
        </div>
      )}
    </div>
  )
}

function CanvasDropZone({
  items,
  onRemove,
  onMarksChange,
  onSerialChange,
  onQuestionTextChange,
  onOptionChange,
  onIndent,
  onOutdent,
}: {
  items: CanvasItem[]
  onRemove: (uid: string) => void
  onMarksChange: (uid: string, value: string) => void
  onSerialChange: (uid: string, value: string) => void
  onQuestionTextChange: (uid: string, html: string) => void
  onOptionChange: (uid: string, optionIndex: number, value: string) => void
  onIndent: (uid: string) => void
  onOutdent: (uid: string) => void
}) {
  const { setNodeRef, isOver } = useDroppable({ id: 'canvas' })

  return (
    <div
      ref={setNodeRef}
      className={`flex max-h-[32rem] min-h-[200px] flex-col gap-2 overflow-y-auto rounded-md border-2 border-dashed p-4 transition-colors ${
        isOver ? 'border-primary-500 bg-primary-50' : 'border-gray-200'
      }`}
    >
      {items.length === 0 && <p className="text-sm text-gray-400">{t['teacher.questionBuilder.dragHint']}</p>}

      <SortableContext items={items.map((item) => item.uid)} strategy={verticalListSortingStrategy}>
        {items.map((item, index) => (
          <CanvasItemCard
            key={item.uid}
            item={item}
            index={index}
            onRemove={() => onRemove(item.uid)}
            onMarksChange={(value) => onMarksChange(item.uid, value)}
            onSerialChange={(value) => onSerialChange(item.uid, value)}
            onQuestionTextChange={(html) => onQuestionTextChange(item.uid, html)}
            onOptionChange={(optionIndex, value) => onOptionChange(item.uid, optionIndex, value)}
            onIndent={() => onIndent(item.uid)}
            onOutdent={() => onOutdent(item.uid)}
          />
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

  // Two-step flow: Step 1 collects the paper's own details (title, class,
  // version, subject, time/marks, instructions); Step 2 is the question
  // bank search + drag-and-drop canvas, filtered by that SAME class/
  // version/subject rather than a separate, independent set of bank
  // filters — a teacher building a paper never wants bank questions from
  // a different class/subject than the paper itself.
  const [step, setStep] = useState<1 | 2>(1)

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [versions, setVersions] = useState<Version[]>([])

  const [title, setTitle] = useState('')
  const [schoolClassId, setSchoolClassId] = useState('')
  const [versionId, setVersionId] = useState('')
  const [subjectId, setSubjectId] = useState('')
  const [timeDuration, setTimeDuration] = useState('')
  const [fullMarks, setFullMarks] = useState('')
  const [instructions, setInstructions] = useState('')

  const [filtersOpen, setFiltersOpen] = useState(true)
  const [filterType, setFilterType] = useState('')
  const [filterMarks, setFilterMarks] = useState('')
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [selectedChapterIds, setSelectedChapterIds] = useState<Set<number>>(new Set())
  const [bankQuestions, setBankQuestions] = useState<AcademicQuestion[]>([])
  const [loadingBank, setLoadingBank] = useState(false)

  const [items, setItems] = useState<CanvasItem[]>([])
  const [headingDraft, setHeadingDraft] = useState('')
  const [addingHeading, setAddingHeading] = useState(false)
  const [textDraft, setTextDraft] = useState('')
  const [addingText, setAddingText] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 }).then((result) => setClasses(result.data)).catch(() => setClasses([]))
    listSubjects({ perPage: 200 }).then((result) => setSubjects(result.data)).catch(() => setSubjects([]))

    // Defaults to Bangla (falling back to whatever version sorts first)
    // so Step 1 starts on the medium most schools use, while staying a
    // normal, changeable <select> — not locked to it.
    listVersions({ perPage: 10 })
      .then((result) => {
        setVersions(result.data)
        setVersionId((previous) => previous || String(result.data.find((version) => version.name === 'Bangla')?.id ?? result.data[0]?.id ?? ''))
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

    if (!schoolClassId || !versionId || !subjectId) {
      setChapters([])
      return
    }

    let active = true

    listChapters({ classId: Number(schoolClassId), versionId: Number(versionId), subjectId: Number(subjectId) })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [schoolClassId, versionId, subjectId])

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
  // existing paper, then jump straight to Step 2 — re-opening an existing
  // paper is almost always to adjust its questions, not its details;
  // Back still reaches Step 1 to change them. Each 'question' item is
  // already a self-contained copy (question_type/question_text/options/
  // marks — see QuestionPaperItemResource), so no bank lookup is needed
  // to populate the canvas. version_id isn't stored on the paper itself
  // (see QuestionPaperPayload), so versionId keeps whatever Step 1's own
  // mount effect already defaulted it to.
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
            text: item.text_content ?? undefined,
            questionType: item.question_type ?? undefined,
            questionText: item.question_text ?? undefined,
            options: item.options ?? undefined,
            marks: item.marks ?? undefined,
            serialLabel: item.serial_label ?? undefined,
            indentLevel: item.indent_level,
          })),
        )
        setStep(2)
      })
      .catch(() => showToast({ type: 'error', message: t['teacher.questionBuilder.saveError'] }))
      .finally(() => setLoadingPaper(false))
  }, [token, paperId])

  function loadBank() {
    setLoadingBank(true)

    listAcademicQuestions(
      {
        schoolClassId: schoolClassId ? Number(schoolClassId) : undefined,
        versionId: versionId ? Number(versionId) : undefined,
        subjectId: subjectId ? Number(subjectId) : undefined,
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
      // This copies the bank question's content into a brand-new,
      // independent canvas item — editing it afterward never touches the
      // bank question it came from.
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

  function confirmText() {
    setItems((prev) => addTextToCanvas(prev, textDraft))
    setTextDraft('')
    setAddingText(false)
  }

  const computedFullMarks = items.reduce((sum, item) => sum + (item.type === 'question' ? (item.marks ?? 0) : 0), 0)

  const detailsComplete = Boolean(schoolClassId && versionId && subjectId)

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
          ? {
              type: 'question' as const,
              question_type: item.questionType,
              question_text: item.questionText,
              options: item.questionType === 'mcq' ? (item.options ?? null) : null,
              marks: item.marks ?? null,
              serial_label: item.serialLabel ?? null,
              indent_level: item.indentLevel,
            }
          : { type: item.type, text_content: item.text, indent_level: item.indentLevel },
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
      setStep(1)
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.questionBuilder.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  if (loadingPaper) {
    return <p className="px-4 py-6 text-sm text-gray-500 lg:px-6">{t['teacher.questionBuilder.loading']}</p>
  }

  const selectedClassName = classes.find((schoolClass) => String(schoolClass.id) === schoolClassId)?.name
  const selectedSubjectName = subjects.find((subject) => String(subject.id) === subjectId)?.name

  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <style>{richQuestionTextStyle}</style>
      <h1 className="text-2xl font-bold text-primary-700">
        {isEditing ? t['teacher.questionBuilder.editTitle'] : t['teacher.questionBuilder.title']}
      </h1>

      {step === 1 && (
        <div className="mx-auto flex w-full max-w-xl flex-col gap-3 rounded-md bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.detailsStepTitle']}</h2>

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
            <select className={selectClass} value={versionId} onChange={(event) => setVersionId(event.target.value)}>
              <option value="">{t['teacher.questionBuilder.selectVersion']}</option>
              {versions.map((version) => (
                <option key={version.id} value={version.id}>
                  {version.name}
                </option>
              ))}
            </select>
          </div>

          <select className={selectClass} value={subjectId} onChange={(event) => setSubjectId(event.target.value)}>
            <option value="">{t['teacher.questionBuilder.selectSubject']}</option>
            {subjects.map((subject) => (
              <option key={subject.id} value={subject.id}>
                {subject.name}
              </option>
            ))}
          </select>

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

          <div className="flex justify-end">
            <button type="button" onClick={() => setStep(2)} disabled={!detailsComplete} className={buttonPrimary}>
              {t['teacher.questionBuilder.next']}
            </button>
          </div>
        </div>
      )}

      {step === 2 && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" onClick={() => setStep(1)} className={buttonNeutral}>
              <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
              {t['teacher.questionBuilder.back']}
            </button>
            <p className="text-sm text-gray-500">
              {title || t['teacher.questionBuilder.titleLabel']}
              {selectedClassName && ` · ${selectedClassName}`}
              {selectedSubjectName && ` · ${selectedSubjectName}`}
            </p>
          </div>

          <div className="rounded-md bg-white p-4 shadow-sm">
            <button
              type="button"
              onClick={() => setFiltersOpen((previous) => !previous)}
              className="flex w-full cursor-pointer items-center justify-between text-sm font-semibold text-primary-800"
            >
              {t['teacher.questionBuilder.filtersTitle']}
              <span className="material-symbols-outlined !text-[20px]">{filtersOpen ? 'expand_less' : 'expand_more'}</span>
            </button>

            {filtersOpen && (
              <div className="mt-3 flex flex-col gap-3">
                <div className="flex flex-wrap gap-2">
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
                </div>

                <div className="flex flex-col gap-1.5 rounded-md border border-gray-200 p-3">
                  <span className="text-xs font-semibold tracking-wide text-gray-500 uppercase">
                    {t['teacher.questionBuilder.chaptersLabel']}
                  </span>
                  {chapters.length === 0 ? (
                    <p className="text-sm text-gray-400">{t['teacher.questionBuilder.noChapters']}</p>
                  ) : (
                    <div className="flex max-h-32 flex-wrap gap-x-4 gap-y-1.5 overflow-y-auto">
                      {chapters.map((chapter) => (
                        <label key={chapter.id} className="flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                          <input
                            type="checkbox"
                            checked={selectedChapterIds.has(chapter.id)}
                            onChange={() => toggleChapter(chapter.id)}
                            className="h-4 w-4 cursor-pointer accent-primary-500"
                          />
                          {chapter.name}
                        </label>
                      ))}
                    </div>
                  )}
                </div>

                <button type="button" onClick={loadBank} disabled={loadingBank} className={`${buttonPrimary} self-start`}>
                  {t['teacher.questionBuilder.loadQuestions']}
                </button>
              </div>
            )}
          </div>

          <DndContext onDragEnd={handleDragEnd}>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_3fr]">
              <div className="flex flex-col gap-3 rounded-md bg-white p-4 shadow-sm">
                <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.bankTitle']}</h2>

                <div className="flex max-h-[32rem] flex-col gap-2 overflow-y-auto">
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

                <CanvasDropZone
                  items={items}
                  onRemove={(uid) => setItems((prev) => removeCanvasItem(prev, uid))}
                  onMarksChange={(uid, value) => setItems((prev) => setQuestionMarks(prev, uid, value))}
                  onSerialChange={(uid, value) => setItems((prev) => setQuestionSerialLabel(prev, uid, value))}
                  onQuestionTextChange={(uid, html) => setItems((prev) => setQuestionText(prev, uid, html))}
                  onOptionChange={(uid, optionIndex, value) => setItems((prev) => setQuestionOption(prev, uid, optionIndex, value))}
                  onIndent={(uid) => setItems((prev) => indentItem(prev, uid))}
                  onOutdent={(uid) => setItems((prev) => outdentItem(prev, uid))}
                />

                <div className="flex flex-wrap gap-2">
                  {addingHeading ? (
                    <div className="flex grow gap-2">
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

                  {addingText ? (
                    <div className="flex grow gap-2">
                      <input
                        className={selectClass}
                        placeholder={t['teacher.questionBuilder.textPlaceholder']}
                        value={textDraft}
                        onChange={(event) => setTextDraft(event.target.value)}
                      />
                      <button type="button" onClick={confirmText} className={buttonPrimary}>
                        {t['teacher.questionBuilder.addText']}
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setAddingText(false)
                          setTextDraft('')
                        }}
                        className={buttonNeutral}
                      >
                        {t['teacher.questionBuilder.cancelHeading']}
                      </button>
                    </div>
                  ) : (
                    <button type="button" onClick={() => setAddingText(true)} className={buttonNeutral}>
                      {t['teacher.questionBuilder.insertText']}
                    </button>
                  )}
                </div>

                <button
                  type="button"
                  onClick={handleSave}
                  disabled={saving || !schoolClassId || !subjectId || items.length === 0}
                  className={`${buttonPrimary} self-start`}
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
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
