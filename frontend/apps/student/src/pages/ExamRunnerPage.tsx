import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  getExam,
  getPracticeExamConfig,
  randomDrawExamQuestions,
  saveExamAttemptAnswer,
  startExamAttempt,
  submitExamAttempt,
  submitGuestExamAttempt,
  type Exam,
  type ExamAttempt,
  type ExamAttemptQuestionResult,
  type ExamQuestion,
  type ExamQuestionLink,
  type ExamQuestionOption,
  type GuestExamAnswerInput,
  type GuestExamSubmissionResult,
  type PracticeExamConfig,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import type { CommonStrings } from '@ttla/i18n'
import { useLocale } from '../lib/locale'

// Same design as ExamsPage.tsx's own WarningAlert — kept local rather than
// shared since this is the only other page that needs it.
function WarningAlert({ message }: { message: string }) {
  return (
    <div className="mt-4 flex items-center gap-3 rounded-lg border border-warning-500 bg-warning-50 px-4 py-3 text-left text-sm font-medium text-[#8a6d00]">
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

type RunnerStatus = 'setup' | 'in_progress' | 'submitted'

interface ExamResultQuestionView {
  questionId: number
  questionText: string
  options: ExamQuestionOption[]
  selectedOptionIds: number[]
  correctOptionIds: number[]
  isCorrect: boolean | null
}

interface ExamResultView {
  score: number | null
  totalPossibleMarks: number | null
  passed: boolean | null
  questions: ExamResultQuestionView[]
}

/**
 * Verbatim string from ExamAttemptController::start() (confirmed by reading
 * backend/app/Http/Controllers/Api/V1/ExamAttemptController.php at the time
 * this was written) — re-check that file if this stops matching. Detection
 * must be this exact string, not "any 422 from this endpoint": start() also
 * throws 422s for an exam that isn't publicly takeable, used-up attempts,
 * and an undersized random pool — none of those mean "not enrolled, fall
 * back to guest", they mean the exam genuinely cannot be taken right now,
 * and that must surface as a real error instead of silently degrading.
 */
const ENROLLMENT_MISMATCH_MESSAGE = 'You are not enrolled in the class version/academic year this exam belongs to.'

function isEnrollmentMismatch(error: ApiError): boolean {
  const message = error.errors?.exam?.[0] ?? error.message
  return message === ENROLLMENT_MISMATCH_MESSAGE
}

function messageFromApiError(error: unknown): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? error.message
  }

  return error instanceof Error ? error.message : String(error)
}

function questionFromAttemptQuestion(question: ExamAttemptQuestionResult): ExamQuestion {
  return {
    id: question.question_id,
    question_type: question.question_type,
    question_text: question.question_text,
    bn_question_text: question.bn_question_text,
    marks: question.marks,
    order_index: question.order_index,
    options: question.options,
  }
}

/**
 * The manual/fixed_random branch below reads `exam.questions` (from
 * getExam()), which is an `ExamQuestionLink[]` — the `exam_questions` pivot
 * row wrapping each actual question (confirmed by reading
 * backend/app/Http/Resources/Api/V1/ExamQuestionResource.php). Unwrap it
 * here into the flat `ExamQuestion` shape every other path already
 * produces, keeping the pivot's own `order_index` (the question's position
 * within THIS exam) rather than the question's own `order_index` (its
 * position within its chapter's question bank).
 */
function questionFromExamQuestionLink(link: ExamQuestionLink): ExamQuestion {
  return { ...link.question, order_index: link.order_index }
}

// Marks a TRACKED attempt (attemptId !== null — guests never persist an
// attempt server-side, so they're never flagged) as already started for
// this exam in this browser tab. startExamAttempt() is not idempotent: a
// page refresh mid-exam would otherwise lose all in-memory progress and
// then, on the student clicking Start again, silently create a SECOND
// ExamAttempt row and burn another one of the exam's max_attempts. The
// main reset effect below checks this flag on every mount and, if set,
// redirects to /exams instead of showing the Start screen again.
function examInProgressKey(examId: number): string {
  return `ttla.examInProgress.${examId}`
}

type ResultTier = 'failed' | 'pass' | 'good' | 'best'

function tierFor(percent: number, config: PracticeExamConfig): ResultTier {
  if (percent < config.tier_pass_percent) return 'failed'
  if (percent < config.tier_good_percent) return 'pass'
  if (percent < config.tier_best_percent) return 'good'
  return 'best'
}

const TIER_LABEL_KEY: Record<ResultTier, keyof CommonStrings> = {
  failed: 'student.exams.tierFailed',
  pass: 'student.exams.tierPass',
  good: 'student.exams.tierGood',
  best: 'student.exams.tierBest',
}

const TIER_TONE: Record<ResultTier, string> = {
  failed: 'text-danger-600',
  pass: 'text-success-600',
  good: 'text-success-600',
  best: 'text-success-600',
}

// The setup card's question count: for per_attempt_random exams the exact
// set isn't drawn until handleStart() actually runs, but
// random_question_count is already known from the exam row itself; other
// modes already carry their fixed question list via getExam()'s eager load.
function totalQuestionCount(exam: Exam): number {
  return exam.selection_mode === 'per_attempt_random'
    ? (exam.random_question_count ?? 0)
    : (exam.questions?.length ?? 0)
}

// Practice exams never set a real time_limit_minutes (PracticeExamController
// doesn't — it's a teacher-exam-only field), so the fallback of "1 minute
// per question" is what actually applies there; a teacher-created exam
// with its own explicit time limit uses that instead.
function totalExamMinutes(exam: Exam): number {
  return exam.time_limit_minutes ?? totalQuestionCount(exam)
}

// PracticeExamController::store() now sets name/bn_name from the
// submitted chapters' own names (previously a synthetic random slug with
// a fixed Bangla label, which this function used to substitute for), so
// both practice and teacher-created exams show their real name the same
// way — the setup card's type badge (see the 'setup' render below)
// already communicates which kind this is.
function examTitle(exam: Exam): string {
  return exam.bn_name ?? exam.name
}

function formatClock(totalSeconds: number): string {
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes}:${seconds.toString().padStart(2, '0')}`
}

interface LoadResult {
  questions: ExamQuestion[]
  attemptId: number | null
  selectionMode: Exam['selection_mode']
}

async function loadQuestionsAndMaybeStartAttempt(examId: number, token: string | null): Promise<LoadResult> {
  const examResult = await getExam(examId)
  const exam = examResult.data

  if (token) {
    try {
      const attemptResult = await startExamAttempt(examId, token)
      const attempt = attemptResult.data
      return {
        questions: (attempt.questions ?? []).map(questionFromAttemptQuestion),
        attemptId: attempt.id,
        selectionMode: exam.selection_mode,
      }
    } catch (error) {
      // A 403 here means the token's role lacks `exam_attempts.create`
      // (only the student role has it — reachable because this app shares
      // its auth token with the teacher/management apps via the same
      // localStorage key on a shared origin). That's functionally the
      // same situation as "not enrolled": tracking isn't possible, but
      // taking the exam still should be, so it falls through to the guest
      // path below just like an enrollment mismatch does.
      const isForbidden = error instanceof ApiError && error.status === 403
      if (!(error instanceof ApiError) || !(isEnrollmentMismatch(error) || isForbidden)) {
        throw error
      }
      // Falls through to the guest path below.
    }
  }

  if (exam.selection_mode === 'per_attempt_random') {
    const drawResult = await randomDrawExamQuestions(examId)
    return { questions: drawResult.data, attemptId: null, selectionMode: exam.selection_mode }
  }

  return {
    questions: (exam.questions ?? []).map(questionFromExamQuestionLink),
    attemptId: null,
    selectionMode: exam.selection_mode,
  }
}

function resultFromAttempt(attempt: ExamAttempt): ExamResultView {
  return {
    score: attempt.score,
    totalPossibleMarks: attempt.total_possible_marks,
    passed: attempt.passed,
    questions: (attempt.questions ?? []).map((question) => ({
      questionId: question.question_id,
      questionText: question.bn_question_text ?? question.question_text,
      options: question.options,
      selectedOptionIds: question.selected_option_ids ?? [],
      correctOptionIds: question.options.filter((option) => option.is_correct).map((option) => option.id),
      isCorrect: question.is_correct,
    })),
  }
}

function resultFromGuestSubmission(result: GuestExamSubmissionResult, questions: ExamQuestion[]): ExamResultView {
  const questionById = new Map(questions.map((question) => [question.id, question]))

  return {
    score: result.score,
    totalPossibleMarks: result.total_possible_marks,
    passed: result.passed,
    questions: result.answers.map((answer) => {
      const question = questionById.get(answer.question_id)

      return {
        questionId: answer.question_id,
        questionText: question?.bn_question_text ?? question?.question_text ?? '',
        options: question?.options ?? [],
        selectedOptionIds: answer.selected_option_ids,
        correctOptionIds: answer.correct_option_ids,
        isCorrect: answer.is_correct,
      }
    }),
  }
}

export default function ExamRunnerPage() {
  const { id } = useParams<{ id: string }>()
  const examId = Number(id)
  const { token } = useAuth()
  const { t } = useLocale()

  const [status, setStatus] = useState<RunnerStatus>('setup')
  // The setup screen's own exam-preview fetch (name only, for the "Start"
  // screen) — separate from `setupError` below, which is specifically
  // about a failed START attempt. This fetch is a plain GET with no side
  // effects, so it's safe to run eagerly on mount/examId change, unlike
  // starting an attempt.
  const [examPreview, setExamPreview] = useState<Exam | null>(null)
  const [config, setConfig] = useState<PracticeExamConfig | null>(null)
  const navigate = useNavigate()
  const [examLoadError, setExamLoadError] = useState<string | null>(null)
  const [starting, setStarting] = useState(false)
  const [setupError, setSetupError] = useState<string | null>(null)
  const [questions, setQuestions] = useState<ExamQuestion[]>([])
  const [attemptId, setAttemptId] = useState<number | null>(null)
  const [answers, setAnswers] = useState<Record<number, number[]>>({})
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [result, setResult] = useState<ExamResultView | null>(null)

  // null until handleStart() seeds it (see there) — counts down once per
  // second while status is 'in_progress'. Deliberately never auto-submits
  // at zero: it just stops at 0 and blocks the Submit button (see the
  // in_progress render below) rather than risk submitting whatever
  // happened to be selected at the exact moment it expired.
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(null)

  useEffect(() => {
    if (status !== 'in_progress') return

    const interval = setInterval(() => {
      setRemainingSeconds((previous) => (previous === null || previous <= 0 ? previous : previous - 1))
    }, 1000)

    return () => clearInterval(interval)
  }, [status])

  // The latest in-flight saveExamAttemptAnswer() promise per question —
  // handleOptionChange() fires that save on every change without awaiting
  // it (so typing/clicking never blocks on network), which means a student
  // selecting an answer and immediately clicking Submit could otherwise
  // have the POST /submit land before the PUT /answers/{q} that was still
  // in flight, grading without an answer the student visibly selected.
  // handleSubmit() awaits every promise still in this map before
  // submitting, on the tracked path only.
  const savePromisesRef = useRef<Map<number, Promise<unknown>>>(new Map())

  // Fetches only the exam's own details for the "Start" screen — NOT
  // startExamAttempt(), which is deliberately deferred to an explicit
  // click (see handleStart below). startExamAttempt() is NOT idempotent —
  // every call creates a brand new ExamAttempt row and consumes one of
  // the exam's max_attempts — so firing it automatically on mount meant
  // an ordinary browser refresh on this page silently burned one of the
  // student's attempts (confirmed during verification: this is exactly
  // how an exhausted-attempts dead end was reached without the student
  // ever clicking anything). Gating the real start behind a user click
  // also means React StrictMode's dev-mode double-invoked effects can no
  // longer double-fire it, since effects no longer call it at all.
  useEffect(() => {
    // Reset all per-exam state when navigating from one exam straight to
    // another — ExamRunnerPage is the same mounted component instance for
    // every `exams/:id` route, so without this, answers/attemptId/result
    // from the previous exam would still be visible for the new one.
    setStatus('setup')
    setExamPreview(null)
    setExamLoadError(null)
    setSetupError(null)
    setQuestions([])
    setAttemptId(null)
    setAnswers({})
    setResult(null)
    setRemainingSeconds(null)
    savePromisesRef.current.clear()

    // A tracked attempt for this exact exam was already started in this
    // browser tab (set in handleStart() below) and never got submitted —
    // reaching this page again, whether via a reload/refresh or navigating
    // back to the same URL, must not show the Start screen again: clicking
    // Start a second time would silently create another real ExamAttempt
    // and burn another one of the exam's max_attempts. Redirect to the
    // exam list with an explanatory message instead.
    if (examId && sessionStorage.getItem(examInProgressKey(examId)) === '1') {
      navigate('/exams', { replace: true, state: { examSessionEnded: true } })
      return
    }

    if (!examId) {
      setExamLoadError(t['student.exams.notFound'])
      return
    }

    let active = true

    getExam(examId)
      .then((result) => {
        if (active) setExamPreview(result.data)
      })
      .catch((error) => {
        if (active) setExamLoadError(messageFromApiError(error))
      })

    getPracticeExamConfig()
      .then((result) => {
        if (active) setConfig(result)
      })
      .catch(() => {
        if (active) setConfig(null)
      })

    return () => {
      active = false
    }
  }, [examId])

  async function handleStart() {
    if (starting) return // guards against a double-click firing two real attempts

    setStarting(true)
    setSetupError(null)

    try {
      const loaded = await loadQuestionsAndMaybeStartAttempt(examId, token)
      setQuestions(loaded.questions)
      setAttemptId(loaded.attemptId)
      if (loaded.attemptId !== null) {
        // Guests never persist an attempt server-side, so only a genuinely
        // tracked start is flagged — see examInProgressKey()'s own comment.
        sessionStorage.setItem(examInProgressKey(examId), '1')
      }
      if (examPreview) {
        setRemainingSeconds(totalExamMinutes(examPreview) * 60)
      }
      setStatus('in_progress')
    } catch (error) {
      setSetupError(messageFromApiError(error))
    } finally {
      setStarting(false)
    }
  }

  function handleOptionChange(question: ExamQuestion, optionId: number, checked: boolean) {
    const current = answers[question.id] ?? []
    const next =
      question.question_type === 'mcq_multiple'
        ? checked
          ? [...current, optionId]
          : current.filter((selectedId) => selectedId !== optionId)
        : checked
          ? [optionId]
          : []

    setAnswers((previous) => ({ ...previous, [question.id]: next }))

    if (attemptId !== null && token) {
      // Save-as-you-go convenience only — never awaited here, so it can't
      // block the UI, and a failure is not worth surfacing as a
      // user-facing error. The promise is still tracked in
      // savePromisesRef so handleSubmit() can wait for the latest one per
      // question before submitting — see the ref's own comment above.
      const savePromise = saveExamAttemptAnswer(attemptId, question.id, next, token).catch((error) => {
        console.warn('Failed to save exam attempt answer', error)
      })
      savePromisesRef.current.set(question.id, savePromise)
    }
  }

  async function handleSubmit() {
    // The tracked path can never reach here with zero questions —
    // ExamAttemptController::start() already rejects that case with
    // 'This exam has no questions configured.' before an attempt is even
    // created. The guest path has no equivalent guard on the backend: a
    // manual/fixed_random exam with zero configured questions reaches
    // 'in_progress' successfully with an empty question list, and
    // submitting an empty `answers: []` array would otherwise fail on
    // SubmitGuestExamAttemptRequest's generic 'answers' validation
    // message instead of a clear explanation. Catch it here instead.
    if (attemptId === null && questions.length === 0) {
      setSubmitError(t['student.exams.noQuestions'])
      return
    }

    setSubmitting(true)
    setSubmitError(null)

    try {
      if (attemptId !== null && token) {
        // Let every answer save still in flight land before submitting —
        // otherwise POST /submit can race a PUT /answers/{q} that hasn't
        // committed yet, grading without an answer the student visibly
        // selected. The guest path below builds its payload from local
        // `answers` state at submit time rather than saved server-side
        // answers, so it has no equivalent race and doesn't need this.
        await Promise.allSettled([...savePromisesRef.current.values()])

        const attemptResult = await submitExamAttempt(attemptId, token)
        setResult(resultFromAttempt(attemptResult.data))
        // Successfully submitted — this exam is done, so the refresh guard
        // no longer applies (there's nothing left to protect against
        // double-starting).
        sessionStorage.removeItem(examInProgressKey(examId))
      } else {
        const guestAnswers: GuestExamAnswerInput[] = questions.map((question) => ({
          question_id: question.id,
          selected_option_ids: answers[question.id] ?? [],
        }))
        const guestResult = await submitGuestExamAttempt(examId, guestAnswers)
        setResult(resultFromGuestSubmission(guestResult, questions))
      }

      setStatus('submitted')
    } catch (error) {
      setSubmitError(messageFromApiError(error))
    } finally {
      setSubmitting(false)
    }
  }

  if (status === 'setup') {
    return (
      <section className="mx-auto flex min-h-[60vh] max-w-3xl items-center justify-center px-4 py-8 lg:px-8">
        {examLoadError ? (
          <div className="w-full max-w-md rounded-2xl border border-primary-100 bg-white p-8 text-center shadow-sm">
            <p className="text-sm text-danger-600">{examLoadError}</p>
            <Link to="/exams" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
              {t['student.exams.title']}
            </Link>
          </div>
        ) : !examPreview ? (
          <p className="text-sm text-primary-600">{t['student.exams.loading']}</p>
        ) : !token && !examPreview.is_practice ? (
          <div className="w-full max-w-md rounded-2xl border border-primary-100 bg-white p-8 text-center shadow-sm">
            <h1 className="text-2xl font-bold text-primary-800">{examTitle(examPreview)}</h1>
            <p className="mt-4 text-sm text-danger-600">{t['student.exams.loginRequired']}</p>
            <Link to="/login" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
              {t['student.nav.signIn']}
            </Link>
          </div>
        ) : (
          <div className="w-full max-w-md rounded-2xl border border-primary-100 bg-white p-8 text-center shadow-sm">
            <span className="inline-block rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700">
              {examPreview.is_practice ? t['student.nav.exams'] : t['student.nav.classExams']}
            </span>
            <h1 className="mt-3 text-xl font-bold text-primary-800">{examTitle(examPreview)}</h1>
            <p className="mt-1 text-sm text-primary-600">
              {examPreview.is_practice ? t['student.exams.practiceExamNote'] : t['student.exams.classExamNote']}
            </p>

            <dl className="mt-6 flex flex-col gap-3 text-left">
              <div className="flex items-center justify-between rounded-lg bg-primary-50 px-4 py-3">
                <dt className="text-sm text-primary-600">{t['student.exams.totalQuestions']}</dt>
                <dd className="text-sm font-semibold text-primary-800">{totalQuestionCount(examPreview)}</dd>
              </div>
              <div className="flex items-center justify-center rounded-lg bg-primary-50 px-4 py-3">
                <dd className="text-sm font-medium text-primary-700">
                  {t['student.exams.totalExamTime'].replace('{minutes}', String(totalExamMinutes(examPreview)))}
                </dd>
              </div>
              <div className="flex items-center justify-center rounded-lg bg-primary-50 px-4 py-3">
                <dd className="text-sm font-medium text-primary-700">{t['student.exams.perQuestionTime']}</dd>
              </div>
            </dl>

            <WarningAlert message={t['student.exams.noRefreshWarning']} />

            {setupError && <p className="mt-4 text-sm text-danger-600">{setupError}</p>}

            <button
              type="button"
              onClick={handleStart}
              disabled={starting}
              className="mt-6 w-full cursor-pointer rounded-md bg-primary-500 px-4 py-2.5 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
            >
              {starting ? t['student.exams.loading'] : t['student.exams.start']}
            </button>
          </div>
        )}
      </section>
    )
  }

  if (status === 'in_progress') {
    const timeExpired = remainingSeconds !== null && remainingSeconds <= 0
    const oneMinuteLeft = remainingSeconds !== null && remainingSeconds > 0 && remainingSeconds <= 60

    return (
      <section className="mx-auto max-w-3xl px-4 py-8 lg:px-8">
        {remainingSeconds !== null && (
          <div className="sticky top-16 z-10 mb-4 flex justify-center">
            <span
              className={`rounded-full px-4 py-1.5 text-sm font-semibold ${
                timeExpired
                  ? 'bg-danger-100 text-danger-600'
                  : oneMinuteLeft
                    ? 'bg-warning-50 text-[#8a6d00]'
                    : 'bg-primary-50 text-primary-700'
              }`}
            >
              {t['student.exams.timeRemaining']}: {formatClock(Math.max(0, remainingSeconds))}
            </span>
          </div>
        )}

        {oneMinuteLeft && (
          <div className="mb-4 flex items-center gap-3 rounded-lg border border-warning-500 bg-warning-50 px-4 py-3 text-sm font-medium text-[#8a6d00]">
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
            <p>{t['student.exams.oneMinuteLeft']}</p>
          </div>
        )}

        {timeExpired && (
          <div className="mb-4 flex items-center gap-3 rounded-lg border border-danger-400 bg-danger-50 px-4 py-3 text-sm font-medium text-danger-600">
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
              <path d="M12 7v6l4 2" />
            </svg>
            <p>{t['student.exams.timeUp']}</p>
          </div>
        )}

        <div className="flex max-h-[32rem] flex-col gap-6 overflow-y-auto rounded-lg border border-primary-100 p-4">
          {questions.map((question, index) => {
            const selected = answers[question.id] ?? []
            const inputType = question.question_type === 'mcq_multiple' ? 'checkbox' : 'radio'

            return (
              <div key={question.id} className="rounded-lg border border-primary-100 p-4">
                <p className="font-semibold text-primary-800">
                  {index + 1}. {question.bn_question_text ?? question.question_text}
                </p>
                <div className="mt-3 flex flex-col gap-2">
                  {question.options.map((option) => (
                    <label key={option.id} className="flex items-center gap-2 text-sm text-primary-800">
                      <input
                        type={inputType}
                        name={`question-${question.id}`}
                        checked={selected.includes(option.id)}
                        onChange={(event) => handleOptionChange(question, option.id, event.target.checked)}
                        className="h-4 w-4"
                      />
                      {option.option_text}
                    </label>
                  ))}
                </div>
              </div>
            )
          })}
        </div>

        {submitError && <p className="mt-4 text-sm text-danger-600">{submitError}</p>}

        <button
          type="button"
          onClick={handleSubmit}
          disabled={submitting || timeExpired}
          className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-primary-300"
        >
          {submitting ? t['student.exams.submitting'] : t['student.exams.submit']}
        </button>
      </section>
    )
  }

  // status === 'submitted' — only the score card is shown, centered on
  // the page; the per-question answer breakdown and question list are
  // deliberately not rendered here anymore.
  return (
    <section className="mx-auto flex min-h-[60vh] max-w-3xl items-center justify-center px-4 py-8 lg:px-8">
      {result && (
        <div className="w-full max-w-md rounded-2xl border border-primary-100 bg-white p-8 text-center shadow-sm">
          <p className="text-lg font-semibold text-primary-800">
            {t['student.exams.score']}: {result.score ?? 0} / {result.totalPossibleMarks ?? 0}
          </p>
          {/*
            The tiered verdict belongs to PRACTICE exams only. A
            teacher-created exam has its own passing_score_percent, and
            the backend already graded this attempt against it into
            `result.passed` — applying the practice tier cutoffs here
            would contradict that verdict (a student failing a teacher's
            exam could be shown "Passed"). Non-practice exams therefore
            keep the original, simple passed/failed line, and a practice
            exam whose config failed to load falls back to the same line
            rather than showing no verdict at all. The "View certificate"
            button is intentionally not shown here — see the user request
            that removed it.
          */}
          {examPreview?.is_practice && config && result.totalPossibleMarks !== null && result.totalPossibleMarks > 0 ? (
            (() => {
              const tier = tierFor(((result.score ?? 0) / result.totalPossibleMarks) * 100, config)
              return <p className={`mt-1 text-sm font-medium ${TIER_TONE[tier]}`}>{t[TIER_LABEL_KEY[tier]]}</p>
            })()
          ) : (
            result.passed !== null && (
              <p className={`mt-1 text-sm font-medium ${result.passed ? 'text-success-600' : 'text-danger-600'}`}>
                {result.passed ? t['student.exams.passed'] : t['student.exams.failed']}
              </p>
            )
          )}

          <Link
            to={examPreview?.is_practice ? '/exams' : '/class-exams'}
            className="mt-6 block w-full cursor-pointer rounded-md bg-primary-500 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-[#EB1F27]"
          >
            {examPreview?.is_practice ? t['student.exams.goToExamsPage'] : t['student.classExams.goToClassExamsPage']}
          </Link>
        </div>
      )}
    </section>
  )
}
