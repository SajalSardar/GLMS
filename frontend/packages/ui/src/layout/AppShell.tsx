import type { ReactNode } from 'react'
import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import Sidebar, { type SidebarItem } from './Sidebar'

export interface AppShellProps {
  title: string
  navItems: SidebarItem[]
  logoSrc?: string
  userLabel?: string
  onLogout?: () => void
  logoutLabel?: string
  // Extra content (e.g. a language switcher) rendered in the topbar, to the
  // left of userLabel/logout — optional so existing consumers (management)
  // are unaffected.
  headerExtra?: ReactNode
  // Override the <main> wrapper's classes. Defaults to this shell's own
  // p-4 lg:p-6 padding; a consumer whose pages already carry their own
  // outer padding (because those same pages also render under a second,
  // unpadded shell — e.g. the student app's SiteShell for guests) can pass
  // 'flex-1' here to avoid doubling up.
  mainClassName?: string
}

const currentYear = new Date().getFullYear()

export default function AppShell({
  title,
  navItems,
  logoSrc,
  userLabel,
  onLogout,
  logoutLabel = 'Logout',
  headerExtra,
  mainClassName = 'flex-1 p-4 lg:p-6',
}: AppShellProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="min-h-screen bg-gray-50 text-gray-700">
      <aside
        className={`fixed inset-y-0 left-0 z-30 w-[260px] overflow-y-auto border-r border-gray-100 bg-white transition-transform duration-200 lg:translate-x-0 ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-[18px]">
          {logoSrc ? (
            <img src={logoSrc} alt={title} className="h-9 w-9 object-contain" />
          ) : (
            <span className="text-lg leading-none font-bold text-primary-600">{title}</span>
          )}
          <button
            type="button"
            className="cursor-pointer text-gray-500 transition-colors duration-150 hover:text-primary-500 lg:hidden"
            aria-label="Close menu"
            onClick={() => setSidebarOpen(false)}
          >
            <span className="material-symbols-outlined !text-[22px] leading-none">close</span>
          </button>
        </div>
        <nav className="px-4 py-5" aria-label="Primary">
          <Sidebar items={navItems} />
        </nav>
      </aside>

      {sidebarOpen && (
        <div
          className="fixed inset-0 z-20 bg-black/40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
          aria-hidden="true"
        />
      )}

      <div className="flex min-h-screen flex-col lg:pl-[260px]">
        <header className="sticky top-0 z-10 flex items-center gap-4 border-b border-gray-100 bg-white px-4 py-3 lg:px-6">
          <button
            type="button"
            className="cursor-pointer text-gray-600 transition-colors duration-150 hover:text-primary-500 lg:hidden"
            aria-label="Open menu"
            onClick={() => setSidebarOpen(true)}
          >
            <span className="material-symbols-outlined !text-[22px] leading-none">menu</span>
          </button>

          <span className="hidden text-sm font-semibold text-gray-700 lg:block">{title}</span>

          <div className="ml-auto flex items-center gap-3">
            {headerExtra}
            {userLabel && (
              <span className="hidden items-center gap-2 text-sm font-medium text-gray-700 sm:flex">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700">
                  {userLabel.charAt(0).toUpperCase()}
                </span>
                {userLabel}
              </span>
            )}
            {onLogout && (
              <button
                type="button"
                onClick={onLogout}
                className="cursor-pointer rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors duration-150 hover:border-danger-400 hover:text-danger-500"
              >
                {logoutLabel}
              </button>
            )}
          </div>
        </header>

        <main className={mainClassName}>
          <Outlet />
        </main>

        <footer className="border-t border-gray-100 bg-white px-4 py-4 text-center text-sm text-gray-500 lg:px-6">
          © {currentYear} {title}
        </footer>
      </div>
    </div>
  )
}
