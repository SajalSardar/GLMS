import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, deleteQuestionPaper, listQuestionPapers, type QuestionPaper } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

// Deliberately duplicated in the management app's QuestionPapersPage —
// see this plan's Global Constraints on per-app duplication of shared
// rendering, not a cross-app packages/ui component.
function PrintableQuestionPaper({ paper }: { paper: QuestionPaper }) {
  const items = paper.items ?? []
  let questionNumber = 0

  return (
    <div className="mx-auto max-w-3xl bg-white p-8 text-gray-900 print:p-0">
      <div className="text-center">
        <h1 className="text-lg font-bold">{paper.title}</h1>
        <p className="mt-1 text-sm">
          {paper.school_class?.name ?? ''} — {paper.subject?.name ?? ''}
        </p>
        <div className="mt-2 flex justify-center gap-6 text-sm">
          {paper.time_duration_minutes !== null && <span>Time: {paper.time_duration_minutes} min</span>}
          {paper.full_marks !== null && <span>Full Marks: {paper.full_marks}</span>}
        </div>
        {paper.instructions && <p className="mt-2 text-sm italic">{paper.instructions}</p>}
      </div>

      <div className="mt-6 flex flex-col gap-3">
        {items.map((item) => {
          if (item.item_type === 'heading') {
            return (
              <h2 key={item.id} className="mt-4 text-base font-semibold underline">
                {item.heading_text}
              </h2>
            )
          }

          questionNumber += 1
          const question = item.academic_question

          return (
            <div key={item.id} className="flex justify-between gap-4">
              <div className="flex-1">
                <p className="whitespace-pre-wrap">
                  {questionNumber}. {question?.question_text}
                </p>
                {question?.question_type === 'mcq' && question.options && (
                  <ol className="mt-1 ml-5 list-none">
                    {question.options.map((option, index) => (
                      <li key={index}>
                        {String.fromCharCode(0x0995 + index)}) {option}
                      </li>
                    ))}
                  </ol>
                )}
              </div>
              <span className="shrink-0 text-sm">{question?.marks}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export default function MyQuestionPapersPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()
  const [papers, setPapers] = useState<QuestionPaper[]>([])
  const [loading, setLoading] = useState(true)
  const [printingPaper, setPrintingPaper] = useState<QuestionPaper | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  function loadPapers() {
    if (!token) return

    setLoading(true)
    listQuestionPapers(token)
      .then((result) => setPapers(result.data))
      .catch(() => setPapers([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadPapers()
  }, [token])

  function handlePrint(paper: QuestionPaper) {
    setPrintingPaper(paper)
    requestAnimationFrame(() => window.print())
  }

  async function handleDelete(paper: QuestionPaper) {
    if (!token || !window.confirm(t['teacher.myQuestionPapers.deleteConfirm'])) return

    setDeletingId(paper.id)

    try {
      await deleteQuestionPaper(paper.id, token)
      showToast({ type: 'success', message: t['teacher.myQuestionPapers.deleteSuccess'] })
      loadPapers()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.myQuestionPapers.deleteError'] })
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <div className="flex items-center justify-between print:hidden">
        <h1 className="text-xl font-semibold text-gray-900">{t['teacher.myQuestionPapers.title']}</h1>
        <Link
          to="/question-builder"
          className="inline-flex items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
          {t['teacher.myQuestionPapers.createNew']}
        </Link>
      </div>

      {loading && <p className="mt-4 text-sm text-gray-500 print:hidden">{t['teacher.myQuestionPapers.loading']}</p>}

      {!loading && papers.length === 0 && (
        <p className="mt-4 rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm print:hidden">
          {t['teacher.myQuestionPapers.empty']}
        </p>
      )}

      {!loading && papers.length > 0 && !printingPaper && (
        <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm print:hidden">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colTitle']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colClass']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colCreated']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colActions']}</th>
              </tr>
            </thead>
            <tbody>
              {papers.map((paper) => (
                <tr key={paper.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.title}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.school_class?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{paper.created_at.slice(0, 10)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={() => handlePrint(paper)}
                        aria-label={t['teacher.myQuestionPapers.print']}
                        title={t['teacher.myQuestionPapers.print']}
                        className="cursor-pointer text-gray-500 transition-colors hover:text-primary-500"
                      >
                        <span className="material-symbols-outlined !text-[18px]">print</span>
                      </button>
                      {paper.can_edit && (
                        <Link
                          to={`/question-builder/${paper.id}`}
                          aria-label={t['teacher.myQuestionPapers.edit']}
                          title={t['teacher.myQuestionPapers.edit']}
                          className="text-gray-500 transition-colors hover:text-primary-500"
                        >
                          <span className="material-symbols-outlined !text-[18px]">edit</span>
                        </Link>
                      )}
                      {paper.can_delete && (
                        <button
                          type="button"
                          disabled={deletingId === paper.id}
                          onClick={() => handleDelete(paper)}
                          aria-label={t['teacher.myQuestionPapers.delete']}
                          title={t['teacher.myQuestionPapers.delete']}
                          className="cursor-pointer text-danger-500 transition-colors hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          <span className="material-symbols-outlined !text-[18px]">
                            {deletingId === paper.id ? 'hourglass_top' : 'delete'}
                          </span>
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {printingPaper && (
        <>
          <button
            type="button"
            onClick={() => setPrintingPaper(null)}
            className="mt-4 cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm text-gray-600 hover:border-primary-500 print:hidden"
          >
            ×
          </button>
          <PrintableQuestionPaper paper={printingPaper} />
        </>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}
