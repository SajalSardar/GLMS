import { useEffect, useRef, useState } from 'react'
import { inputClass } from './styles'

export interface SearchableSelectOption {
  value: string
  label: string
}

export interface SearchableSelectProps {
  value: string
  onChange: (value: string) => void
  options: SearchableSelectOption[]
  placeholder: string
  searchPlaceholder?: string
  noMatchesLabel?: string
  className?: string
  disabled?: boolean
}

// Ported from the management portal's SearchableSelect (apps/management/
// src/components/SearchableSelect.tsx) so the dashboard's filter dropdowns
// look and behave the same across both portals — same button-with-chevron
// trigger and searchable floating panel, instead of a native <select>.
// Dashboard-only — the public website pages keep plain native <select>s.
export default function SearchableSelect({
  value,
  onChange,
  options,
  placeholder,
  searchPlaceholder = 'Search...',
  noMatchesLabel = 'No matches',
  className,
  disabled = false,
}: SearchableSelectProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  useEffect(() => {
    if (open) {
      inputRef.current?.focus()
    }
  }, [open])

  useEffect(() => {
    if (disabled) setOpen(false)
  }, [disabled])

  const selectedLabel = options.find((option) => option.value === value)?.label
  const filtered = query
    ? options.filter((option) => option.label.toLowerCase().includes(query.toLowerCase()))
    : options

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        disabled={disabled}
        className={`${className ?? inputClass} flex w-full cursor-pointer items-center justify-between gap-2 text-left transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-60`}
        onClick={() => {
          setQuery('')
          setOpen((prev) => !prev)
        }}
      >
        <span className={`truncate ${selectedLabel ? 'text-gray-800' : 'text-gray-400'}`}>
          {selectedLabel ?? placeholder}
        </span>
        <span className="material-symbols-outlined !text-[18px] leading-none text-gray-400">
          {open ? 'expand_less' : 'expand_more'}
        </span>
      </button>

      {open && (
        <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg">
          <div className="border-b border-gray-100 p-2">
            <input
              ref={inputRef}
              type="text"
              className="h-9 w-full rounded-md border border-gray-200 px-2.5 text-sm outline-none transition-colors duration-150 focus:border-primary-500"
              placeholder={searchPlaceholder}
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Escape') setOpen(false)
              }}
            />
          </div>
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-sm text-gray-400">{noMatchesLabel}</li>
            ) : (
              filtered.map((option) => (
                <li key={option.value}>
                  <button
                    type="button"
                    className={`block w-full cursor-pointer px-3 py-2 text-left text-sm transition-colors duration-150 hover:bg-primary-50 hover:text-primary-600 ${
                      option.value === value ? 'bg-primary-50 font-medium text-primary-600' : 'text-gray-700'
                    }`}
                    onClick={() => {
                      onChange(option.value)
                      setOpen(false)
                    }}
                  >
                    {option.label}
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  )
}
