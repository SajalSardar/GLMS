import { useNavigate } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { AppShell, type SidebarItem } from '@ttla/ui'

const t = getCommonStrings('en')

const navItems: SidebarItem[] = [
  { to: '/dashboard', label: t['teacher.nav.dashboard'], icon: 'dashboard', end: true },
  { to: '/attendance', label: t['teacher.nav.attendance'], icon: 'fact_check' },
  { to: '/leave-applications', label: t['teacher.nav.leaveApplications'], icon: 'event_busy' },
  { to: '/holidays', label: t['teacher.nav.holidays'], icon: 'celebration' },
]

export default function TeacherAppShell() {
  const { token, name, clearToken } = useAuth()
  const navigate = useNavigate()

  // Revokes the token server-side before dropping it locally — same
  // reasoning as the student/management portals' own handleLogout: a
  // Sanctum token never expires on its own, so clearToken() alone would
  // leave it valid indefinitely.
  function handleLogout() {
    if (token) {
      apiLogout(token).catch(() => {})
    }
    clearToken()
    navigate('/', { replace: true })
  }

  return (
    <AppShell
      title={t['teacher.login.portalName']}
      navItems={navItems}
      userLabel={name ?? undefined}
      onLogout={handleLogout}
      logoutLabel={t['teacher.nav.logout']}
    />
  )
}
