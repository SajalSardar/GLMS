import { Link } from 'react-router-dom'

// Shown only in a guardian session's header (see StudentAppShell.tsx) —
// a quick way back to the child picker to view a different linked child.
export default function GuardianChildSwitcher({ label }: { label: string }) {
  return (
    <Link
      to="/select-child"
      className="flex items-center gap-1 rounded-full border border-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-600 transition-colors hover:bg-primary-50"
    >
      <span className="material-symbols-outlined !text-[16px]">switch_account</span>
      {label}
    </Link>
  )
}
