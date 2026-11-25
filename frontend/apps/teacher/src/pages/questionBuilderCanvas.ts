import type { AcademicQuestion } from '@ttla/api-client'

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading'
  question?: AcademicQuestion
  headingText?: string
}

export function addQuestionToCanvas(items: CanvasItem[], question: AcademicQuestion): CanvasItem[] {
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
