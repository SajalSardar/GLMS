import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ApiError,
  createPracticeExam,
  getPracticeExamConfig,
  listClasses,
  listSchools,
  type PracticeExamConfig,
  type School,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { chaptersForClassAndSubject, subjectsForClass, type ChapterWithBook } from '../lib/resolveContent'

const t = getCommonStrings('bn')

// Same pattern as BooksPage.tsx/VideoLecturePage.tsx — see the comment
// there for why a translated `fallback` is used instead of the raw
// `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

// The chapter pool is deliberately merged across every school's version
// of the chosen class, so two schools' "Chapter 1 — Mathematics" entries
// are otherwise identical on screen. Same resolution VideoLecturePage
// uses: ClassVersion carries a bare `school_id` with no nested `school`
// relation, so the school list is fetched once and mapped by id.
function chapterLabel(entry: ChapterWithBook, schools: School[]): string {
  const chapterName = entry.chapter.bn_name ?? entry.chapter.name
  const bookName = entry.book.bn_name ?? entry.book.name
  const schoolId = entry.book.class_version?.school_id
  const schoolName = schoolId ? (schools.find((school) => school.id === schoolId)?.name ?? '') : ''

  return schoolName ? `${chapterName} — ${bookName} — ${schoolName}` : `${chapterName} — ${bookName}`
}

export default function ExamsPage() {
  const { token } = useAuth()
  const navigate = useNavigate()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [schools, setSchools] = useState<School[]>([])
  const [classId, setClassId] = useState<number | null>(null)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(null)

  const [chapters, setChapters] = useState<ChapterWithBook[]>([])
  const [checkedChapterIds, setCheckedChapterIds] = useState<Set<number>>(new Set())
  const [chaptersLoading, setChaptersLoading] = useState(false)

  const [config, setConfig] = useState<PracticeExamConfig | null>(null)

  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSchools({ perPage: 500 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))

    getPracticeExamConfig()
      .then(setConfig)
      .catch(() => setConfig(null))
  }, [])

  useEffect(() => {
    setSubjectId(null)

    let active = true

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    setCheckedChapterIds(new Set())

    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    setChaptersLoading(true)

    chaptersForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setChapters(result)
      })
      .catch(() => {
        if (active) setChapters([])
      })
      .finally(() => {
        if (active) setChaptersLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  function toggleChapter(chapterId: number, checked: boolean) {
    setCheckedChapterIds((previous) => {
      const next = new Set(previous)
      if (checked) {
        next.add(chapterId)
      } else {
        next.delete(chapterId)
      }
      return next
    })
  }

  function questionHint(): string | null {
    if (!config || checkedChapterIds.size === 0) return null

    const template =
      checkedChapterIds.size === 1
        ? t['student.practiceExam.questionHintSingle']
        : t['student.practiceExam.questionHintMulti']
    const count =
      checkedChapterIds.size === 1 ? config.single_chapter_question_count : config.multi_chapter_question_count

    return template.replace('{count}', String(count))
  }

  async function handleSubmit() {
    if (!subjectId || checkedChapterIds.size === 0) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await createPracticeExam(
        { subjectId, chapterIds: [...checkedChapterIds] },
        token ?? undefined,
      )
      navigate(`/exams/${result.data.id}`)
    } catch (caught) {
      setError(messageFromApiError(caught, t['student.practiceExam.loadError']))
      setSubmitting(false)
    }
  }

  const hint = questionHint()

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.practiceExam.title']}</h1>

      <div className="mt-4 flex flex-wrap gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.practiceExam.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.bn_name ?? schoolClass.name}
            </option>
          ))}
        </select>

        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={!classId}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.practiceExam.allSubjects']}</option>
          {subjects.map((subject) => (
            <option key={subject.id} value={subject.id}>
              {subject.bn_name ?? subject.name}
            </option>
          ))}
        </select>
      </div>

      {!classId && <p className="mt-6 text-sm text-primary-600">{t['student.practiceExam.pickClass']}</p>}

      {classId && !subjectId && <p className="mt-6 text-sm text-primary-600">{t['student.practiceExam.pickSubject']}</p>}

      {classId && subjectId && (
        <>
          <h2 className="mt-6 text-lg font-semibold text-primary-800">{t['student.practiceExam.chaptersHeading']}</h2>

          {chaptersLoading && <p className="mt-2 text-sm text-primary-600">{t['student.exams.loading']}</p>}

          {!chaptersLoading && chapters.length === 0 && (
            <p className="mt-2 text-sm text-primary-600">{t['student.practiceExam.noChapters']}</p>
          )}

          <div className="mt-3 flex flex-col gap-2">
            {chapters.map((entry) => (
              <label
                key={entry.chapter.id}
                className="flex items-center gap-3 rounded-md border border-primary-100 px-4 py-3 text-sm text-primary-800"
              >
                <input
                  type="checkbox"
                  className="h-4 w-4"
                  checked={checkedChapterIds.has(entry.chapter.id)}
                  onChange={(event) => toggleChapter(entry.chapter.id, event.target.checked)}
                />
                {chapterLabel(entry, schools)}
              </label>
            ))}
          </div>

          {hint && <p className="mt-4 text-sm font-medium text-primary-700">{hint}</p>}

          {error && <p className="mt-4 text-sm text-danger-600">{error}</p>}

          <button
            type="button"
            onClick={handleSubmit}
            disabled={checkedChapterIds.size === 0 || submitting}
            className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
          >
            {submitting ? t['student.practiceExam.submitting'] : t['student.practiceExam.submit']}
          </button>
        </>
      )}
    </section>
  )
}
