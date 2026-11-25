import { useEffect, useState } from 'react'
import {
  createQuestion,
  deleteQuestion,
  listChapters,
  listClasses,
  listQuestions,
  listSubjects,
  listVersions,
  updateQuestion,
  type Chapter,
  type Question,
  type QuestionDifficulty,
  type QuestionOptionPayload,
  type QuestionType,
  type SchoolClass,
  type Subject,
  type Version,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const MAX_OPTIONS = 6

function matchesSearch(questionText: string, search: string): boolean {
  if (!search.trim()) return true
  return questionText.toLowerCase().includes(search.trim().toLowerCase())
}

function parseCorrectIndices(value: string): Set<number> {
  return new Set(
    value
      .split(',')
      .filter(Boolean)
      .map(Number),
  )
}

function toggleCorrectIndex(current: string, index: number, singleCorrect: boolean, checked: boolean): string {
  if (singleCorrect) {
    return checked ? String(index) : ''
  }

  const indices = parseCorrectIndices(current)
  if (checked) {
    indices.add(index)
  } else {
    indices.delete(index)
  }

  return [...indices].sort((a, b) => a - b).join(',')
}

// true_false's two options are fixed text ("True"/"False"); mcq_single and
// mcq_multiple share up to MAX_OPTIONS free-text slots — a blank slot is
// simply not sent, same convention as AcademicQuestionsPage's 4 fixed
// option fields, extended here to a variable 2-6 count instead of exactly
// 4. Correctness for every type lives in one comma-joined `correct_indices`
// field: mcq_single/true_false enforce exactly one member by replacing it
// outright on toggle (toggleCorrectIndex's singleCorrect branch), while
// mcq_multiple adds/removes freely.
function optionsFromValues(values: Record<string, string>): QuestionOptionPayload[] {
  const type = values.question_type as QuestionType

  if (type === 'true_false') {
    const correctIndex = Number(values.correct_indices || '0')
    return [
      { option_text: 'True', is_correct: correctIndex === 1, order_index: 0 },
      { option_text: 'False', is_correct: correctIndex === 2, order_index: 1 },
    ]
  }

  const correctIndices = parseCorrectIndices(values.correct_indices)
  const options: QuestionOptionPayload[] = []

  for (let index = 1; index <= MAX_OPTIONS; index += 1) {
    const text = values[`option_${index}_text`]?.trim()
    if (!text) continue

    options.push({ option_text: text, is_correct: correctIndices.has(index), order_index: options.length })
  }

  return options
}

const initialValues: Record<string, string> = {
  school_class_id: '',
  version_id: '',
  subject_id: '',
  chapter_id: '',
  question_type: 'mcq_single',
  question_text: '',
  bn_question_text: '',
  marks: '1',
  difficulty: 'medium',
  is_active: 'true',
  correct_indices: '',
  option_1_text: '',
  option_2_text: '',
  option_3_text: '',
  option_4_text: '',
  option_5_text: '',
  option_6_text: '',
}

const requiredFields = ['chapter_id', 'question_type', 'question_text', 'marks']

// Class -> Version -> class-wise Subject -> Chapter, the exact cascade
// AcademicQuestionsPage/AllChaptersPage already use to resolve a real
// chapter_id from the per-school Subject -> Book -> Chapter hierarchy.
function ChapterCascadeFields({
  schoolClassId,
  versionId,
  subjectId,
  chapterId,
  onChange,
  fieldErrors,
}: {
  schoolClassId: string
  versionId: string
  subjectId: string
  chapterId: string
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [chapters, setChapters] = useState<Chapter[]>([])

  useEffect(() => {
    if (!schoolClassId) {
      setSubjects([])
      return
    }

    let active = true
    listSubjects({ classId: Number(schoolClassId), perPage: 200 })
      .then((result) => {
        if (active) setSubjects(result.data)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    return () => {
      active = false
    }
  }, [schoolClassId])

  useEffect(() => {
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

  const chapterEnabled = Boolean(schoolClassId) && Boolean(versionId) && Boolean(subjectId)

  return (
    <>
      <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
        <SearchableSelect
          value={subjectId}
          onChange={(next) => {
            onChange('subject_id', next)
            onChange('chapter_id', '')
          }}
          placeholder={t['management.fields.subject']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))}
          className={!schoolClassId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>

      <GeoField label={t['management.examQuestions.colChapter']} required error={fieldErrors.chapter_id}>
        <SearchableSelect
          value={chapterId}
          onChange={(next) => onChange('chapter_id', next)}
          placeholder={t['management.examQuestions.selectChapter']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={chapters.map((chapter) => ({ value: String(chapter.id), label: chapter.name }))}
          className={!chapterEnabled ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

function OptionsEditor({
  values,
  onChange,
  fieldErrors,
}: {
  values: Record<string, string>
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const type = values.question_type as QuestionType
  const correctIndices = parseCorrectIndices(values.correct_indices)
  const singleCorrect = type !== 'mcq_multiple'

  if (type === 'true_false') {
    return (
      <GeoField label={t['management.examQuestions.options']} error={fieldErrors.options}>
        <div className="flex flex-col gap-2">
          {[1, 2].map((index) => (
            <label key={index} className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="radio"
                checked={Number(values.correct_indices) === index}
                onChange={() => onChange('correct_indices', String(index))}
              />
              {index === 1 ? t['management.examQuestions.trueLabel'] : t['management.examQuestions.falseLabel']}
            </label>
          ))}
        </div>
      </GeoField>
    )
  }

  return (
    <GeoField label={t['management.examQuestions.options']} required error={fieldErrors.options}>
      <div className="flex flex-col gap-2">
        {Array.from({ length: MAX_OPTIONS }, (_, position) => position + 1).map((index) => (
          <div key={index} className="flex items-center gap-2">
            <input
              type={singleCorrect ? 'radio' : 'checkbox'}
              checked={correctIndices.has(index)}
              onChange={(event) =>
                onChange('correct_indices', toggleCorrectIndex(values.correct_indices, index, singleCorrect, event.target.checked))
              }
            />
            <input
              className={inputClass}
              placeholder={`${t['management.examQuestions.optionPlaceholder']} ${index}`}
              value={values[`option_${index}_text`] ?? ''}
              onChange={(event) => onChange(`option_${index}_text`, event.target.value)}
            />
          </div>
        ))}
      </div>
    </GeoField>
  )
}

export default function ExamQuestionsPage() {
  const { token } = useAuth()
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [versions, setVersions] = useState<Version[]>([])
  const [chapterFilter, setChapterFilter] = useState<Chapter[]>([])

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))

    // No actor-scoping applies to this call (national_admin has no
    // school_id — see ChapterController::index()'s own comment), so this
    // is every chapter nationally. Small today; fine as a flat filter list.
    listChapters({})
      .then((result) => setChapterFilter(result.data))
      .catch(() => setChapterFilter([]))
  }, [])

  const columns: GeoColumn<Question>[] = [
    { key: 'chapter', label: t['management.examQuestions.colChapter'], render: (row) => row.chapter?.name ?? '—' },
    {
      key: 'type',
      label: t['management.examQuestions.colType'],
      render: (row) => t[`management.examQuestions.type.${row.question_type}`],
    },
    { key: 'text', label: t['management.examQuestions.colQuestion'], render: (row) => row.question_text },
    { key: 'marks', label: t['management.examQuestions.colMarks'], render: (row) => row.marks },
    {
      key: 'difficulty',
      label: t['management.examQuestions.colDifficulty'],
      render: (row) => t[`management.examQuestions.difficulty.${row.difficulty}`],
    },
    {
      key: 'active',
      label: t['management.questionBank.colActive'],
      render: (row) => (row.is_active ? '✓' : '—'),
    },
  ]

  return (
    <GeoCrudTable<Question>
      title={t['management.examQuestions.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => {
        const correctIndices = row.options
          .map((option, index) => (option.is_correct ? index + 1 : null))
          .filter((index): index is number => index !== null)

        const base: Record<string, string> = {
          school_class_id: '',
          version_id: '',
          subject_id: '',
          chapter_id: String(row.chapter_id),
          question_type: row.question_type,
          question_text: row.question_text,
          bn_question_text: row.bn_question_text ?? '',
          marks: String(row.marks),
          difficulty: row.difficulty,
          is_active: row.is_active ? 'true' : 'false',
          correct_indices: correctIndices.join(','),
          option_1_text: '',
          option_2_text: '',
          option_3_text: '',
          option_4_text: '',
          option_5_text: '',
          option_6_text: '',
        }

        if (row.question_type !== 'true_false') {
          row.options.forEach((option, index) => {
            base[`option_${index + 1}_text`] = option.option_text
          })
        }

        return base
      }}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
          {editingId ? (
            <GeoField label={t['management.examQuestions.colChapter']}>
              <input className={`${inputClass} cursor-not-allowed opacity-60`} value={t['management.examQuestions.chapterImmutable']} disabled />
            </GeoField>
          ) : (
            <>
              <GeoField label={t['management.fields.class']} required error={fieldErrors.school_class_id}>
                <SearchableSelect
                  value={values.school_class_id}
                  onChange={(next) => {
                    onChange('school_class_id', next)
                    onChange('subject_id', '')
                    onChange('chapter_id', '')
                  }}
                  placeholder={t['management.fields.class']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))}
                />
              </GeoField>

              <GeoField label={t['management.fields.version']} required error={fieldErrors.version_id}>
                <SearchableSelect
                  value={values.version_id}
                  onChange={(next) => {
                    onChange('version_id', next)
                    onChange('chapter_id', '')
                  }}
                  placeholder={t['management.fields.version']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={versions.map((version) => ({ value: String(version.id), label: version.name }))}
                />
              </GeoField>

              <ChapterCascadeFields
                schoolClassId={values.school_class_id}
                versionId={values.version_id}
                subjectId={values.subject_id}
                chapterId={values.chapter_id}
                onChange={onChange}
                fieldErrors={fieldErrors}
              />
            </>
          )}

          <GeoField label={t['management.examQuestions.colType']} required error={fieldErrors.question_type}>
            <select
              className={inputClass}
              value={values.question_type}
              onChange={(event) => {
                onChange('question_type', event.target.value as QuestionType)
                onChange('correct_indices', '')
              }}
            >
              <option value="mcq_single">{t['management.examQuestions.type.mcq_single']}</option>
              <option value="mcq_multiple">{t['management.examQuestions.type.mcq_multiple']}</option>
              <option value="true_false">{t['management.examQuestions.type.true_false']}</option>
            </select>
          </GeoField>

          <GeoField label={t['management.examQuestions.colQuestion']} required error={fieldErrors.question_text}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={3}
              value={values.question_text}
              onChange={(event) => onChange('question_text', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.examQuestions.bnQuestion']} error={fieldErrors.bn_question_text}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={2}
              value={values.bn_question_text}
              onChange={(event) => onChange('bn_question_text', event.target.value)}
            />
          </GeoField>

          <OptionsEditor values={values} onChange={onChange} fieldErrors={fieldErrors} />

          <GeoField label={t['management.examQuestions.colMarks']} required error={fieldErrors.marks}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.marks}
              onChange={(event) => onChange('marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.examQuestions.colDifficulty']} error={fieldErrors.difficulty}>
            <select className={inputClass} value={values.difficulty} onChange={(event) => onChange('difficulty', event.target.value as QuestionDifficulty)}>
              <option value="easy">{t['management.examQuestions.difficulty.easy']}</option>
              <option value="medium">{t['management.examQuestions.difficulty.medium']}</option>
              <option value="hard">{t['management.examQuestions.difficulty.hard']}</option>
            </select>
          </GeoField>

          <GeoField label={t['management.questionBank.active']} error={fieldErrors.is_active}>
            <select className={inputClass} value={values.is_active} onChange={(event) => onChange('is_active', event.target.value)}>
              <option value="true">{t['management.questionBank.active']}</option>
              <option value="false">—</option>
            </select>
          </GeoField>
        </>
      )}
      fetchPage={(_page, filters) =>
        listQuestions({ chapterId: filters.parentId }, token ?? undefined).then((result) =>
          toSinglePage(result.data.filter((question) => matchesSearch(question.question_text, filters.search))),
        )
      }
      createRow={(values, token) =>
        createQuestion(
          {
            chapter_id: Number(values.chapter_id),
            question_type: values.question_type as QuestionType,
            question_text: values.question_text,
            bn_question_text: values.bn_question_text || null,
            marks: Number(values.marks),
            difficulty: values.difficulty as QuestionDifficulty,
            is_active: values.is_active === 'true',
            options: optionsFromValues(values),
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateQuestion(
          id,
          {
            chapter_id: Number(values.chapter_id),
            question_type: values.question_type as QuestionType,
            question_text: values.question_text,
            bn_question_text: values.bn_question_text || null,
            marks: Number(values.marks),
            difficulty: values.difficulty as QuestionDifficulty,
            is_active: values.is_active === 'true',
            options: optionsFromValues(values),
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteQuestion(id, token)}
      createPermission="questions.create"
      updatePermission="questions.update"
      deletePermission="questions.delete"
      parentFilter={{
        label: t['management.examQuestions.colChapter'],
        allLabel: t['management.fields.allChapters'],
        options: chapterFilter.map((chapter) => ({
          id: chapter.id,
          label: chapter.book ? `${chapter.name} — ${chapter.book.name}` : chapter.name,
        })),
      }}
    />
  )
}
