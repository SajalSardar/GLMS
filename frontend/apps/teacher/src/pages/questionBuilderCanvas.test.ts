import { describe, expect, it } from 'vitest'
import type { AcademicQuestion } from '@ttla/api-client'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  addTextToCanvas,
  removeCanvasItem,
  reorderCanvasItems,
  setQuestionMarksOverride,
  setQuestionSerialLabel,
  type CanvasItem,
} from './questionBuilderCanvas'

function makeQuestion(overrides: Partial<AcademicQuestion> = {}): AcademicQuestion {
  return {
    id: 1,
    school_class_id: 1,
    subject_id: 1,
    version_id: 1,
    chapter_id: 1,
    question_type: 'cq',
    question_text: 'Read the passage...',
    options: null,
    marks: 10,
    is_active: true,
    created_at: '2026-11-25T00:00:00Z',
    ...overrides,
  }
}

describe('addQuestionToCanvas', () => {
  it('appends a new question item carrying the given question', () => {
    const result = addQuestionToCanvas([], makeQuestion({ id: 7 }))

    expect(result).toHaveLength(1)
    expect(result[0].type).toBe('question')
    expect(result[0].question?.id).toBe(7)
    expect(result[0].uid).toBeTruthy()
  })

  it('appends after existing items without disturbing them', () => {
    const existing: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Prose' }]
    const result = addQuestionToCanvas(existing, makeQuestion())

    expect(result).toHaveLength(2)
    expect(result[0]).toBe(existing[0])
    expect(result[1].type).toBe('question')
  })
})

describe('addHeadingToCanvas', () => {
  it('appends a new heading item with the trimmed text', () => {
    const result = addHeadingToCanvas([], '  Poetry  ')

    expect(result).toHaveLength(1)
    expect(result[0]).toMatchObject({ type: 'heading', text: 'Poetry' })
  })

  it('is a no-op for blank or whitespace-only text', () => {
    expect(addHeadingToCanvas([], '')).toEqual([])
    expect(addHeadingToCanvas([], '   ')).toEqual([])
  })
})

describe('addTextToCanvas', () => {
  it('appends a new plain text item with the trimmed content', () => {
    const result = addTextToCanvas([], '  Read the note below.  ')

    expect(result).toHaveLength(1)
    expect(result[0]).toMatchObject({ type: 'text', text: 'Read the note below.' })
  })

  it('is a no-op for blank or whitespace-only text', () => {
    expect(addTextToCanvas([], '')).toEqual([])
    expect(addTextToCanvas([], '   ')).toEqual([])
  })
})

describe('removeCanvasItem', () => {
  it('removes exactly the item with the matching uid', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', text: 'Prose' },
      { uid: 'b', type: 'question', question: makeQuestion() },
    ]

    expect(removeCanvasItem(items, 'a')).toEqual([items[1]])
  })

  it('is a no-op when the uid is not present', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Prose' }]

    expect(removeCanvasItem(items, 'missing')).toEqual(items)
  })
})

describe('reorderCanvasItems', () => {
  it('moves the active item to the position of the over item', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', text: 'First' },
      { uid: 'b', type: 'heading', text: 'Second' },
      { uid: 'c', type: 'heading', text: 'Third' },
    ]

    const result = reorderCanvasItems(items, 'c', 'a')

    expect(result.map((item) => item.uid)).toEqual(['c', 'a', 'b'])
  })

  it('is a no-op when active and over are the same item', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'a')).toBe(items)
  })

  it('is a no-op when either uid is not found', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'missing')).toBe(items)
    expect(reorderCanvasItems(items, 'missing', 'a')).toBe(items)
  })
})

describe('setQuestionMarksOverride', () => {
  it('sets the override on the matching item, leaving others untouched', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', question: makeQuestion({ id: 1 }) },
      { uid: 'b', type: 'question', question: makeQuestion({ id: 2 }) },
    ]

    const result = setQuestionMarksOverride(items, 'a', '15')

    expect(result[0].marksOverride).toBe(15)
    expect(result[1].marksOverride).toBeUndefined()
  })

  it('clears the override back to undefined for an empty value', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', question: makeQuestion(), marksOverride: 15 }]

    expect(setQuestionMarksOverride(items, 'a', '')[0].marksOverride).toBeUndefined()
    expect(setQuestionMarksOverride(items, 'a', '   ')[0].marksOverride).toBeUndefined()
  })
})

describe('setQuestionSerialLabel', () => {
  it('sets the label on the matching item, leaving others untouched', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', question: makeQuestion({ id: 1 }) },
      { uid: 'b', type: 'question', question: makeQuestion({ id: 2 }) },
    ]

    const result = setQuestionSerialLabel(items, 'a', '1(a)')

    expect(result[0].serialLabel).toBe('1(a)')
    expect(result[1].serialLabel).toBeUndefined()
  })

  it('clears the label back to undefined (auto-number) for an empty value', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', question: makeQuestion(), serialLabel: '1(a)' }]

    expect(setQuestionSerialLabel(items, 'a', '')[0].serialLabel).toBeUndefined()
    expect(setQuestionSerialLabel(items, 'a', '   ')[0].serialLabel).toBeUndefined()
  })
})
