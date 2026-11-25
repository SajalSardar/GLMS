import { describe, expect, it } from 'vitest'
import type { AcademicQuestion } from '@ttla/api-client'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  removeCanvasItem,
  reorderCanvasItems,
  type CanvasItem,
} from './questionBuilderCanvas'

function makeQuestion(overrides: Partial<AcademicQuestion> = {}): AcademicQuestion {
  return {
    id: 1,
    school_class_id: 1,
    subject_id: 1,
    chapter_number: 1,
    chapter_name: 'Prose',
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
    const existing: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Prose' }]
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
    expect(result[0]).toMatchObject({ type: 'heading', headingText: 'Poetry' })
  })

  it('is a no-op for blank or whitespace-only text', () => {
    expect(addHeadingToCanvas([], '')).toEqual([])
    expect(addHeadingToCanvas([], '   ')).toEqual([])
  })
})

describe('removeCanvasItem', () => {
  it('removes exactly the item with the matching uid', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', headingText: 'Prose' },
      { uid: 'b', type: 'question', question: makeQuestion() },
    ]

    expect(removeCanvasItem(items, 'a')).toEqual([items[1]])
  })

  it('is a no-op when the uid is not present', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Prose' }]

    expect(removeCanvasItem(items, 'missing')).toEqual(items)
  })
})

describe('reorderCanvasItems', () => {
  it('moves the active item to the position of the over item', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', headingText: 'First' },
      { uid: 'b', type: 'heading', headingText: 'Second' },
      { uid: 'c', type: 'heading', headingText: 'Third' },
    ]

    const result = reorderCanvasItems(items, 'c', 'a')

    expect(result.map((item) => item.uid)).toEqual(['c', 'a', 'b'])
  })

  it('is a no-op when active and over are the same item', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'a')).toBe(items)
  })

  it('is a no-op when either uid is not found', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'missing')).toBe(items)
    expect(reorderCanvasItems(items, 'missing', 'a')).toBe(items)
  })
})
