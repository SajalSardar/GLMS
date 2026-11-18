import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  ApiError,
  getExam,
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
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

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
      if (!(error instanceof ApiError) || !isEnrollmentMismatch(error)) {
        throw error
      }
      // Falls through to the guest path below — this student is logged
      // in but not enrolled in this exam's class/year, so tracking isn't
      // possible; browsing/taking it still is.
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
      questionText: question.question_text,
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
        questionText: question?.question_text ?? '',
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

  const [status, setStatus] = useState<RunnerStatus>('setup')
  // The setup screen's own exam-preview fetch (name only, for the "Start"
  // screen) — separate from `setupError` below, which is specifically
  // about a failed START attempt. This fetch is a plain GET with no side
  // effects, so it's safe to run eagerly on mount/examId change, unlike
  // starting an attempt.
  const [examPreview, setExamPreview] = useState<Exam | null>(null)
  const [examLoadError, setExamLoadError] = useState<string | null>(null)
  const [starting, setStarting] = useState(false)
  const [setupError, setSetupError] = useState<string | null>(null)
  const [questions, setQuestions] = useState<ExamQuestion[]>([])
  const [attemptId, setAttemptId] = useState<number | null>(null)
  const [answers, setAnswers] = useState<Record<number, number[]>>({})
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [result, setResult] = useState<ExamResultView | null>(null)

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
      // Save-as-you-go convenience only — never blocks the UI, and a
      // failure here is not worth surfacing as a user-facing error.
      saveExamAttemptAnswer(attemptId, question.id, next, token).catch((error) => {
        console.warn('Failed to save exam attempt answer', error)
      })
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
        const attemptResult = await submitExamAttempt(attemptId, token)
        setResult(resultFromAttempt(attemptResult.data))
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
      <section className="mx-auto max-w-3xl px-4 py-8 lg:px-8">
        {examLoadError ? (
          <>
            <p className="text-sm text-danger-600">{examLoadError}</p>
            <Link to="/exams" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
              {t['student.exams.title']}
            </Link>
          </>
        ) : !examPreview ? (
          <p className="text-sm text-primary-600">{t['student.exams.loading']}</p>
        ) : (
          <>
            <h1 className="text-2xl font-bold text-primary-800">{examPreview.name}</h1>

            {setupError && <p className="mt-4 text-sm text-danger-600">{setupError}</p>}

            <button
              type="button"
              onClick={handleStart}
              disabled={starting}
              className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-primary-600 disabled:cursor-default disabled:bg-primary-300"
            >
              {starting ? t['student.exams.loading'] : t['student.exams.start']}
            </button>
          </>
        )}
      </section>
    )
  }

  if (status === 'in_progress') {
    return (
      <section className="mx-auto max-w-3xl px-4 py-8 lg:px-8">
        <div className="flex flex-col gap-6">
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
          disabled={submitting}
          className="mt-6 cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:enabled:bg-primary-600 disabled:cursor-default disabled:bg-primary-300"
        >
          {submitting ? t['student.exams.submitting'] : t['student.exams.submit']}
        </button>
      </section>
    )
  }

  // status === 'submitted'
  return (
    <section className="mx-auto max-w-3xl px-4 py-8 lg:px-8">
      {result && (
        <>
          <div className="rounded-lg border border-primary-100 p-4">
            <p className="text-lg font-semibold text-primary-800">
              {t['student.exams.score']}: {result.score ?? 0} / {result.totalPossibleMarks ?? 0}
            </p>
            {result.passed !== null && (
              <p className={`mt-1 text-sm font-medium ${result.passed ? 'text-success-600' : 'text-danger-600'}`}>
                {result.passed ? t['student.exams.passed'] : t['student.exams.failed']}
              </p>
            )}
          </div>

          <div className="mt-6 flex flex-col gap-6">
            {result.questions.map((question, index) => (
              <div key={question.questionId} className="rounded-lg border border-primary-100 p-4">
                <p className="font-semibold text-primary-800">
                  {index + 1}. {question.questionText}
                </p>
                <div className="mt-3 flex flex-col gap-1 text-sm">
                  {question.options.map((option) => {
                    const wasSelected = question.selectedOptionIds.includes(option.id)
                    const isCorrectOption = question.correctOptionIds.includes(option.id)

                    let tone = 'text-primary-800'
                    if (isCorrectOption) {
                      tone = 'text-success-600 font-medium'
                    } else if (wasSelected) {
                      tone = 'text-danger-600'
                    }

                    return (
                      <p key={option.id} className={tone}>
                        {option.option_text}
                        {wasSelected && ` (${t['student.exams.yourAnswer']})`}
                        {isCorrectOption && ` (${t['student.exams.correctAnswer']})`}
                      </p>
                    )
                  })}
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      <Link to="/exams" className="mt-6 inline-block text-sm font-semibold text-primary-700 underline">
        {t['student.exams.title']}
      </Link>
    </section>
  )
}
