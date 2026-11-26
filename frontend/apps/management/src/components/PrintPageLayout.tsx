import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { getCommonStrings } from '@ttla/i18n'
import { buttonNeutral, buttonPrimary } from './styles'

const t = getCommonStrings('en')

// Full A4 document layout for a printable form (as opposed to IdCardLayout's
// CR80 card) — same window.print() convention as the rest of this codebase
// (CertificatePage, ManagementClassRoutinePage).
export default function PrintPageLayout({
  title,
  backTo,
  children,
}: {
  title: string
  backTo: string
  children: ReactNode
}) {
  const navigate = useNavigate()

  return (
    <section className="flex flex-col items-center gap-6 px-4 py-6 print:px-0 print:py-0">
      <style>{`
        @media print {
          @page { size: A4; margin: 15mm; }
          body { background: white !important; }
        }
      `}</style>

      <div className="flex w-full max-w-3xl items-center justify-between print:hidden">
        <button type="button" className={`${buttonNeutral} inline-flex items-center gap-1.5`} onClick={() => navigate(backTo)}>
          <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
          {t['management.idCard.back']}
        </button>

        <button type="button" className={`${buttonPrimary} inline-flex items-center gap-1.5`} onClick={() => window.print()}>
          <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
          {t['management.idCard.print']}
        </button>
      </div>

      <h1 className="text-xl font-semibold text-gray-800 print:hidden">{title}</h1>

      <div className="w-full max-w-3xl rounded-md bg-white p-8 shadow-sm print:rounded-none print:p-0 print:shadow-none">
        {children}
      </div>
    </section>
  )
}
