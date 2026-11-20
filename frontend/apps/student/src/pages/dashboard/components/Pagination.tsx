const pageButtonBase =
  'flex h-[31px] w-[31px] cursor-pointer items-center justify-center rounded-md border border-gray-100 text-sm transition-colors duration-150'
const pageButtonInactive = 'text-gray-600 hover:border-primary-500 hover:bg-primary-500 hover:text-white'
const pageButtonActive = 'border-primary-500 bg-primary-500 text-white'

function getPageNumbers(current: number, last: number): Array<number | 'ellipsis'> {
  if (last <= 7) {
    return Array.from({ length: last }, (_, index) => index + 1)
  }

  const pages = new Set<number>([1, last, current, current - 1, current + 1, current - 2, current + 2])
  const sorted = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b)

  const result: Array<number | 'ellipsis'> = []
  let previous = 0
  for (const page of sorted) {
    if (page - previous > 1) result.push('ellipsis')
    result.push(page)
    previous = page
  }
  return result
}

interface PaginationProps {
  currentPage: number
  totalPages: number
  onPageChange: (page: number) => void
  visibleCount: number
  total: number
  showingLabel: string
  ofLabel: string
  resultsLabel: string
  previousLabel: string
  nextLabel: string
}

// Numbered pager + "Showing X of Y results" footer, matching the management
// app's GeoCrudTable list style exactly (same button sizing/colors, same
// ellipsis collapsing for long page ranges). Dashboard-only — the public
// website pages use the plain prev/next pager in src/components/Pagination.tsx.
export default function Pagination({
  currentPage,
  totalPages,
  onPageChange,
  visibleCount,
  total,
  showingLabel,
  ofLabel,
  resultsLabel,
  previousLabel,
  nextLabel,
}: PaginationProps) {
  return (
    <div className="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
      <p className="text-sm text-gray-500">
        {showingLabel} {visibleCount} {ofLabel} {total} {resultsLabel}
      </p>
      <ol className="flex items-center gap-[3px]">
        <li>
          <button
            type="button"
            className={`${pageButtonBase} ${pageButtonInactive} disabled:pointer-events-none disabled:opacity-40`}
            onClick={() => onPageChange(currentPage - 1)}
            disabled={currentPage <= 1}
            aria-label={previousLabel}
          >
            <span className="material-symbols-outlined !text-[18px]">chevron_left</span>
          </button>
        </li>
        {getPageNumbers(currentPage, totalPages).map((entry, index) =>
          entry === 'ellipsis' ? (
            <li key={`ellipsis-${index}`} className="px-1 text-sm text-gray-400">
              …
            </li>
          ) : (
            <li key={entry}>
              <button
                type="button"
                className={`${pageButtonBase} ${entry === currentPage ? pageButtonActive : pageButtonInactive}`}
                onClick={() => onPageChange(entry)}
                aria-current={entry === currentPage ? 'page' : undefined}
              >
                {entry}
              </button>
            </li>
          ),
        )}
        <li>
          <button
            type="button"
            className={`${pageButtonBase} ${pageButtonInactive} disabled:pointer-events-none disabled:opacity-40`}
            onClick={() => onPageChange(currentPage + 1)}
            disabled={currentPage >= totalPages}
            aria-label={nextLabel}
          >
            <span className="material-symbols-outlined !text-[18px]">chevron_right</span>
          </button>
        </li>
      </ol>
    </div>
  )
}
