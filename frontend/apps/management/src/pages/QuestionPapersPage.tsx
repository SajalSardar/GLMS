import { useEffect, useState } from 'react'
import DOMPurify from 'dompurify'
import { listQuestionPapers, type QuestionPaper } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Question text is authored as rich HTML in this page's own editor —
// Tailwind's preflight strips default list styling, so bullet/numbered
// lists need their own markers restored for display here too.
const richQuestionTextStyle = `
  .rich-question-text p { margin: 0 0 0.35em; }
  .rich-question-text p:last-child { margin-bottom: 0; }
  .rich-question-text ul { list-style: disc; padding-left: 1.25rem; }
  .rich-question-text ol { list-style: decimal; padding-left: 1.25rem; }
`

// Real Bangladeshi school papers are written in the medium of the
// subject itself — an English paper reads in English (option lettering
// included), everything else (Bangla, Mathematics, Science, ...) reads
// in Bangla — rather than following whatever language this portal's own
// chrome happens to be in. "English" is matched by Subject.name (the
// national catalog's canonical English name), not by the actor's
// current locale.
function isEnglishSubjectPaper(paper: QuestionPaper): boolean {
  return paper.subject?.name === 'English'
}

// ক/খ/গ/ঘ are four consecutive Unicode code points (U+0995..U+0998) —
// exactly matching the 4-option MCQ shape, same convention as the
// sample exam papers this feature's design was based on; A/B/C/D for
// an English-subject paper instead (see isEnglishSubjectPaper() above).
function optionLetter(index: number, english: boolean): string {
  return english ? String.fromCharCode(0x41 + index) : String.fromCharCode(0x0995 + index)
}

// Deliberately duplicated in the teacher app's MyQuestionPapersPage —
// see this plan's Global Constraints on per-app duplication of shared
// rendering, not a cross-app packages/ui component.
function PrintableQuestionPaper({ paper }: { paper: QuestionPaper }) {
  const items = paper.items ?? []
  let questionNumber = 0
  const english = isEnglishSubjectPaper(paper)
  const className = english ? paper.school_class?.name : (paper.school_class?.bn_name ?? paper.school_class?.name)
  const subjectName = english ? paper.subject?.name : (paper.subject?.bn_name ?? paper.subject?.name)
  const schoolName = english ? paper.school?.name : (paper.school?.bn_name ?? paper.school?.name)

  return (
    <div className="mx-auto max-w-3xl bg-white p-8 text-gray-900 print:p-0">
      <style>{richQuestionTextStyle}</style>
      <div className="text-center">
        {schoolName && <p className="text-xl font-bold">{schoolName}</p>}
        {paper.school?.address && <p className="text-sm text-gray-600">{paper.school.address}</p>}
        <h1 className="mt-2 text-lg font-bold">{paper.title}</h1>
        <p className="mt-1 text-sm">
          {className ?? ''} — {subjectName ?? ''}
        </p>
        <div className="mt-2 flex justify-center gap-6 text-sm">
          {paper.time_duration_minutes !== null && (
            <span>
              {english ? 'Time' : 'সময়'}: {paper.time_duration_minutes} {english ? 'min' : 'মিনিট'}
            </span>
          )}
          {paper.full_marks !== null && (
            <span>
              {english ? 'Full Marks' : 'পূর্ণমান'}: {paper.full_marks}
            </span>
          )}
        </div>
        {paper.instructions && <p className="mt-2 text-sm italic">{paper.instructions}</p>}
      </div>

      <div className="mt-6 flex flex-col gap-3">
        {items.map((item) => {
          // Every item's own indent_level pushes it right, like a nested
          // <li> — a sub-question under a main one, say.
          const indentStyle = { marginLeft: `${item.indent_level * 1.5}rem` }

          // Section headings are always centered — a real exam paper's
          // headings (গদ্য, ব্যাকরণ, ...) read that way regardless of
          // subject/medium, unlike the Time/Marks labels and option
          // lettering above, which do switch with it.
          if (item.item_type === 'heading') {
            return (
              <h2 key={item.id} style={indentStyle} className="mt-4 text-center text-base font-semibold underline">
                {item.text_content}
              </h2>
            )
          }

          // A plain inserted note/instruction — reads like ordinary
          // paper text, never numbered alongside the real questions.
          if (item.item_type === 'text') {
            return (
              <p key={item.id} style={indentStyle} className="text-sm">
                {item.text_content}
              </p>
            )
          }

          questionNumber += 1
          const displayNumber = item.serial_label ?? String(questionNumber)

          return (
            <div key={item.id} style={indentStyle} className="flex items-start justify-between gap-4">
              <div className="flex flex-1 items-start gap-1.5">
                <span className="shrink-0 font-medium">{displayNumber}.</span>
                <div className="flex-1">
                  <div
                    className="rich-question-text"
                    dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(item.question_text ?? '') }}
                  />
                  {item.question_type === 'mcq' && item.options && (
                    <ol className="mt-1 ml-5 list-none">
                      {item.options.map((option, index) => (
                        <li key={index}>
                          {optionLetter(index, english)}) {option}
                        </li>
                      ))}
                    </ol>
                  )}
                </div>
              </div>
              <span className="shrink-0 text-sm">{item.marks}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export default function QuestionPapersPage() {
  const { token } = useAuth()
  const [papers, setPapers] = useState<QuestionPaper[]>([])
  const [loading, setLoading] = useState(true)
  const [printingPaper, setPrintingPaper] = useState<QuestionPaper | null>(null)

  useEffect(() => {
    if (!token) return

    listQuestionPapers(token)
      .then((result) => setPapers(result.data))
      .catch(() => setPapers([]))
      .finally(() => setLoading(false))
  }, [token])

  function handlePrint(paper: QuestionPaper) {
    setPrintingPaper(paper)
    // Let the printable view render before invoking the browser's print
    // dialog — window.print() runs synchronously against whatever is
    // already painted, same pattern as MarksheetsPage (there, the
    // printable section is always mounted; here, mounting itself is
    // conditional on which paper was clicked, so a frame must elapse
    // first).
    requestAnimationFrame(() => window.print())
  }

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-xl font-semibold text-gray-900 print:hidden">{t['management.questionPapers.title']}</h1>

      {loading && <p className="mt-4 text-sm text-gray-500 print:hidden">{t['management.questionPapers.loading']}</p>}

      {!loading && papers.length === 0 && (
        <p className="mt-4 rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm print:hidden">
          {t['management.questionPapers.empty']}
        </p>
      )}

      {!loading && papers.length > 0 && !printingPaper && (
        <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm print:hidden">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colTitle']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colClass']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colCreated']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.print']}</th>
              </tr>
            </thead>
            <tbody>
              {papers.map((paper) => (
                <tr key={paper.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.title}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.teacher?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.school_class?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{paper.created_at.slice(0, 10)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <button
                      type="button"
                      onClick={() => handlePrint(paper)}
                      className="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-400"
                    >
                      <span className="material-symbols-outlined !text-[16px] leading-none">print</span>
                      {t['management.questionPapers.print']}
                    </button>
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
    </div>
  )
}
