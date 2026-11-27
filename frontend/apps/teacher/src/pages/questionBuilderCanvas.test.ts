import { describe, expect, it } from 'vitest'
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
  type CanvasQuestionSummary,
} from './questionBuilderCanvas'

function makeQuestion(overrides: Partial<CanvasQuestionSummary> = {}): CanvasQuestionSummary {
  return {
    question_type: 'cq',
    question_text: 'Read the passage...',
    options: null,
    marks: 10,
    ...overrides,
  }
}

describe('addQuestionToCanvas', () => {
  it('appends a new question item, copying the question content in', () => {
    const result = addQuestionToCanvas([], makeQuestion({ question_text: 'Explain photosynthesis.', marks: 7 }))

    expect(result).toHaveLength(1)
    expect(result[0]).toMatchObject({
      type: 'question',
      questionType: 'cq',
      questionText: 'Explain photosynthesis.',
      marks: 7,
      indentLevel: 0,
    })
    expect(result[0].uid).toBeTruthy()
  })

  it('appends after existing items without disturbing them', () => {
    const existing: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Prose', indentLevel: 0 }]
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
    expect(result[0]).toMatchObject({ type: 'heading', text: 'Poetry', indentLevel: 0 })
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
    expect(result[0]).toMatchObject({ type: 'text', text: 'Read the note below.', indentLevel: 0 })
  })

  it('is a no-op for blank or whitespace-only text', () => {
    expect(addTextToCanvas([], '')).toEqual([])
    expect(addTextToCanvas([], '   ')).toEqual([])
  })
})

describe('removeCanvasItem', () => {
  it('removes exactly the item with the matching uid', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', text: 'Prose', indentLevel: 0 },
      { uid: 'b', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 },
    ]

    expect(removeCanvasItem(items, 'a')).toEqual([items[1]])
  })

  it('is a no-op when the uid is not present', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Prose', indentLevel: 0 }]

    expect(removeCanvasItem(items, 'missing')).toEqual(items)
  })
})

describe('reorderCanvasItems', () => {
  it('moves the active item to the position of the over item', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', text: 'First', indentLevel: 0 },
      { uid: 'b', type: 'heading', text: 'Second', indentLevel: 0 },
      { uid: 'c', type: 'heading', text: 'Third', indentLevel: 0 },
    ]

    const result = reorderCanvasItems(items, 'c', 'a')

    expect(result.map((item) => item.uid)).toEqual(['c', 'a', 'b'])
  })

  it('is a no-op when active and over are the same item', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Only', indentLevel: 0 }]

    expect(reorderCanvasItems(items, 'a', 'a')).toBe(items)
  })

  it('is a no-op when either uid is not found', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', text: 'Only', indentLevel: 0 }]

    expect(reorderCanvasItems(items, 'a', 'missing')).toBe(items)
    expect(reorderCanvasItems(items, 'missing', 'a')).toBe(items)
  })
})

describe('setQuestionText', () => {
  it('edits the wording of the matching item, leaving others untouched', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', questionType: 'cq', questionText: 'Original', marks: 5, indentLevel: 0 },
      { uid: 'b', type: 'question', questionType: 'cq', questionText: 'Other', marks: 5, indentLevel: 0 },
    ]

    const result = setQuestionText(items, 'a', 'Edited for this paper only')

    expect(result[0].questionText).toBe('Edited for this paper only')
    expect(result[1].questionText).toBe('Other')
  })
})

describe('setQuestionOption', () => {
  it('sets one option by index, defaulting the other three to empty strings', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'mcq', questionText: 'Q', marks: 1, options: null, indentLevel: 0 }]

    const result = setQuestionOption(items, 'a', 2, 'Paris')

    expect(result[0].options).toEqual(['', '', 'Paris', ''])
  })

  it('updates just the given index of an existing options array', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', questionType: 'mcq', questionText: 'Q', marks: 1, options: ['a', 'b', 'c', 'd'], indentLevel: 0 },
    ]

    const result = setQuestionOption(items, 'a', 0, 'z')

    expect(result[0].options).toEqual(['z', 'b', 'c', 'd'])
  })
})

describe('setQuestionMarks', () => {
  it('sets the marks on the matching item, leaving others untouched', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 10, indentLevel: 0 },
      { uid: 'b', type: 'question', questionType: 'cq', questionText: 'Q', marks: 10, indentLevel: 0 },
    ]

    const result = setQuestionMarks(items, 'a', '15')

    expect(result[0].marks).toBe(15)
    expect(result[1].marks).toBe(10)
  })

  it('clears back to undefined for an empty value', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 15, indentLevel: 0 }]

    expect(setQuestionMarks(items, 'a', '')[0].marks).toBeUndefined()
    expect(setQuestionMarks(items, 'a', '   ')[0].marks).toBeUndefined()
  })
})

describe('setQuestionSerialLabel', () => {
  it('sets the label on the matching item, leaving others untouched', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 },
      { uid: 'b', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 },
    ]

    const result = setQuestionSerialLabel(items, 'a', '1(a)')

    expect(result[0].serialLabel).toBe('1(a)')
    expect(result[1].serialLabel).toBeUndefined()
  })

  it('clears the label back to undefined (auto-number) for an empty value', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, serialLabel: '1(a)', indentLevel: 0 }]

    expect(setQuestionSerialLabel(items, 'a', '')[0].serialLabel).toBeUndefined()
    expect(setQuestionSerialLabel(items, 'a', '   ')[0].serialLabel).toBeUndefined()
  })
})

describe('indentItem / outdentItem', () => {
  it('increases and decreases the indent level of the matching item', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 }]

    const indented = indentItem(items, 'a')
    expect(indented[0].indentLevel).toBe(1)

    const outdented = outdentItem(indented, 'a')
    expect(outdented[0].indentLevel).toBe(0)
  })

  it('never goes below 0', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 }]

    expect(outdentItem(items, 'a')[0].indentLevel).toBe(0)
  })

  it('caps at the maximum indent level', () => {
    let items: CanvasItem[] = [{ uid: 'a', type: 'question', questionType: 'cq', questionText: 'Q', marks: 5, indentLevel: 0 }]

    for (let i = 0; i < 10; i += 1) {
      items = indentItem(items, 'a')
    }

    expect(items[0].indentLevel).toBe(5)
  })
})
