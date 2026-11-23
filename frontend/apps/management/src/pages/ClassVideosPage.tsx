import { useEffect, useState } from 'react'
import {
  createChapterVideo,
  deleteChapterVideo,
  listAcademicYears,
  listBooks,
  listChapters,
  listChapterVideos,
  listClasses,
  listClassVersions,
  listSubjects,
  listVersions,
  updateChapterVideo,
  type AcademicYear,
  type Book,
  type Chapter,
  type ChapterVideo,
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

function matchesSearch(title: string, bnTitle: string | null, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return title.toLowerCase().includes(needle) || (bnTitle ?? '').toLowerCase().includes(needle)
}

// A video only ever stores a chapter_id — there's no direct class/subject/
// version/academic-year/book column to pick from. This cascade resolves a
// Chapter from those dimensions instead: Class narrows the per-class
// Version list (same as BooksPage's own cascade), Subject + Academic Year
// narrow the Book list (a book is only unique per class_version+subject+
// name, not per academic year, so more than one can still match), and the
// chosen Book finally narrows the Chapter list.
function ChapterVideoCascadeFields({
  classId,
  classVersionId,
  subjectId,
  academicYearId,
  bookId,
  chapterId,
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
  chapterId: string
  classes: SchoolClass[]
  subjects: Subject[]
  academicYears: AcademicYear[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [books, setBooks] = useState<Book[]>([])
  const [chapters, setChapters] = useState<Chapter[]>([])

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

  useEffect(() => {
    if (!bookId) {
      setChapters([])
      return
    }

    let active = true

    listChapters({ bookId: Number(bookId) })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [bookId])

  const classOptions = classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))
  const versionOptions = classVersions.map((version) => ({ value: String(version.id), label: version.name }))
  const subjectOptions = subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))
  const academicYearOptions = academicYears.map((year) => ({ value: String(year.id), label: year.name }))
  const bookOptions = books.map((book) => ({ value: String(book.id), label: book.name }))
  const chapterOptions = chapters.map((chapter) => ({ value: String(chapter.id), label: chapter.name }))
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
            onChange('chapter_id', '')
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
            onChange('chapter_id', '')
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
            onChange('chapter_id', '')
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
            onChange('chapter_id', '')
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
          onChange={(next) => {
            onChange('book_id', next)
            onChange('chapter_id', '')
          }}
          placeholder={t['management.chapters.book']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={bookOptions}
          className={!bookEnabled ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>

      <GeoField label={t['management.chapters.title']} required error={fieldErrors.chapter_id}>
        <SearchableSelect
          value={chapterId}
          onChange={(next) => onChange('chapter_id', next)}
          placeholder={t['management.chapters.title']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={chapterOptions}
          className={!bookId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
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
  chapter_id: '',
  youtube_url: '',
  title: '',
  bn_title: '',
  instructor_name: '',
  description: '',
  duration_minutes: '',
  learning_objectives: '',
  completion_threshold_percent: '',
  order_index: '',
  is_enabled: 'true',
}

const requiredFields = [
  'class_id',
  'class_version_id',
  'subject_id',
  'academic_year_id',
  'book_id',
  'chapter_id',
  'youtube_url',
  'title',
]

export default function ClassVideosPage() {
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
    // filter, same reasoning as BooksPage/AllChaptersPage: a simple
    // 2-option toggle rather than the per-class class_versions list the
    // create form's cascade uses.
    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))

    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  function openVideo(video: ChapterVideo) {
    window.open(`https://www.youtube.com/watch?v=${video.youtube_video_id}`, '_blank')
  }

  const columns: GeoColumn<ChapterVideo>[] = [
    { key: 'title', label: t['management.fields.name'], render: (row) => row.title },
    { key: 'bn_title', label: t['management.fields.bnName'], render: (row) => row.bn_title ?? '—' },
    { key: 'chapter', label: t['management.chapters.title'], render: (row) => row.chapter?.name ?? '—' },
    { key: 'book', label: t['management.chapters.book'], render: (row) => row.chapter?.book?.name ?? '—' },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => row.chapter?.book?.class_version?.class?.name ?? '—',
    },
    {
      key: 'subject',
      label: t['management.fields.subject'],
      render: (row) => row.chapter?.book?.subject?.name ?? '—',
    },
    {
      key: 'version',
      label: t['management.fields.version'],
      render: (row) => row.chapter?.book?.class_version?.name ?? '—',
    },
    {
      key: 'academic_year',
      label: t['management.books.academicYear'],
      render: (row) => row.chapter?.book?.academic_year?.name ?? '—',
    },
    {
      key: 'instructor_name',
      label: t['management.classVideos.instructor'],
      render: (row) => row.instructor_name ?? '—',
    },
    {
      key: 'duration_seconds',
      label: t['management.classVideos.durationMinutes'],
      render: (row) => (row.duration_seconds ? Math.round(row.duration_seconds / 60) : '—'),
    },
    {
      key: 'is_enabled',
      label: t['management.classVideos.isEnabled'],
      render: (row) => (
        <span
          className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
            row.is_enabled ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
          }`}
        >
          {row.is_enabled ? t['management.fields.yes'] : t['management.fields.no']}
        </span>
      ),
    },
  ]

  return (
    <GeoCrudTable<ChapterVideo>
      title={t['management.classVideos.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        // Display-only in edit mode — chapter_id (and everything it
        // implies) is immutable after creation, same as Chapters' own
        // class/subject/version/academic-year/book fields.
        chapter_name: row.chapter?.name ?? '',
        book_name: row.chapter?.book?.name ?? '',
        class_name: row.chapter?.book?.class_version?.class?.name ?? '',
        version_name: row.chapter?.book?.class_version?.name ?? '',
        subject_name: row.chapter?.book?.subject?.name ?? '',
        academic_year_name: row.chapter?.book?.academic_year?.name ?? '',
        // Real values (not blanked) — GeoCrudTable's requiredFields check
        // runs against this same `values` object on submit, and these
        // fields are immutable on update anyway (the update payload below
        // never sends them), so keeping the actual ids here just satisfies
        // that validation without changing what's sent to the server.
        class_id: row.chapter?.book?.class_version?.class_id != null ? String(row.chapter.book.class_version.class_id) : '',
        class_version_id: row.chapter?.book ? String(row.chapter.book.class_version_id) : '',
        subject_id: row.chapter?.book ? String(row.chapter.book.subject_id) : '',
        academic_year_id: row.chapter?.book?.academic_year_id ? String(row.chapter.book.academic_year_id) : '',
        book_id: row.chapter ? String(row.chapter.book_id) : '',
        chapter_id: String(row.chapter_id),
        youtube_url: `https://www.youtube.com/watch?v=${row.youtube_video_id}`,
        title: row.title,
        bn_title: row.bn_title ?? '',
        instructor_name: row.instructor_name ?? '',
        description: row.description ?? '',
        duration_minutes: row.duration_seconds ? String(Math.round(row.duration_seconds / 60)) : '',
        learning_objectives: row.learning_objectives ?? '',
        completion_threshold_percent:
          row.completion_threshold_percent !== null ? String(row.completion_threshold_percent) : '',
        order_index: row.order_index !== null ? String(row.order_index) : '',
        is_enabled: String(row.is_enabled),
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
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
              <GeoField label={t['management.chapters.title']}>
                <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.chapter_name} disabled />
              </GeoField>
            </>
          ) : (
            <ChapterVideoCascadeFields
              classId={values.class_id}
              classVersionId={values.class_version_id}
              subjectId={values.subject_id}
              academicYearId={values.academic_year_id}
              bookId={values.book_id}
              chapterId={values.chapter_id}
              classes={classes}
              subjects={subjects}
              academicYears={academicYears}
              onChange={onChange}
              fieldErrors={fieldErrors}
            />
          )}

          <GeoField label={t['management.classVideos.youtubeUrl']} required error={fieldErrors.youtube_url}>
            <input
              className={inputClass}
              placeholder="https://www.youtube.com/watch?v=..."
              value={values.youtube_url}
              onChange={(event) => onChange('youtube_url', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.name']} required error={fieldErrors.title}>
            <input
              className={inputClass}
              value={values.title}
              onChange={(event) => onChange('title', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_title}>
            <input
              className={inputClass}
              value={values.bn_title}
              onChange={(event) => onChange('bn_title', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.classVideos.instructor']} error={fieldErrors.instructor_name}>
            <input
              className={inputClass}
              value={values.instructor_name}
              onChange={(event) => onChange('instructor_name', event.target.value)}
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
          <GeoField label={t['management.classVideos.learningObjectives']} error={fieldErrors.learning_objectives}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={3}
              value={values.learning_objectives}
              onChange={(event) => onChange('learning_objectives', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.classVideos.durationMinutes']} error={fieldErrors.duration_seconds}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.duration_minutes}
              onChange={(event) => onChange('duration_minutes', event.target.value)}
            />
          </GeoField>
          <GeoField
            label={t['management.classVideos.completionThreshold']}
            error={fieldErrors.completion_threshold_percent}
          >
            <input
              type="number"
              min={1}
              max={100}
              className={inputClass}
              value={values.completion_threshold_percent}
              onChange={(event) => onChange('completion_threshold_percent', event.target.value)}
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
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_enabled === 'true'}
              onChange={(event) => onChange('is_enabled', String(event.target.checked))}
            />
            {t['management.classVideos.isEnabled']}
          </label>
        </>
      )}
      fetchPage={(_page, filters) =>
        listChapterVideos({
          classId: filters.parentId,
          subjectId: filters.secondaryParentId,
          versionId: filters.tertiaryParentId,
          academicYearId: filters.quaternaryParentId,
        }).then((result) =>
          toSinglePage(result.data.filter((video) => matchesSearch(video.title, video.bn_title, filters.search))),
        )
      }
      createRow={(values, token) =>
        createChapterVideo(
          {
            chapter_id: Number(values.chapter_id),
            youtube_url: values.youtube_url,
            title: values.title,
            bn_title: values.bn_title || null,
            instructor_name: values.instructor_name || null,
            description: values.description || null,
            duration_seconds: values.duration_minutes ? Number(values.duration_minutes) * 60 : null,
            learning_objectives: values.learning_objectives || null,
            completion_threshold_percent: values.completion_threshold_percent
              ? Number(values.completion_threshold_percent)
              : null,
            order_index: values.order_index ? Number(values.order_index) : undefined,
            is_enabled: values.is_enabled === 'true',
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateChapterVideo(
          id,
          {
            youtube_url: values.youtube_url,
            title: values.title,
            bn_title: values.bn_title || null,
            instructor_name: values.instructor_name || null,
            description: values.description || null,
            duration_seconds: values.duration_minutes ? Number(values.duration_minutes) * 60 : null,
            learning_objectives: values.learning_objectives || null,
            completion_threshold_percent: values.completion_threshold_percent
              ? Number(values.completion_threshold_percent)
              : null,
            order_index: values.order_index ? Number(values.order_index) : undefined,
            is_enabled: values.is_enabled === 'true',
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteChapterVideo(id, token)}
      rowActions={(row) => (
        <button
          type="button"
          className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
          onClick={() => openVideo(row)}
          aria-label={t['management.classVideos.viewVideo']}
          title={t['management.classVideos.viewVideo']}
        >
          <span className="material-symbols-outlined !text-md">play_circle</span>
        </button>
      )}
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
