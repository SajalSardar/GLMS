import { logout as apiLogout } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import type { Locale } from '@ttla/i18n'
import { AppShell, type SidebarItem } from '@ttla/ui'
import { useLocale } from '../lib/locale'

function LanguageSwitcher({
  locale,
  setLocale,
  ariaLabel,
}: {
  locale: Locale
  setLocale: (locale: Locale) => void
  ariaLabel: string
}) {
  return (
    <div
      className="flex items-center overflow-hidden rounded-full border border-gray-200 text-xs font-semibold"
      role="group"
      aria-label={ariaLabel}
    >
      <button
        type="button"
        onClick={() => setLocale('bn')}
        aria-pressed={locale === 'bn'}
        className={`cursor-pointer px-2.5 py-1 transition-colors ${
          locale === 'bn' ? 'bg-primary-500 text-white' : 'text-gray-600 hover:bg-primary-50'
        }`}
      >
        বাং
      </button>
      <button
        type="button"
        onClick={() => setLocale('en')}
        aria-pressed={locale === 'en'}
        className={`cursor-pointer px-2.5 py-1 transition-colors ${
          locale === 'en' ? 'bg-primary-500 text-white' : 'text-gray-600 hover:bg-primary-50'
        }`}
      >
        EN
      </button>
    </div>
  )
}

// The logged-in counterpart to SiteShell's public top-nav — same sidebar +
// topbar AppShell the management portal uses (App.tsx's DashboardLayout),
// swapped in only once a student is authenticated (see App.tsx's
// StudentAreaShell). Guests browsing the very same routes (books,
// video-lecture, exams all support guest access) keep SiteShell.
export default function StudentAppShell() {
  const { token, name, clearToken } = useAuth()
  const { t, locale, setLocale } = useLocale()

  const navItems: SidebarItem[] = [
    { to: '/dashboard', label: t['student.nav.home'], icon: 'dashboard', end: true },
    { to: '/books', label: t['student.nav.books'], icon: 'menu_book' },
    { to: '/class-materials', label: t['student.nav.classMaterials'], icon: 'folder_open' },
    { to: '/video-lecture', label: t['student.nav.videoLecture'], icon: 'smart_display' },
    { to: '/class-routine', label: t['student.nav.classRoutine'], icon: 'calendar_month' },
    { to: '/attendance', label: t['student.nav.attendance'], icon: 'fact_check' },
    { to: '/class-exams', label: t['student.nav.classExams'], icon: 'assignment' },
    { to: '/exams', label: t['student.nav.exams'], icon: 'quiz' },
    { to: '/leave-application', label: t['student.nav.leaveApplication'], icon: 'event_busy' },
    { to: '/holidays', label: t['student.nav.holidays'], icon: 'celebration' },
  ]

  function handleLogout() {
    if (token) {
      apiLogout(token).catch(() => {})
    }
    clearToken()
  }

  return (
    <AppShell
      title={t['landing.brand']}
      navItems={navItems}
      logoSrc="/logo.webp"
      userLabel={name ?? undefined}
      onLogout={handleLogout}
      logoutLabel={t['student.nav.logout']}
      mainClassName="flex-1"
      headerExtra={
        <LanguageSwitcher locale={locale} setLocale={setLocale} ariaLabel={t['student.nav.languageSwitcher']} />
      }
    />
  )
}
