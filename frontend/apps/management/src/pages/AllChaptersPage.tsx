import { useEffect, useState } from 'react'
import {
  createChapter,
  deleteChapter,
  listAcademicYears,
  listBooks,
  listChapters,
  listClasses,
  listClassVersions,
  listSubjects,
  listVersions,
  updateChapter,
  type AcademicYear,
  type Book,
  type Chapter,
  type ClassVersion,
  type SchoolClass,
  type Subject,
  type Version,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

function matchesSearch(name: string, bnName: string | null, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return name.toLowerCase().includes(needle) || (bnName ?? '').toLowerCase().includes(needle)
}

// A chapter only ever stores a book_id — there's no direct class/subject/
// version/academic-year column to pick from. This cascade resolves a Book
// from those four dimensions instead: Class narrows the per-class Version
// list (same as BooksPage's own cascade), then Subject + Academic Year
// narrow the Book list itself, since a book is only unique per
// (class_version, subject, name) — not per academic year, so more than
// one Book can still match and a final explicit Book pick is required.
function ChapterBookCascadeFields({
  classId,
  classVersionId,
  subjectId,
  academicYearId,
  bookId,
  classes,
  subjects,
  academicYears,
  onChange,
  fieldErrors,
}: {
  classId: string
  classVersionId: string
  subjectId: string
  academicYearId: string
  bookId: string
  classes: SchoolClass[]
  subjects: Subject[]
  academicYears: AcademicYear[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [books, setBooks] = useState<Book[]>([])

  useEffect(() => {
    if (!classId) {
      setClassVersions([])
      return
    }

    let active = true

    listClassVersions({ classId: Number(classId), perPage: 500 })
      .then((result) => {
        if (active) setClassVersions(result.data)
      })
      .catch(() => {
        if (active) setClassVersions([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    if (!classVersionId || !subjectId || !academicYearId) {
      setBooks([])
      return
    }

    let active = true

    listBooks({
      classVersionId: Number(classVersionId),
      subjectId: Number(subjectId),
      academicYearId: Number(academicYearId),
    })
      .then((result) => {
        if (active) setBooks(result.data)
      })
      .catch(() => {
        if (active) setBooks([])
      })

    return () => {
      active = false
    }
  }, [classVersionId, subjectId, academicYearId])

  const classOptions = classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))
  const versionOptions = classVersions.map((version) => ({ value: String(version.id), label: version.name }))
  const subjectOptions = subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))
  const academicYearOptions = academicYears.map((year) => ({ value: String(year.id), label: year.name }))
  const bookOptions = books.map((book) => ({ value: String(book.id), label: book.name }))
  const bookEnabled = Boolean(classVersionId) && Boolean(subjectId) && Boolean(academicYearId)

  return (
    <>
      <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
        <SearchableSelect
          value={classId}
          onChange={(next) => {
            onChange('class_id', next)
            onChange('class_version_id', '')
            onChange('book_id', '')
          }}
          placeholder={t['management.fields.class']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={classOptions}
        />
      </GeoField>

      <GeoField label={t['management.fields.version']} required error={fieldErrors.class_version_id}>
        <SearchableSelect
          value={classVersionId}
          onChange={(next) => {
            onChange('class_version_id', next)
            onChange('book_id', '')
          }}
          placeholder={t['management.fields.version']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={versionOptions}
          className={!classId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>

      <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
        <SearchableSelect
          value={subjectId}
          onChange={(next) => {
            onChange('subject_id', next)
            onChange('book_id', '')
          }}
          placeholder={t['management.fields.subject']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={subjectOptions}
        />
      </GeoField>

      <GeoField label={t['management.books.academicYear']} required error={fieldErrors.academic_year_id}>
        <SearchableSelect
          value={academicYearId}
          onChange={(next) => {
            onChange('academic_year_id', next)
            onChange('book_id', '')
          }}
          placeholder={t['management.books.academicYear']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={academicYearOptions}
        />
      </GeoField>

      <GeoField label={t['management.chapters.book']} required error={fieldErrors.book_id}>
        <SearchableSelect
          value={bookId}
          onChange={(next) => onChange('book_id', next)}
          placeholder={t['management.chapters.book']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={bookOptions}
          className={!bookEnabled ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

const initialValues = {
  class_id: '',
  class_version_id: '',
  subject_id: '',
  academic_year_id: '',
  book_id: '',
  name: '',
  bn_name: '',
  order_index: '',
  description: '',
}

const requiredFields = ['class_id', 'class_version_id', 'subject_id', 'academic_year_id', 'book_id', 'name']

export default function AllChaptersPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [versions, setVersions] = useState<Version[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    // The national Bangla/English catalog — used only for the top-of-page
    // filter, same reasoning as BooksPage: a simple 2-option toggle rather
    // than the per-class class_versions list the create form's cascade uses.
    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))

    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  const columns: GeoColumn<Chapter>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    { key: 'book', label: t['management.chapters.book'], render: (row) => row.book?.name ?? '—' },
    { key: 'class', label: t['management.fields.class'], render: (row) => row.book?.class_version?.class?.name ?? '—' },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.book?.subject?.name ?? '—' },
    { key: 'version', label: t['management.fields.version'], render: (row) => row.book?.class_version?.name ?? '—' },
    {
      key: 'academic_year',
      label: t['management.books.academicYear'],
      render: (row) => row.book?.academic_year?.name ?? '—',
    },
    {
      key: 'chapter_number',
      label: t['management.chapters.chapterNumber'],
      render: (row) => row.chapter_number ?? '—',
    },
    { key: 'order_index', label: t['management.fields.order'], render: (row) => row.order_index ?? '—' },
  ]

  return (
    <GeoCrudTable<Chapter>
      title={t['management.chapters.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        // Not expected in normal use (book_id is a required FK and deleting
        // a book while it still has chapters is blocked server-side), but
        // if it's ever missing, class_id/class_version_id/subject_id/
        // academic_year_id below all collapse to '' and GeoCrudTable's
        // requiredFields check would otherwise block Save with no visible
        // explanation — this flag drives a visible warning instead.
        book_missing: row.book ? '' : 'true',
        // Display-only in edit mode — book_id (and everything it implies)
        // is immutable after creation, same as Books' own class/subject/
        // academic-year fields.
        book_name: row.book?.name ?? '',
        class_name: row.book?.class_version?.class?.name ?? '',
        version_name: row.book?.class_version?.name ?? '',
        subject_name: row.book?.subject?.name ?? '',
        academic_year_name: row.book?.academic_year?.name ?? '',
        // Real values (not blanked), even though edit mode renders them as
        // disabled text above — GeoCrudTable's requiredFields check runs
        // against this same `values` object on submit, and these fields
        // are immutable on update anyway (the update payload below never
        // sends them), so keeping the actual ids here just satisfies that
        // validation without changing what's sent to the server.
        class_id: row.book?.class_version?.class_id != null ? String(row.book.class_version.class_id) : '',
        class_version_id: row.book ? String(row.book.class_version_id) : '',
        subject_id: row.book ? String(row.book.subject_id) : '',
        academic_year_id: row.book?.academic_year_id ? String(row.book.academic_year_id) : '',
        book_id: String(row.book_id),
        name: row.name,
        bn_name: row.bn_name ?? '',
        order_index: row.order_index !== null ? String(row.order_index) : '',
        description: row.description ?? '',
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
          {editingId && values.book_missing === 'true' && (
            <p className="col-span-1 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600 sm:col-span-2">
              {t['management.chapters.bookMissing']}
            </p>
          )}
          {editingId ? (
            <>
              <GeoField label={t['management.fields.class']}>
                <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.class_name} disabled />
              </GeoField>
              <GeoField label={t['management.fields.version']}>
                <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.version_name} disabled />
              </GeoField>
              <GeoField label={t['management.fields.subject']}>
                <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.subject_name} disabled />
              </GeoField>
              <GeoField label={t['management.books.academicYear']}>
                <input
                  className={`${inputClass} cursor-not-allowed opacity-60`}
                  value={values.academic_year_name}
                  disabled
                />
              </GeoField>
              <GeoField label={t['management.chapters.book']}>
                <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.book_name} disabled />
              </GeoField>
            </>
          ) : (
            <ChapterBookCascadeFields
              classId={values.class_id}
              classVersionId={values.class_version_id}
              subjectId={values.subject_id}
              academicYearId={values.academic_year_id}
              bookId={values.book_id}
              classes={classes}
              subjects={subjects}
              academicYears={academicYears}
              onChange={onChange}
              fieldErrors={fieldErrors}
            />
          )}

          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
            <input
              className={inputClass}
              value={values.bn_name}
              onChange={(event) => onChange('bn_name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.order']} error={fieldErrors.order_index}>
            <input
              type="number"
              min={0}
              className={inputClass}
              value={values.order_index}
              onChange={(event) => onChange('order_index', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.description']} error={fieldErrors.description}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={3}
              value={values.description}
              onChange={(event) => onChange('description', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(_page, filters) =>
        listChapters({
          classId: filters.parentId,
          subjectId: filters.secondaryParentId,
          versionId: filters.tertiaryParentId,
          academicYearId: filters.quaternaryParentId,
        }).then((result) =>
          toSinglePage(result.data.filter((chapter) => matchesSearch(chapter.name, chapter.bn_name, filters.search))),
        )
      }
      createRow={(values, token) =>
        createChapter(
          {
            book_id: Number(values.book_id),
            name: values.name,
            bn_name: values.bn_name || null,
            order_index: values.order_index ? Number(values.order_index) : null,
            description: values.description || null,
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateChapter(
          id,
          {
            name: values.name,
            bn_name: values.bn_name || null,
            order_index: values.order_index ? Number(values.order_index) : null,
            description: values.description || null,
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteChapter(id, token)}
      createPermission="chapters.create"
      updatePermission="chapters.update"
      deletePermission="chapters.delete"
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
      tertiaryFilter={{
        label: t['management.fields.version'],
        allLabel: t['management.fields.allVersions'],
        options: versions.map((version) => ({ id: version.id, label: version.name })),
      }}
      quaternaryFilter={{
        label: t['management.books.academicYear'],
        allLabel: t['management.fields.allAcademicYears'],
        options: academicYears.map((year) => ({ id: year.id, label: year.name })),
      }}
    />
  )
}
