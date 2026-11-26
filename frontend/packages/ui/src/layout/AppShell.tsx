import type { ReactNode } from 'react'
import { useEffect, useRef, useState } from 'react'
import { Link, Outlet } from 'react-router-dom'
import Sidebar, { type SidebarItem } from './Sidebar'

export interface AppShellProps {
  title: string
  navItems: SidebarItem[]
  logoSrc?: string
  // The signed-in actor's display name and role label, shown together in
  // the topbar's account menu — replaces the old single `userLabel`
  // (management passed it the role, teacher/student passed it the name;
  // the account menu now always needs both at once).
  userName?: string
  userRole?: string
  // Path to this app's own self-service account page — omitted entirely
  // (no "Profile" row) by an app that hasn't built one yet, so a consumer
  // can adopt the rest of the account menu before that page exists.
  profileTo?: string
  profileLabel?: string
  notificationsLabel?: string
  noNotificationsLabel?: string
  onLogout?: () => void
  logoutLabel?: string
  // Extra content (e.g. a language switcher) rendered in the topbar, to the
  // left of the account menu — optional so existing consumers (management)
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

// Shared click-outside-to-close behavior for the two topbar dropdowns
// below — same pattern as SearchableSelect's own containerRef/mousedown
// listener, generalized since both menus need it. The callback is read
// through a ref (kept current every render) rather than placed in the
// effect's own deps, so the mousedown listener itself is attached once
// on mount instead of being torn down and reattached on every render.
function useClickOutside(onOutsideClick: () => void) {
  const ref = useRef<HTMLDivElement>(null)
  const callbackRef = useRef(onOutsideClick)

  useEffect(() => {
    callbackRef.current = onOutsideClick
  })

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        callbackRef.current()
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  return ref
}

function FullscreenToggleButton() {
  const [isFullscreen, setIsFullscreen] = useState(false)

  useEffect(() => {
    function handleChange() {
      setIsFullscreen(document.fullscreenElement !== null)
    }

    document.addEventListener('fullscreenchange', handleChange)
    return () => document.removeEventListener('fullscreenchange', handleChange)
  }, [])

  function toggle() {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {})
    } else {
      document.documentElement.requestFullscreen().catch(() => {})
    }
  }

  return (
    <button
      type="button"
      onClick={toggle}
      className="hidden cursor-pointer text-gray-500 transition-colors duration-150 hover:text-primary-500 sm:inline-flex"
      aria-label="Toggle fullscreen"
    >
      <span className="material-symbols-outlined !text-[22px] leading-none">
        {isFullscreen ? 'fullscreen_exit' : 'fullscreen'}
      </span>
    </button>
  )
}

function NotificationsMenu({
  notificationsLabel = 'Notifications',
  noNotificationsLabel = 'No notifications yet',
}: {
  notificationsLabel?: string
  noNotificationsLabel?: string
}) {
  const [open, setOpen] = useState(false)
  const ref = useClickOutside(() => setOpen(false))

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="cursor-pointer text-gray-500 transition-colors duration-150 hover:text-primary-500"
        aria-label={notificationsLabel}
      >
        <span className="material-symbols-outlined !text-[22px] leading-none">notifications</span>
      </button>
      {open && (
        <div className="absolute top-full right-0 z-20 mt-2 w-[260px] rounded-md bg-white p-4 text-center text-sm text-gray-500 shadow-2xl">
          {noNotificationsLabel}
        </div>
      )}
    </div>
  )
}

function AccountMenu({
  userName,
  userRole,
  profileTo,
  profileLabel = 'Profile',
  onLogout,
  logoutLabel = 'Logout',
}: {
  userName?: string
  userRole?: string
  profileTo?: string
  profileLabel?: string
  onLogout?: () => void
  logoutLabel?: string
}) {
  const [open, setOpen] = useState(false)
  const ref = useClickOutside(() => setOpen(false))

  if (!userName && !onLogout) return null

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700"
      >
        {userName && (
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700">
            {userName.charAt(0).toUpperCase()}
          </span>
        )}
        <span className="hidden sm:block">{userName}</span>
        <span className="material-symbols-outlined !text-[18px] leading-none text-gray-400">expand_more</span>
      </button>
      {open && (
        <div className="absolute top-full right-0 z-20 mt-2 w-[220px] rounded-md bg-white py-2 shadow-2xl">
          {(userName || userRole) && (
            <div className="border-b border-gray-100 px-4 py-3">
              {userName && <span className="block truncate text-sm font-semibold text-gray-800">{userName}</span>}
              {userRole && <span className="mt-0.5 block truncate text-xs text-gray-500">{userRole}</span>}
            </div>
          )}
          {profileTo && (
            <Link
              to={profileTo}
              onClick={() => setOpen(false)}
              className="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 transition-colors duration-150 hover:bg-gray-50"
            >
              <span className="material-symbols-outlined !text-[18px] leading-none text-gray-500">person</span>
              {profileLabel}
            </Link>
          )}
          {onLogout && (
            <button
              type="button"
              onClick={() => {
                setOpen(false)
                onLogout()
              }}
              className="flex w-full cursor-pointer items-center gap-2.5 px-4 py-2 text-left text-sm text-danger-500 transition-colors duration-150 hover:bg-danger-50"
            >
              <span className="material-symbols-outlined !text-[18px] leading-none">logout</span>
              {logoutLabel}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

export default function AppShell({
  title,
  navItems,
  logoSrc,
  userName,
  userRole,
  profileTo,
  profileLabel,
  notificationsLabel,
  noNotificationsLabel,
  onLogout,
  logoutLabel = 'Logout',
  headerExtra,
  mainClassName = 'flex-1 p-4 lg:p-6',
}: AppShellProps) {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false)
  // Independent of the mobile drawer above — this is the desktop-only
  // "collapse the sidebar away entirely" toggle, using the exact same
  // hide/show mechanism (translate-x + main content's left padding) so
  // there's one code path for "sidebar visible" rather than two.
  const [desktopSidebarOpen, setDesktopSidebarOpen] = useState(true)

  return (
    <div className="min-h-screen bg-gray-50 text-gray-700">
      <aside
        className={`fixed inset-y-0 left-0 z-30 w-[260px] overflow-y-auto border-r border-gray-100 bg-white transition-transform duration-200 print:hidden ${
          mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full'
        } ${desktopSidebarOpen ? 'lg:translate-x-0' : 'lg:-translate-x-full'}`}
      >
        <div className="flex items-center gap-2.5 border-b border-gray-100 px-5 py-[18px]">
          {logoSrc && <img src={logoSrc} alt="" className="h-9 w-9 shrink-0 object-contain" />}
          <span className="truncate text-base leading-none font-bold text-primary-600">{title}</span>
          <button
            type="button"
            className="ml-auto cursor-pointer text-gray-500 transition-colors duration-150 hover:text-primary-500 lg:hidden"
            aria-label="Close menu"
            onClick={() => setMobileSidebarOpen(false)}
          >
            <span className="material-symbols-outlined !text-[22px] leading-none">close</span>
          </button>
        </div>
        <nav className="px-4 py-5" aria-label="Primary">
          <Sidebar items={navItems} />
        </nav>
      </aside>

      {mobileSidebarOpen && (
        <div
          className="fixed inset-0 z-20 bg-black/40 lg:hidden"
          onClick={() => setMobileSidebarOpen(false)}
          aria-hidden="true"
        />
      )}

      <div className={`flex min-h-screen flex-col print:pl-0 ${desktopSidebarOpen ? 'lg:pl-[260px]' : ''}`}>
        <header className="sticky top-0 z-10 flex items-center gap-3 border-b border-gray-100 bg-white px-4 py-3 print:hidden lg:px-6">
          <button
            type="button"
            className="cursor-pointer text-gray-600 transition-colors duration-150 hover:text-primary-500 lg:hidden"
            aria-label="Open menu"
            onClick={() => setMobileSidebarOpen(true)}
          >
            <span className="material-symbols-outlined !text-[22px] leading-none">menu</span>
          </button>
          <button
            type="button"
            className="hidden cursor-pointer text-gray-600 transition-colors duration-150 hover:text-primary-500 lg:inline-flex"
            aria-label="Toggle sidebar"
            onClick={() => setDesktopSidebarOpen((value) => !value)}
          >
            <span className="material-symbols-outlined !text-[22px] leading-none">menu</span>
          </button>

          <div className="ml-auto flex items-center gap-4">
            {headerExtra}
            <FullscreenToggleButton />
            <NotificationsMenu notificationsLabel={notificationsLabel} noNotificationsLabel={noNotificationsLabel} />
            <AccountMenu
              userName={userName}
              userRole={userRole}
              profileTo={profileTo}
              profileLabel={profileLabel}
              onLogout={onLogout}
              logoutLabel={logoutLabel}
            />
          </div>
        </header>

        <main className={`${mainClassName} print:p-0`}>
          <Outlet />
        </main>

        <footer className="border-t border-gray-100 bg-white px-4 py-4 text-center text-sm text-gray-500 print:hidden lg:px-6">
          © {currentYear} {title}
        </footer>
      </div>
    </div>
  )
}
