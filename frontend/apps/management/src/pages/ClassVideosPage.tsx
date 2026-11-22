import { useEffect, useState } from 'react'
import {
  createChapterVideo,
  deleteChapterVideo,
  listBooks,
  listChapters,
  listChapterVideos,
  listSubjects,
  updateChapterVideo,
  type Book,
  type Chapter,
  type ChapterVideo,
  type Subject,
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

const initialValues = {
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

const requiredFields = ['youtube_url', 'title']

export default function ClassVideosPage() {
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [books, setBooks] = useState<Book[]>([])
  const [chapters, setChapters] = useState<Chapter[]>([])

  const [subjectId, setSubjectId] = useState('')
  const [bookId, setBookId] = useState('')
  const [chapterId, setChapterId] = useState('')

  useEffect(() => {
    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  useEffect(() => {
    if (!subjectId) {
      setBooks([])
      return
    }

    let active = true
    listBooks({ subjectId: Number(subjectId) })
      .then((result) => {
        if (active) setBooks(result.data)
      })
      .catch(() => {
        if (active) setBooks([])
      })

    return () => {
      active = false
    }
  }, [subjectId])

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

  const subjectOptions = subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))
  const bookOptions = books.map((book) => ({ value: String(book.id), label: book.name }))
  const chapterOptions = chapters.map((chapter) => ({ value: String(chapter.id), label: chapter.name }))

  const columns: GeoColumn<ChapterVideo>[] = [
    { key: 'title', label: t['management.fields.name'], render: (row) => row.title },
    { key: 'bn_title', label: t['management.fields.bnName'], render: (row) => row.bn_title ?? '—' },
    {
      key: 'instructor_name',
      label: t['management.classVideos.instructor'],
      render: (row) => row.instructor_name ?? '—',
    },
    { key: 'order_index', label: t['management.fields.order'], render: (row) => row.order_index ?? '—' },
    {
      key: 'is_enabled',
      label: t['management.fields.isActive'],
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
    <section className="space-y-5">
      <h2 className="text-lg font-semibold text-gray-800">{t['management.classVideos.title']}</h2>

      <div className="flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <div className="w-full sm:w-56">
          <GeoField label={t['management.fields.subject']}>
            <SearchableSelect
              value={subjectId}
              onChange={(next) => {
                setSubjectId(next)
                setBookId('')
                setChapterId('')
              }}
              placeholder={t['management.fields.subject']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={subjectOptions}
            />
          </GeoField>
        </div>

        <div className="w-full sm:w-56">
          <GeoField label={t['management.books.title']}>
            <SearchableSelect
              value={bookId}
              onChange={(next) => {
                setBookId(next)
                setChapterId('')
              }}
              placeholder={t['management.books.title']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={bookOptions}
              className={!subjectId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
            />
          </GeoField>
        </div>

        <div className="w-full sm:w-56">
          <GeoField label={t['management.chapters.title']}>
            <SearchableSelect
              value={chapterId}
              onChange={setChapterId}
              placeholder={t['management.chapters.title']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={chapterOptions}
              className={!bookId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
            />
          </GeoField>
        </div>
      </div>

      {!chapterId ? (
        <p className="rounded-md bg-white px-5 py-6 text-center text-sm text-gray-500 shadow-sm">
          {t['management.classVideos.selectChapterPrompt']}
        </p>
      ) : (
        <GeoCrudTable<ChapterVideo>
          key={chapterId}
          title={t['management.classVideos.title']}
          columns={columns}
          initialValues={initialValues}
          requiredFields={requiredFields}
          valuesFromRow={(row) => ({
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
          renderFields={(values, onChange, fieldErrors) => (
            <>
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
              <GeoField
                label={t['management.classVideos.learningObjectives']}
                error={fieldErrors.learning_objectives}
              >
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
            listChapterVideos({ chapterId: Number(chapterId) }).then((result) =>
              toSinglePage(result.data.filter((video) => matchesSearch(video.title, video.bn_title, filters.search))),
            )
          }
          createRow={(values, token) =>
            createChapterVideo(
              {
                chapter_id: Number(chapterId),
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
        />
      )}
    </section>
  )
}
