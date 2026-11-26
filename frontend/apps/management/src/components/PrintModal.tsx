import { useEffect, type ReactNode } from 'react'
import { getCommonStrings } from '@ttla/i18n'
import { buttonPrimary } from './styles'

const t = getCommonStrings('en')

// A popup, not a route — the caller already has the student/teacher and
// profile in memory, so this just presents it for printing without a
// navigation. The backdrop/dialog chrome is `fixed`/scroll-constrained for
// on-screen use but relaxes to a normal flowing block under print:* so the
// content isn't clipped by a fixed ancestor across multiple printed pages.
// The caller is responsible for giving its own normal (non-print) content a
// `print:hidden` wrapper — that, plus AppShell's existing print:hidden
// sidebar/header, is what leaves only this modal's content on the page.
export default function PrintModal({
  title,
  onClose,
  children,
}: {
  title: string
  onClose: () => void
  children: ReactNode
}) {
  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5 print:static print:z-auto print:block print:bg-white print:p-0"
      onClick={onClose}
    >
      <style>{`
        @media print {
          @page { size: A4; margin: 15mm; }
          body { background: white !important; }
        }
      `}</style>

      <div
        className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-md bg-white shadow-2xl print:max-h-none print:w-auto print:max-w-none print:overflow-visible print:rounded-none print:shadow-none"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 print:hidden">
          <h3 className="text-base font-semibold text-gray-800">{title}</h3>
          <div className="flex items-center gap-2">
            <button type="button" className={`${buttonPrimary} inline-flex items-center gap-1.5`} onClick={() => window.print()}>
              <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
              {t['management.idCard.print']}
            </button>
            <button
              type="button"
              className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
              onClick={onClose}
              aria-label={t['management.table.close']}
            >
              ×
            </button>
          </div>
        </div>

        <div className="flex flex-col items-center gap-4 overflow-y-auto p-6 print:overflow-visible print:p-0">
          {children}
        </div>
      </div>
    </div>
  )
}
