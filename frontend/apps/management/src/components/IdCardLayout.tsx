import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { getCommonStrings } from '@ttla/i18n'
import { buttonNeutral, buttonPrimary } from './styles'

const t = getCommonStrings('en')

// Real CR80 card dimensions (the standard ID card size) so the printed
// page can be laminated/cut to size rather than just looking card-shaped
// on screen.
const CARD_SIZE = 'h-[2.125in] w-[3.375in]'

// Front and back render as two CR80-sized faces stacked on one A4 sheet,
// each with a dashed cut line — rather than e.g. printing front/back on
// separate pages — so a single print job produces both sides to cut out
// and glue/laminate back-to-back.
export function IdCardFace({ children, labelHidden }: { children: ReactNode; labelHidden?: string }) {
  return (
    <div
      className={`${CARD_SIZE} flex flex-col overflow-hidden rounded-[10px] border border-dashed border-gray-300 bg-white p-3 shadow-sm print:shadow-none`}
      aria-label={labelHidden}
    >
      {children}
    </div>
  )
}

export default function IdCardLayout({
  title,
  backTo,
  front,
  back,
}: {
  title: string
  backTo: string
  front: ReactNode
  back: ReactNode
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

      <div className="flex w-full max-w-md items-center justify-between print:hidden">
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

      <div className="flex flex-col items-center gap-6">
        {front}
        {back}
      </div>
    </section>
  )
}
