import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  ApiError,
  createPracticeExam,
  getPracticeExamConfig,
  listMyExamAttempts,
  type ExamAttempt,
  type PracticeExamConfig,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import {
  allClasses,
  chaptersForClassAndSubject,
  ownClassId,
  subjectsForClass,
  type ChapterWithBook,
} from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

function InfoAlert({ message }: { message: string }) {
  return (
    <div className="mt-6 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700">
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v5" />
        <circle cx="12" cy="8" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

function WarningAlert({ message }: { message: string }) {
  return (
    <div className="mt-4 flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <path d="M10.3 3.9 1.9 18a1.8 1.8 0 0 0 1.55 2.7h17.1A1.8 1.8 0 0 0 22.1 18L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" />
        <path d="M12 9.5v4" />
        <circle cx="12" cy="17" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

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

// Lets VideoPlaylistPage's "Start exam (chapter-wise)" button land here
// with that exact chapter already checked, ready to submit — read once
// via location.state rather than a query string, matching how
// CertificatePage already receives its own navigation-only state.
interface InitialSelection {
  classId: number
  subjectId: number
  chapterId: number
}

function initialSelectionFromState(state: unknown): InitialSelection | null {
  if (typeof state !== 'object' || state === null) return null
  const candidate = state as Partial<InitialSelection>

  return typeof candidate.classId === 'number' &&
    typeof candidate.subjectId === 'number' &&
    typeof candidate.chapterId === 'number'
    ? (candidate as InitialSelection)
    : null
}

// The chapter pool is deliberately merged across every school's version of
// the chosen class — this is a global/national practice exam, not scoped to
// the student's own school, so the label intentionally omits which school a
// chapter came from (unlike e.g. VideoPlaylistPage's version chip).
function chapterLabel(entry: ChapterWithBook): string {
  const chapterName = entry.chapter.bn_name ?? entry.chapter.name
  const bookName = entry.book.bn_name ?? entry.book.name

  return `${chapterName} — ${bookName}`
}

export default function ExamsPage() {
  const { token, userId } = useAuth()
  const { t } = useLocale()
  const navigate = useNavigate()
  const location = useLocation()

  const [initialSelection] = useState(() => initialSelectionFromState(location.state))
  // Set when ExamRunnerPage redirects here because a tracked attempt for
  // some exam was already started and the page got reloaded/revisited —
  // see examInProgressKey()'s own comment there for why that redirect
  // exists at all.
  const [sessionEndedMessage] = useState(
    () => (location.state as { examSessionEnded?: boolean } | null)?.examSessionEnded === true,
  )
  const initialChapterAppliedRef = useRef(false)

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classesById, setClassesById] = useState<Map<number, SchoolClass>>(new Map())
  const [classId, setClassId] = useState<number | null>(initialSelection?.classId ?? null)

  // Same pattern as BooksPage.tsx/VideoLecturePage.tsx — a logged-in
  // student's own class locks the filter (shown as a badge), UNLESS a
  // specific class already arrived via initialSelection (VideoPlaylistPage's
  // "start exam" button), which takes priority.
  const [ownClassLocked, setOwnClassLocked] = useState(false)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectId, setSubjectId] = useState<number | null>(initialSelection?.subjectId ?? null)
  // Both class/subject-change effects below reset downstream selections by
  // design (picking a different class invalidates the previous subject,
  // etc.) — these track the LAST classId/subjectId the effect actually ran
  // with, so the reset fires only on a genuine change, not on mount (where
  // classId/subjectId may already be seeded from initialSelection) and not
  // on StrictMode's deliberate double-invoke of the same effect (a "skip
  // exactly once" boolean flag would get consumed by the first invocation
  // and incorrectly fire on the second).
  const previousClassIdRef = useRef(classId)
  const previousClassSubjectKeyRef = useRef(`${classId}:${subjectId}`)

  const [chapters, setChapters] = useState<ChapterWithBook[]>([])
  const [checkedChapterIds, setCheckedChapterIds] = useState<Set<number>>(new Set())
  const [chaptersLoading, setChaptersLoading] = useState(false)

  const [config, setConfig] = useState<PracticeExamConfig | null>(null)

  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [pastAttempts, setPastAttempts] = useState<ExamAttempt[]>([])

  useEffect(() => {
    if (!token) {
      setPastAttempts([])
      return
    }

    let active = true

    // Deliberately not cached (unlike ownClassId()/allClasses() above) —
    // submitting a new practice exam should show up here on the very next
    // visit to this page, same reasoning as ClassExamsPage's dashboard
    // fetch.
    listMyExamAttempts({ perPage: 50 }, token)
      .then((result) => {
        if (active) setPastAttempts(result.data)
      })
      .catch(() => {
        if (active) setPastAttempts([])
      })

    return () => {
      active = false
    }
  }, [token])

  useEffect(() => {
    allClasses()
      .then((result) => {
        setClasses(result)
        setClassesById(new Map(result.map((schoolClass) => [schoolClass.id, schoolClass])))
      })
      .catch(() => setClasses([]))

    getPracticeExamConfig()
      .then(setConfig)
      .catch(() => setConfig(null))
  }, [])

  useEffect(() => {
    if (!token || !userId || initialSelection) return

    let active = true

    ownClassId(userId, token)
      .then((result) => {
        if (!active || result === null) return
        setClassId(result)
        setOwnClassLocked(true)
      })
      .catch(() => {})

    return () => {
      active = false
    }
  }, [token, userId, initialSelection])

  useEffect(() => {
    if (previousClassIdRef.current !== classId) {
      setSubjectId(null)
    }
    previousClassIdRef.current = classId

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
    const key = `${classId}:${subjectId}`
    if (previousClassSubjectKeyRef.current !== key) {
      setCheckedChapterIds(new Set())
    }
    previousClassSubjectKeyRef.current = key

    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    setChaptersLoading(true)

    chaptersForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (!active) return
        setChapters(result)

        if (
          initialSelection &&
          !initialChapterAppliedRef.current &&
          result.some((entry) => entry.chapter.id === initialSelection.chapterId)
        ) {
          setCheckedChapterIds(new Set([initialSelection.chapterId]))
          initialChapterAppliedRef.current = true
        }
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
  }, [classId, subjectId, initialSelection])

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
  const ownClass = ownClassLocked && classId !== null ? classesById.get(classId) : undefined

  return (
    <section className="mx-auto max-w-4xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.practiceExam.title']}</h1>

      {sessionEndedMessage && <WarningAlert message={t['student.exams.sessionEnded']} />}

      <div className="mt-4 flex flex-wrap gap-3">
        {ownClassLocked ? (
          <span className="inline-flex h-10 items-center rounded-md bg-primary-50 px-3 text-sm font-semibold text-primary-700">
            {t['student.practiceExam.yourClass'].replace('{class}', ownClass ? (ownClass.bn_name ?? ownClass.name) : '')}
          </span>
        ) : (
          <select
            className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm"
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
        )}

        <select
          className="h-10 min-w-[12rem] rounded-md border border-primary-200 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
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

      {!classId && <InfoAlert message={t['student.practiceExam.pickClass']} />}

      {classId && !subjectId && <InfoAlert message={t['student.practiceExam.pickSubject']} />}

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
                {chapterLabel(entry)}
              </label>
            ))}
          </div>

          {hint && <WarningAlert message={hint} />}

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

      {pastAttempts.length > 0 && (
        <>
          <h2 className="mt-10 text-lg font-semibold text-primary-800">{t['student.practiceExam.scoresTitle']}</h2>

          <div className="mt-3 overflow-x-auto rounded-lg border border-gray-100">
            <table className="w-full min-w-[32rem] text-left text-sm">
              <thead>
                <tr>
                  <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                    {t['student.practiceExam.colChapter']}
                  </th>
                  <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                    {t['student.practiceExam.colScore']}
                  </th>
                  <th className="bg-primary-50 px-5 py-3 font-medium whitespace-nowrap text-primary-900">
                    {t['student.practiceExam.colResult']}
                  </th>
                </tr>
              </thead>
              <tbody>
                {pastAttempts.map((attempt) => {
                  // PracticeExamController::store() names the exam after the
                  // chapter(s) it was built from (comma-joined when several
                  // were picked), so this is the real chapter name — not a
                  // synthetic label.
                  const chapterName = attempt.exam ? (attempt.exam.bn_name ?? attempt.exam.name) : ''

                  return (
                    <tr key={attempt.id}>
                      <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                        {chapterName}
                      </td>
                      <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap text-gray-500">
                        {attempt.score ?? 0} / {attempt.total_possible_marks ?? 0}
                      </td>
                      <td className="border-b border-gray-100 px-5 py-4 whitespace-nowrap">
                        {attempt.passed === null ? (
                          <span className="text-gray-500">—</span>
                        ) : (
                          <span
                            className={`rounded-sm px-2 py-0.5 text-xs font-medium ${
                              attempt.passed ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600'
                            }`}
                          >
                            {attempt.passed ? t['student.exams.passed'] : t['student.exams.failed']}
                          </span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}
