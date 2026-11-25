import DatePickerField from './DatePickerField'

const defaultInputClass =
  'h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'

export interface DateTimePickerFieldProps {
  // Same shape a native <input type="datetime-local"> uses: 'YYYY-MM-
  // DDTHH:mm', or '' for no value.
  value: string
  onChange: (value: string) => void
  placeholder?: string
  minDate?: string
  maxDate?: string
  minYear?: number
  maxYear?: number
  todayLabel?: string
  clearLabel?: string
}

function splitValue(value: string): { date: string; time: string } {
  const [date, time] = value.split('T')
  return { date: date ?? '', time: time ?? '' }
}

// Pairs the same calendar popup used everywhere a plain date is picked
// (DatePickerField) with a native time input for the hour/minute this
// one field also needs — composing rather than duplicating the whole
// calendar-grid implementation a second time for a datetime variant.
// Deliberately duplicated from the management portal's equivalent
// component — same design everywhere a date is picked across all three
// apps (see this app's Global Constraints on per-app duplication of
// shared rendering).
export default function DateTimePickerField({
  value,
  onChange,
  placeholder,
  minDate,
  maxDate,
  minYear,
  maxYear,
  todayLabel,
  clearLabel,
}: DateTimePickerFieldProps) {
  const { date, time } = splitValue(value)

  return (
    <div className="flex gap-2">
      <div className="flex-1">
        <DatePickerField
          value={date}
          onChange={(nextDate) => onChange(nextDate ? `${nextDate}T${time || '00:00'}` : '')}
          placeholder={placeholder}
          minDate={minDate}
          maxDate={maxDate}
          minYear={minYear}
          maxYear={maxYear}
          todayLabel={todayLabel}
          clearLabel={clearLabel}
        />
      </div>
      <input
        type="time"
        className={`${defaultInputClass} w-32`}
        value={time}
        disabled={!date}
        onChange={(event) => onChange(date ? `${date}T${event.target.value}` : '')}
      />
    </div>
  )
}
