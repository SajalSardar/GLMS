import type { AcademicQuestionType } from '@ttla/api-client'

const MAX_INDENT_LEVEL = 5

// A canvas item is self-contained — dragging a bank question onto the
// canvas copies its content (question_type/question_text/options/marks)
// in once; after that the item has no link back to the bank at all, so
// every field below is this paper's own, freely editable copy (see
// backend/database/migrations/2026_11_27_143843_embed_question_content_in_question_paper_items_and_add_indent_level).
export interface CanvasQuestionSummary {
  question_type: AcademicQuestionType
  question_text: string
  options: string[] | null
  marks: number
}

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading' | 'text'
  // Shared by 'heading' and 'text' — a heading differs from a plain
  // inserted text block only in how it renders (centered, bold,
  // underlined vs plain), not in what it stores.
  text?: string
  // 'question' only.
  questionType?: AcademicQuestionType
  questionText?: string
  options?: string[] | null
  marks?: number
  serialLabel?: string
  // Every item type — a plain visual nesting depth (0 = normal item,
  // 1+ = indented further right, like a nested <li>), independent of
  // serial_label's own numbering.
  indentLevel: number
}

export function addQuestionToCanvas(items: CanvasItem[], question: CanvasQuestionSummary): CanvasItem[] {
  return [
    ...items,
    {
      uid: crypto.randomUUID(),
      type: 'question',
      questionType: question.question_type,
      questionText: question.question_text,
      options: question.options,
      marks: question.marks,
      indentLevel: 0,
    },
  ]
}

export function addHeadingToCanvas(items: CanvasItem[], headingText: string): CanvasItem[] {
  const trimmed = headingText.trim()
  if (!trimmed) return items

  return [...items, { uid: crypto.randomUUID(), type: 'heading', text: trimmed, indentLevel: 0 }]
}

export function addTextToCanvas(items: CanvasItem[], text: string): CanvasItem[] {
  const trimmed = text.trim()
  if (!trimmed) return items

  return [...items, { uid: crypto.randomUUID(), type: 'text', text: trimmed, indentLevel: 0 }]
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

function updateItem(items: CanvasItem[], uid: string, patch: Partial<CanvasItem>): CanvasItem[] {
  return items.map((item) => (item.uid === uid ? { ...item, ...patch } : item))
}

export function setQuestionText(items: CanvasItem[], uid: string, questionText: string): CanvasItem[] {
  return updateItem(items, uid, { questionText })
}

export function setQuestionOption(items: CanvasItem[], uid: string, optionIndex: number, value: string): CanvasItem[] {
  return items.map((item) => {
    if (item.uid !== uid) return item

    const options = [...(item.options ?? ['', '', '', ''])]
    options[optionIndex] = value
    return { ...item, options }
  })
}

// Empty string clears back to "no marks set" — callers pass the raw
// <input> value, so parsing happens once, here.
export function setQuestionMarks(items: CanvasItem[], uid: string, rawValue: string): CanvasItem[] {
  const trimmed = rawValue.trim()
  return updateItem(items, uid, { marks: trimmed === '' ? undefined : Number(trimmed) })
}

// Empty string clears back to "auto-number as usual".
export function setQuestionSerialLabel(items: CanvasItem[], uid: string, rawValue: string): CanvasItem[] {
  const trimmed = rawValue.trim()
  return updateItem(items, uid, { serialLabel: trimmed === '' ? undefined : trimmed })
}

export function indentItem(items: CanvasItem[], uid: string): CanvasItem[] {
  return items.map((item) => (item.uid === uid ? { ...item, indentLevel: Math.min(item.indentLevel + 1, MAX_INDENT_LEVEL) } : item))
}

export function outdentItem(items: CanvasItem[], uid: string): CanvasItem[] {
  return items.map((item) => (item.uid === uid ? { ...item, indentLevel: Math.max(item.indentLevel - 1, 0) } : item))
}
