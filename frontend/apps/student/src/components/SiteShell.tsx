import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

const NAV_ITEMS = [
  { to: '/', label: t['student.nav.home'], end: true },
  { to: '/books', label: t['student.nav.books'] },
  { to: '/videos', label: t['student.nav.videos'] },
  { to: '/exams', label: t['student.nav.exams'] },
]

export default function SiteShell() {
  const { token, clearToken } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)
  const { pathname } = useLocation()
  // The landing page (`/`) already renders its own complete footer (brand,
  // quick links, help, copyright) — this thin site-wide footer is only for
  // routes that don't have one of their own (Books/Videos/Exams, added by
  // later tasks). Without this guard, `/` would show two footers stacked
  // back-to-back.
  const showFooter = pathname !== '/'

  return (
    <div className="flex min-h-screen flex-col bg-white text-primary-900">
      <header className="sticky top-0 z-20 border-b border-primary-100 bg-white">
        <div className="flex items-center justify-between gap-4 px-4 py-3 lg:px-8">
          <Link to="/" className="text-lg font-bold text-primary-700">
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
                className={({ isActive }) => (isActive ? 'text-primary-600' : 'hover:text-primary-600')}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>

          <div className="hidden md:block">
            {token ? (
              <button
                type="button"
                onClick={clearToken}
                className="cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50"
              >
                {t['student.nav.logout']}
              </button>
            ) : (
              // Plain <a>, not <Link>: this needs a real same-document anchor
              // jump to #login (React Router's Link does not auto-scroll on a
              // hash-only change), matching how the old landing page's own
              // nav already linked to its login card.
              <a
                href="/#login"
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-600"
              >
                {t['student.nav.signIn']}
              </a>
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
                  `border-t border-primary-100 px-4 py-3 text-sm font-medium first:border-t-0 ${
                    isActive ? 'text-primary-600' : 'text-primary-800'
                  }`
                }
              >
                {item.label}
              </NavLink>
            ))}

            <div className="border-t border-primary-100 px-4 py-3">
              {token ? (
                <button
                  type="button"
                  onClick={() => {
                    clearToken()
                    setMenuOpen(false)
                  }}
                  className="w-full cursor-pointer rounded-md border border-primary-200 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50"
                >
                  {t['student.nav.logout']}
                </button>
              ) : (
                <a
                  href="/#login"
                  onClick={() => setMenuOpen(false)}
                  className="block w-full cursor-pointer rounded-md bg-primary-500 px-4 py-1.5 text-center text-sm font-medium text-white hover:bg-primary-600"
                >
                  {t['student.nav.signIn']}
                </a>
              )}
            </div>
          </nav>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      {showFooter && (
        <footer className="border-t border-primary-100 bg-primary-50 px-4 py-6 text-center text-sm text-primary-700 lg:px-8">
          © {new Date().getFullYear()} {t['landing.brand']}
        </footer>
      )}
    </div>
  )
}
