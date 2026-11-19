import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SiteFooter from './SiteFooter'

const t = getCommonStrings('bn')

const NAV_ITEMS = [
  { to: '/', label: t['student.nav.home'], end: true, icon: true },
  { to: '/books', label: t['student.nav.books'] },
  { to: '/video-lecture', label: t['student.nav.videoLecture'] },
  { to: '/exams', label: t['student.nav.exams'] },
]

function HomeIcon() {
  return (
    <svg
      width={16}
      height={16}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M3 10.5 12 3l9 7.5" />
      <path d="M5.5 9v10a1 1 0 0 0 1 1h4v-6h3v6h4a1 1 0 0 0 1-1V9" />
    </svg>
  )
}

export default function SiteShell() {
  const { token, clearToken } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)

  // Revokes the token server-side before dropping it locally — clearToken()
  // alone only clears localStorage, which would leave the token valid
  // indefinitely (Sanctum tokens never expire). If the revoke call fails
  // (e.g. the token was already invalid), still clear local state so the
  // user isn't stuck appearing logged in.
  function handleLogout() {
    if (token) {
      apiLogout(token).catch(() => {})
    }
    clearToken()
  }

  return (
    <div className="flex min-h-screen flex-col bg-white text-primary-900">
      <header className="sticky top-0 z-20 border-b border-primary-100 bg-white">
        <div className="flex items-center justify-between gap-4 px-4 py-3 lg:px-8">
          <Link
            to="/"
            className="flex items-center gap-2 text-lg font-bold text-primary-700 transition-colors hover:text-[#EB1F27]"
          >
            <img src="/logo.webp" alt="" aria-hidden="true" className="h-9 w-9 object-contain" />
            {t['landing.brand']}
          </Link>

          <nav
            className="hidden items-center gap-6 text-sm font-medium text-primary-800 md:flex"
            aria-label={t['student.nav.ariaLabel']}
          >
            {NAV_ITEMS.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  `flex items-center gap-1.5 transition-colors ${
                    isActive ? 'font-semibold text-[#EB1F27]' : 'hover:text-[#EB1F27]'
                  }`
                }
              >
                {item.icon && <HomeIcon />}
                {item.label}
              </NavLink>
            ))}
          </nav>

          <div className="hidden md:block">
            {token ? (
              <button
                type="button"
                onClick={handleLogout}
                className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-[#EB1F27] hover:text-white"
              >
                {t['student.nav.logout']}
              </button>
            ) : (
              <Link
                to="/login"
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-[#EB1F27]"
              >
                {t['student.nav.signIn']}
              </Link>
            )}
          </div>

          <button
            type="button"
            className="flex h-9 w-9 flex-col items-center justify-center gap-1 md:hidden"
            aria-label={t['student.nav.ariaLabel']}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((open) => !open)}
          >
            <span className="block h-0.5 w-5 rounded bg-primary-900" />
            <span className="block h-0.5 w-5 rounded bg-primary-900" />
            <span className="block h-0.5 w-5 rounded bg-primary-900" />
          </button>
        </div>

        {menuOpen && (
          <nav
            className="flex flex-col border-t border-primary-100 md:hidden"
            aria-label={t['student.nav.ariaLabel']}
          >
            {NAV_ITEMS.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                onClick={() => setMenuOpen(false)}
                className={({ isActive }) =>
                  `flex items-center gap-1.5 border-t border-primary-100 px-4 py-3 text-sm font-medium first:border-t-0 ${
                    isActive ? 'text-[#EB1F27]' : 'text-primary-800 hover:text-[#EB1F27]'
                  }`
                }
              >
                {item.icon && <HomeIcon />}
                {item.label}
              </NavLink>
            ))}

            <div className="border-t border-primary-100 px-4 py-3">
              {token ? (
                <button
                  type="button"
                  onClick={() => {
                    handleLogout()
                    setMenuOpen(false)
                  }}
                  className="w-full cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-[#EB1F27] hover:text-white"
                >
                  {t['student.nav.logout']}
                </button>
              ) : (
                <Link
                  to="/login"
                  onClick={() => setMenuOpen(false)}
                  className="block w-full cursor-pointer rounded-md bg-primary-500 px-4 py-1.5 text-center text-sm font-medium text-white hover:bg-[#EB1F27]"
                >
                  {t['student.nav.signIn']}
                </Link>
              )}
            </div>
          </nav>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <SiteFooter />
    </div>
  )
}
