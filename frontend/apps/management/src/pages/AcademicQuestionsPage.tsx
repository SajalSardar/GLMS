import { useEffect, useState } from 'react'
import {
  createAcademicQuestion,
  deleteAcademicQuestion,
  listAcademicQuestionChapters,
  listAcademicQuestions,
  listClasses,
  listSubjects,
  updateAcademicQuestion,
  type AcademicQuestion,
  type AcademicQuestionChapter,
  type AcademicQuestionType,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import RichTextEditor from '../components/RichTextEditor'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

function matchesSearch(chapterName: string, questionText: string, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return chapterName.toLowerCase().includes(needle) || questionText.toLowerCase().includes(needle)
}

// Strips HTML tags for a plain-text search match against rich question
// text — good enough for a simple client-side filter, not meant to be a
// full HTML parser.
function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, ' ')
}

const initialValues = {
  school_class_id: '',
  subject_id: '',
  chapter_id: '',
  question_type: 'cq',
  question_text: '',
  option_1: '',
  option_2: '',
  option_3: '',
  option_4: '',
  marks: '',
  is_active: 'true',
}

// Note: option_1..4 are NOT in this list — their requiredness depends on
// question_type (MCQ only), which GeoCrudTable's blunt requiredFields
// check can't express. The server validates that conditional requirement
// (StoreAcademicQuestionRequest::withValidator()) and its 'options' error
// is surfaced via the fieldErrors.options banner in the form below.
const requiredFields = ['school_class_id', 'subject_id', 'chapter_id', 'question_type', 'question_text', 'marks']

function optionsFromValues(values: Record<string, string>): string[] | null {
  if (values.question_type !== 'mcq') return null
  return [values.option_1, values.option_2, values.option_3, values.option_4]
}

// Class -> class-wise subject -> chapter (from the question bank's own
// global chapter list, scoped by class+subject) — replaces free-typed
// chapter number/name entirely with a managed pick.
function AcademicQuestionFormFields({
  schoolClassId,
  subjectId,
  chapterId,
  classes,
  onChange,
  fieldErrors,
}: {
  schoolClassId: string
  subjectId: string
  chapterId: string
  classes: SchoolClass[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [chapters, setChapters] = useState<AcademicQuestionChapter[]>([])

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
    if (!schoolClassId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    listAcademicQuestionChapters({ schoolClassId: Number(schoolClassId), subjectId: Number(subjectId) })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [schoolClassId, subjectId])

  const chapterEnabled = Boolean(schoolClassId) && Boolean(subjectId)

  return (
    <>
      <GeoField label={t['management.fields.class']} required error={fieldErrors.school_class_id}>
        <SearchableSelect
          value={schoolClassId}
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

      <GeoField label={t['management.questionBank.colChapter']} required error={fieldErrors.chapter_id}>
        <SearchableSelect
          value={chapterId}
          onChange={(next) => onChange('chapter_id', next)}
          placeholder={t['management.questionBank.selectChapter']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={chapters.map((chapter) => ({
            value: String(chapter.id),
            label: `${chapter.chapter_number}. ${chapter.chapter_name}`,
          }))}
          className={!chapterEnabled ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

export default function AcademicQuestionsPage() {
  const { token } = useAuth()
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  const columns: GeoColumn<AcademicQuestion>[] = [
    { key: 'class', label: t['management.questionBank.colClass'], render: (row) => row.school_class?.name ?? '—' },
    { key: 'subject', label: t['management.questionBank.colSubject'], render: (row) => row.subject?.name ?? '—' },
    {
      key: 'chapter',
      label: t['management.questionBank.colChapter'],
      render: (row) => (row.chapter ? `${row.chapter.chapter_number}. ${row.chapter.chapter_name}` : '—'),
    },
    {
      key: 'type',
      label: t['management.questionBank.colType'],
      render: (row) => (row.question_type === 'cq' ? t['management.questionBank.typeCq'] : t['management.questionBank.typeMcq']),
    },
    { key: 'marks', label: t['management.questionBank.colMarks'], render: (row) => row.marks },
    {
      key: 'active',
      label: t['management.questionBank.colActive'],
      render: (row) => (row.is_active ? '✓' : '—'),
    },
  ]

  return (
    <GeoCrudTable<AcademicQuestion>
      title={t['management.questionBank.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_class_id: String(row.school_class_id),
        subject_id: String(row.subject_id),
        chapter_id: String(row.chapter_id),
        question_type: row.question_type,
        question_text: row.question_text,
        option_1: row.options?.[0] ?? '',
        option_2: row.options?.[1] ?? '',
        option_3: row.options?.[2] ?? '',
        option_4: row.options?.[3] ?? '',
        marks: String(row.marks),
        is_active: row.is_active ? 'true' : 'false',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <AcademicQuestionFormFields
            schoolClassId={values.school_class_id}
            subjectId={values.subject_id}
            chapterId={values.chapter_id}
            classes={classes}
            onChange={onChange}
            fieldErrors={fieldErrors}
          />

          <GeoField label={t['management.questionBank.colType']} required error={fieldErrors.question_type}>
            <select
              className={inputClass}
              value={values.question_type}
              onChange={(event) => onChange('question_type', event.target.value as AcademicQuestionType)}
            >
              <option value="cq">{t['management.questionBank.typeCq']}</option>
              <option value="mcq">{t['management.questionBank.typeMcq']}</option>
            </select>
          </GeoField>

          <GeoField label={t['management.questionBank.questionText']} required error={fieldErrors.question_text}>
            <RichTextEditor value={values.question_text} onChange={(html) => onChange('question_text', html)} />
          </GeoField>

          {values.question_type === 'mcq' && (
            <>
              <GeoField label={t['management.questionBank.option1']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_1} onChange={(event) => onChange('option_1', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option2']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_2} onChange={(event) => onChange('option_2', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option3']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_3} onChange={(event) => onChange('option_3', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option4']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_4} onChange={(event) => onChange('option_4', event.target.value)} />
              </GeoField>
            </>
          )}

          <GeoField label={t['management.questionBank.marks']} required error={fieldErrors.marks}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.marks}
              onChange={(event) => onChange('marks', event.target.value)}
            />
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
        listAcademicQuestions({
          schoolClassId: filters.parentId,
          subjectId: filters.secondaryParentId,
        }, token ?? undefined).then((result) =>
          toSinglePage(
            result.data.filter((question) =>
              matchesSearch(question.chapter?.chapter_name ?? '', stripHtml(question.question_text), filters.search),
            ),
          ),
        )
      }
      createRow={(values, token) =>
        createAcademicQuestion(
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_id: Number(values.chapter_id),
            question_type: values.question_type as AcademicQuestionType,
            question_text: values.question_text,
            options: optionsFromValues(values),
            marks: Number(values.marks),
            is_active: values.is_active === 'true',
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateAcademicQuestion(
          id,
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_id: Number(values.chapter_id),
            question_type: values.question_type as AcademicQuestionType,
            question_text: values.question_text,
            options: optionsFromValues(values),
            marks: Number(values.marks),
            is_active: values.is_active === 'true',
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteAcademicQuestion(id, token)}
      createPermission="academic_questions.create"
      updatePermission="academic_questions.update"
      deletePermission="academic_questions.delete"
      parentFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClass.name })),
      }}
      secondaryFilter={{
        label: t['management.fields.subject'],
        allLabel: t['management.fields.allSubjects'],
        options: subjects.map((subject) => ({ id: subject.id, label: subject.name })),
      }}
    />
  )
}
