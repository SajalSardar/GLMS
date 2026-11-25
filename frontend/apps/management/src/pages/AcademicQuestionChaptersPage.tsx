import { useEffect, useState } from 'react'
import {
  createAcademicQuestionChapter,
  deleteAcademicQuestionChapter,
  listAcademicQuestionChapters,
  listClasses,
  listSubjects,
  updateAcademicQuestionChapter,
  type AcademicQuestionChapter,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

function matchesSearch(chapterName: string, search: string): boolean {
  if (!search.trim()) return true
  return chapterName.toLowerCase().includes(search.trim().toLowerCase())
}

const initialValues = {
  school_class_id: '',
  subject_id: '',
  chapter_number: '',
  chapter_name: '',
}

const requiredFields = ['school_class_id', 'subject_id', 'chapter_number', 'chapter_name']

// Class -> class-wise subject — mirrors the cascade the question bank's
// own Add/Edit form uses, so the same chapter list and the questions
// that reference it stay consistent about what "class-wise subject"
// means.
function ChapterFormFields({
  schoolClassId,
  subjectId,
  classes,
  onChange,
  fieldErrors,
}: {
  schoolClassId: string
  subjectId: string
  classes: SchoolClass[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [subjects, setSubjects] = useState<Subject[]>([])

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

  return (
    <>
      <GeoField label={t['management.fields.class']} required error={fieldErrors.school_class_id}>
        <SearchableSelect
          value={schoolClassId}
          onChange={(next) => {
            onChange('school_class_id', next)
            onChange('subject_id', '')
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
          onChange={(next) => onChange('subject_id', next)}
          placeholder={t['management.fields.subject']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))}
          className={!schoolClassId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

export default function AcademicQuestionChaptersPage() {
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

  const columns: GeoColumn<AcademicQuestionChapter>[] = [
    { key: 'class', label: t['management.academicQuestionChapters.colClass'], render: (row) => row.school_class?.name ?? '—' },
    { key: 'subject', label: t['management.academicQuestionChapters.colSubject'], render: (row) => row.subject?.name ?? '—' },
    { key: 'chapterNumber', label: t['management.academicQuestionChapters.colChapterNumber'], render: (row) => row.chapter_number },
    { key: 'chapterName', label: t['management.academicQuestionChapters.colChapterName'], render: (row) => row.chapter_name },
  ]

  return (
    <GeoCrudTable<AcademicQuestionChapter>
      title={t['management.academicQuestionChapters.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_class_id: String(row.school_class_id),
        subject_id: String(row.subject_id),
        chapter_number: String(row.chapter_number),
        chapter_name: row.chapter_name,
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <ChapterFormFields
            schoolClassId={values.school_class_id}
            subjectId={values.subject_id}
            classes={classes}
            onChange={onChange}
            fieldErrors={fieldErrors}
          />

          <GeoField label={t['management.academicQuestionChapters.chapterNumber']} required error={fieldErrors.chapter_number}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.chapter_number}
              onChange={(event) => onChange('chapter_number', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.academicQuestionChapters.chapterName']} required error={fieldErrors.chapter_name}>
            <input
              className={inputClass}
              value={values.chapter_name}
              onChange={(event) => onChange('chapter_name', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(_page, filters) =>
        listAcademicQuestionChapters(
          {
            schoolClassId: filters.parentId,
            subjectId: filters.secondaryParentId,
          },
          token ?? undefined,
        ).then((result) => toSinglePage(result.data.filter((chapter) => matchesSearch(chapter.chapter_name, filters.search))))
      }
      createRow={(values, token) =>
        createAcademicQuestionChapter(
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_number: Number(values.chapter_number),
            chapter_name: values.chapter_name,
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateAcademicQuestionChapter(
          id,
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_number: Number(values.chapter_number),
            chapter_name: values.chapter_name,
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteAcademicQuestionChapter(id, token)}
      createPermission="academic_question_chapters.create"
      updatePermission="academic_question_chapters.update"
      deletePermission="academic_question_chapters.delete"
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
