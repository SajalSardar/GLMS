import { useEffect, useRef, useState } from 'react'
import { inputClass } from './styles'

// Deliberately duplicated from the management portal's DatePickerField —
// same design everywhere a date is picked across all three apps (see
// this app's Global Constraints on per-app duplication of shared
// rendering, not a cross-app packages/ui component).

export interface DatePickerFieldProps {
  // ISO 'YYYY-MM-DD', or '' for no value — same shape a native
  // <input type="date"> uses, so this drops in as a direct replacement.
  value: string
  onChange: (value: string) => void
  placeholder?: string
  // ISO 'YYYY-MM-DD' bounds — a day outside this range can't be picked.
  // Defaults to no bound on either side.
  minDate?: string
  maxDate?: string
  // Bounds for the quick year-jump dropdown — this is what makes picking
  // a date decades back fast, instead of clicking "previous month"
  // dozens of times. Defaults to 100 years back / 1 year forward from
  // today.
  minYear?: number
  maxYear?: number
  className?: string
  todayLabel?: string
  clearLabel?: string
}

const WEEKDAY_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
const MONTH_LABELS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

// Computed once at module load, not per render — this component's
// "today" doesn't need to change mid-session, and calling `new Date()`
// directly inside the component body trips this project's react-purity
// lint rule.
const today = new Date()

function toIsoDate(year: number, month: number, day: number): string {
  const mm = String(month + 1).padStart(2, '0')
  const dd = String(day).padStart(2, '0')
  return `${year}-${mm}-${dd}`
}

function parseIsoDate(value: string): { year: number; month: number; day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return null
  return { year: Number(match[1]), month: Number(match[2]) - 1, day: Number(match[3]) }
}

function formatDisplay(value: string): string {
  const parsed = parseIsoDate(value)
  if (!parsed) return ''
  const date = new Date(parsed.year, parsed.month, parsed.day)
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

// Full 6-row (42-cell) grid for the given month, including the trailing
// days of the previous month and leading days of the next month needed
// to fill complete weeks — each cell knows whether it belongs to the
// visible month so the grid can gray out the rest.
function buildMonthGrid(year: number, month: number): Array<{ year: number; month: number; day: number; inMonth: boolean }> {
  const firstOfMonth = new Date(year, month, 1)
  const startWeekday = firstOfMonth.getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const daysInPrevMonth = new Date(year, month, 0).getDate()

  const cells: Array<{ year: number; month: number; day: number; inMonth: boolean }> = []

  for (let i = 0; i < startWeekday; i++) {
    const day = daysInPrevMonth - startWeekday + 1 + i
    const prevMonth = month === 0 ? 11 : month - 1
    const prevYear = month === 0 ? year - 1 : year
    cells.push({ year: prevYear, month: prevMonth, day, inMonth: false })
  }

  for (let day = 1; day <= daysInMonth; day++) {
    cells.push({ year, month, day, inMonth: true })
  }

  while (cells.length % 7 !== 0 || cells.length < 42) {
    const last = cells[cells.length - 1]!
    const nextDay = last.day + 1
    const rolledOver = new Date(last.year, last.month, nextDay)
    cells.push({ year: rolledOver.getFullYear(), month: rolledOver.getMonth(), day: rolledOver.getDate(), inMonth: false })
  }

  return cells
}

export default function DatePickerField({
  value,
  onChange,
  placeholder = 'Select date',
  minDate,
  maxDate,
  minYear,
  maxYear,
  className,
  todayLabel = 'Today',
  clearLabel = 'Clear',
}: DatePickerFieldProps) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  const parsedValue = parseIsoDate(value)
  const [viewYear, setViewYear] = useState(parsedValue?.year ?? today.getFullYear())
  const [viewMonth, setViewMonth] = useState(parsedValue?.month ?? today.getMonth())

  // Jumps the visible month/year back to the selected value (or today)
  // each time the popup is freshly opened, rather than wherever it was
  // left after a previous navigation.
  function openPicker() {
    const current = parseIsoDate(value)
    setViewYear(current?.year ?? today.getFullYear())
    setViewMonth(current?.month ?? today.getMonth())
    setOpen(true)
  }

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const yearRangeStart = minYear ?? today.getFullYear() - 100
  const yearRangeEnd = maxYear ?? today.getFullYear() + 1
  const yearOptions: number[] = []
  for (let year = yearRangeEnd; year >= yearRangeStart; year--) {
    yearOptions.push(year)
  }

  const grid = buildMonthGrid(viewYear, viewMonth)

  function isDisabled(iso: string): boolean {
    if (minDate && iso < minDate) return true
    if (maxDate && iso > maxDate) return true
    return false
  }

  function goToPrevMonth() {
    if (viewMonth === 0) {
      setViewMonth(11)
      setViewYear((year) => year - 1)
    } else {
      setViewMonth((month) => month - 1)
    }
  }

  function goToNextMonth() {
    if (viewMonth === 11) {
      setViewMonth(0)
      setViewYear((year) => year + 1)
    } else {
      setViewMonth((month) => month + 1)
    }
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        className={`${className ?? inputClass} flex w-full cursor-pointer items-center justify-between gap-2 text-left transition-colors duration-150`}
        onClick={() => (open ? setOpen(false) : openPicker())}
      >
        <span className={value ? 'text-gray-800' : 'text-gray-400'}>{value ? formatDisplay(value) : placeholder}</span>
        <span className="material-symbols-outlined !text-[18px] leading-none text-gray-400">calendar_month</span>
      </button>

      {open && (
        <div className="absolute z-20 mt-1 w-72 overflow-hidden rounded-md border border-gray-200 bg-white p-3 shadow-lg">
          <div className="mb-2 flex items-center gap-1.5">
            <button
              type="button"
              className="cursor-pointer rounded-md p-1 text-gray-500 transition-colors duration-150 hover:bg-gray-50"
              onClick={goToPrevMonth}
              aria-label="Previous month"
            >
              <span className="material-symbols-outlined !text-[18px] leading-none">chevron_left</span>
            </button>

            <select
              className="h-8 flex-1 rounded-md border border-gray-200 bg-white px-1.5 text-sm outline-none focus:border-primary-500"
              value={viewMonth}
              onChange={(event) => setViewMonth(Number(event.target.value))}
            >
              {MONTH_LABELS.map((label, index) => (
                <option key={label} value={index}>
                  {label}
                </option>
              ))}
            </select>

            <select
              className="h-8 w-20 rounded-md border border-gray-200 bg-white px-1.5 text-sm outline-none focus:border-primary-500"
              value={viewYear}
              onChange={(event) => setViewYear(Number(event.target.value))}
            >
              {yearOptions.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>

            <button
              type="button"
              className="cursor-pointer rounded-md p-1 text-gray-500 transition-colors duration-150 hover:bg-gray-50"
              onClick={goToNextMonth}
              aria-label="Next month"
            >
              <span className="material-symbols-outlined !text-[18px] leading-none">chevron_right</span>
            </button>
          </div>

          <div className="grid grid-cols-7 gap-0.5 text-center text-xs text-gray-400">
            {WEEKDAY_LABELS.map((label) => (
              <span key={label} className="py-1">
                {label}
              </span>
            ))}
          </div>

          <div className="grid grid-cols-7 gap-0.5">
            {grid.map((cell) => {
              const iso = toIsoDate(cell.year, cell.month, cell.day)
              const disabled = isDisabled(iso)
              const selected = iso === value
              const isToday = iso === toIsoDate(today.getFullYear(), today.getMonth(), today.getDate())

              return (
                <button
                  key={iso}
                  type="button"
                  disabled={disabled}
                  onClick={() => {
                    onChange(iso)
                    setOpen(false)
                  }}
                  className={`h-8 rounded-md text-sm transition-colors duration-150 ${
                    selected
                      ? 'bg-primary-500 font-medium text-white'
                      : disabled
                        ? 'cursor-not-allowed text-gray-300'
                        : cell.inMonth
                          ? `cursor-pointer text-gray-700 hover:bg-primary-50 hover:text-primary-600${isToday ? ' font-semibold text-primary-600' : ''}`
                          : 'cursor-pointer text-gray-300 hover:bg-gray-50'
                  }`}
                >
                  {cell.day}
                </button>
              )
            })}
          </div>

          <div className="mt-2 flex items-center justify-between border-t border-gray-100 pt-2">
            <button
              type="button"
              className="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700"
              onClick={() => {
                onChange('')
                setOpen(false)
              }}
            >
              {clearLabel}
            </button>

            <button
              type="button"
              disabled={isDisabled(toIsoDate(today.getFullYear(), today.getMonth(), today.getDate()))}
              className="cursor-pointer text-xs font-medium text-primary-600 hover:text-primary-500 disabled:cursor-not-allowed disabled:text-gray-300"
              onClick={() => {
                const iso = toIsoDate(today.getFullYear(), today.getMonth(), today.getDate())
                onChange(iso)
                setOpen(false)
              }}
            >
              {todayLabel}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
