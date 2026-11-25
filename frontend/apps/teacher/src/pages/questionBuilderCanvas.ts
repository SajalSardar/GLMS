import type { AcademicQuestionType } from '@ttla/api-client'

// Deliberately narrower than the full AcademicQuestion type: a canvas
// item only ever needs enough to render itself and to reference the
// bank question by id. chapter_name is optional (not rendered anywhere
// in the canvas) specifically so both of this type's two real sources
// satisfy it without reshaping: a full AcademicQuestion from the bank
// search (which carries chapter_id + a nested chapter object instead)
// and QuestionPaperItemResource's nested academic_question
// (backend/app/Http/Resources/Api/V1/QuestionPaperItemResource.php),
// which flattens just the chapter's name onto the item for a loaded
// paper's own display needs.
export interface CanvasQuestionSummary {
  id: number
  question_type: AcademicQuestionType
  question_text: string
  options: string[] | null
  marks: number
  chapter_name?: string
}

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading'
  question?: CanvasQuestionSummary
  headingText?: string
}

export function addQuestionToCanvas(items: CanvasItem[], question: CanvasQuestionSummary): CanvasItem[] {
  return [...items, { uid: crypto.randomUUID(), type: 'question', question }]
}

export function addHeadingToCanvas(items: CanvasItem[], headingText: string): CanvasItem[] {
  const trimmed = headingText.trim()
  if (!trimmed) return items

  return [...items, { uid: crypto.randomUUID(), type: 'heading', headingText: trimmed }]
}

export function removeCanvasItem(items: CanvasItem[], uid: string): CanvasItem[] {
  return items.filter((item) => item.uid !== uid)
}

// Moves the item with id activeUid to the position currently held by
// overUid — the exact reorder semantics dnd-kit's own arrayMove()
// provides, reimplemented here (rather than imported) so this module has
// zero dependency on @dnd-kit and can be unit-tested with nothing but
// plain data.
export function reorderCanvasItems(items: CanvasItem[], activeUid: string, overUid: string): CanvasItem[] {
  if (activeUid === overUid) return items

  const oldIndex = items.findIndex((item) => item.uid === activeUid)
  const newIndex = items.findIndex((item) => item.uid === overUid)
  if (oldIndex === -1 || newIndex === -1) return items

  const copy = [...items]
  const [moved] = copy.splice(oldIndex, 1)
  copy.splice(newIndex, 0, moved)
  return copy
}
