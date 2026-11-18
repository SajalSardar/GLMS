import { useEffect, useRef, useState } from 'react'
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
    bn_question_text: null,
    marks: question.marks,
    order_index: question.order_index,
    options: question.options,
  }
}

/**
 * The manual/fixed_random branch below reads `exam.questions` (from
 * getExam()), which the backend serializes via ExamQuestionResource —
 * `{ id: <pivot id>, order_index: <pivot order>, question: QuestionResource }`
 * (confirmed by reading
 * backend/app/Http/Resources/Api/V1/ExamQuestionResource.php) — not the
 * flat shape the api-client's `ExamQuestion` type declares (which is what
 * randomDrawExamQuestions()/the tracked attempt's `questions` actually
 * return). Unwrap that nesting here so every path feeds the same flat
 * shape into the rest of this component.
 */
function normalizeManualQuestion(raw: ExamQuestion): ExamQuestion {
  const nested = (raw as unknown as { question?: ExamQuestion }).question
  return nested ? { ...nested, order_index: raw.order_index } : raw
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
    questions: (exam.questions ?? []).map(normalizeManualQuestion),
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
  const [setupError, setSetupError] = useState<string | null>(null)
  const [questions, setQuestions] = useState<ExamQuestion[]>([])
  const [attemptId, setAttemptId] = useState<number | null>(null)
  const [answers, setAnswers] = useState<Record<number, number[]>>({})
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [result, setResult] = useState<ExamResultView | null>(null)

  // startExamAttempt() is NOT idempotent — every call creates a brand new
  // ExamAttempt row and consumes one of the exam's max_attempts. React 19's
  // StrictMode intentionally double-invokes effects in dev (mount -> fake
  // cleanup -> mount again) to surface exactly this kind of bug; without
  // this guard, that double-invoke created two real tracked attempts from
  // a single page load (confirmed against the isolated verification
  // backend — see the task report).
  //
  // The in-flight/resolved promise itself (not just a boolean) is cached
  // on this ref, keyed by (examId, token): the first effect invocation
  // creates it and kicks off the one real network call; StrictMode's
  // synthetic second invocation reuses the SAME promise instead of
  // starting another attempt. Both invocations still attach their own
  // .then/.catch below, each gated by its own `active` closure — the
  // first invocation's `active` is flipped false by StrictMode's fake
  // cleanup before the promise resolves, so only the second (real,
  // surviving) invocation ends up applying the result to state. A
  // genuine new mount (actually navigating away and back) gets a fresh
  // ref and starts its own attempt.
  const attemptPromiseRef = useRef<{ key: string; promise: Promise<LoadResult> } | null>(null)

  useEffect(() => {
    if (!examId) {
      setSetupError(t['student.exams.empty'])
      return
    }

    const startKey = `${examId}:${token ?? ''}`

    if (attemptPromiseRef.current?.key !== startKey) {
      attemptPromiseRef.current = { key: startKey, promise: loadQuestionsAndMaybeStartAttempt(examId, token) }
    }

    const { promise } = attemptPromiseRef.current
    let active = true
    setStatus('setup')
    setSetupError(null)

    promise
      .then((loaded) => {
        if (!active) return
        setQuestions(loaded.questions)
        setAttemptId(loaded.attemptId)
        setStatus('in_progress')
      })
      .catch((error) => {
        if (!active) return
        attemptPromiseRef.current = null
        setSetupError(messageFromApiError(error))
      })

    return () => {
      active = false
    }
  }, [examId, token])

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
        {setupError ? (
          <>
            <p className="text-sm text-danger-600">{setupError}</p>
            <Link to="/exams" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
              {t['student.exams.title']}
            </Link>
          </>
        ) : (
          <p className="text-sm text-primary-600">{t['student.exams.loading']}</p>
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
