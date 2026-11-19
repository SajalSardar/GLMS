interface PaginationProps {
  currentPage: number
  totalPages: number
  onPrevious: () => void
  onNext: () => void
  previousLabel: string
  nextLabel: string
  pageOfLabel: string
}

export default function Pagination({
  currentPage,
  totalPages,
  onPrevious,
  onNext,
  previousLabel,
  nextLabel,
  pageOfLabel,
}: PaginationProps) {
  return (
    <div className="mt-4 flex items-center justify-center gap-3">
      <button
        type="button"
        onClick={onPrevious}
        disabled={currentPage <= 1}
        className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 disabled:cursor-default disabled:opacity-40"
      >
        {previousLabel}
      </button>
      <span className="text-sm text-primary-600">
        {pageOfLabel.replace('{current}', String(currentPage)).replace('{total}', String(totalPages))}
      </span>
      <button
        type="button"
        onClick={onNext}
        disabled={currentPage >= totalPages}
        className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 disabled:cursor-default disabled:opacity-40"
      >
        {nextLabel}
      </button>
    </div>
  )
}
