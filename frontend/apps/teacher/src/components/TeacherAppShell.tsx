import { useNavigate } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { AppShell, type SidebarItem } from '@ttla/ui'

const t = getCommonStrings('en')

const navItems: SidebarItem[] = [
  { to: '/dashboard', label: t['teacher.nav.dashboard'], icon: 'dashboard', end: true },
  { to: '/class-routine', label: t['teacher.nav.classRoutine'], icon: 'calendar_month' },
  {
    label: t['teacher.nav.attendance'],
    icon: 'fact_check',
    children: [
      { to: '/attendance/take', label: t['teacher.attendance.tabTake'], icon: 'edit_calendar' },
      { to: '/attendance/list', label: t['teacher.attendance.tabList'], icon: 'list_alt' },
      { to: '/attendance/report', label: t['teacher.attendance.tabReport'], icon: 'summarize' },
    ],
  },
  { to: '/marks-entry', label: t['teacher.nav.marksEntry'], icon: 'edit_note' },
  { to: '/marksheets', label: t['teacher.nav.marksheets'], icon: 'description' },
  { to: '/class-materials', label: t['teacher.nav.classMaterials'], icon: 'folder_open' },
  { to: '/student-leave', label: t['teacher.nav.leaveApplications'], icon: 'event_busy' },
  { to: '/teacher-leave', label: t['teacher.nav.teacherLeave'], icon: 'work_off' },
  { to: '/question-builder', label: t['teacher.nav.questionBuilder'], icon: 'quiz' },
  { to: '/my-question-papers', label: t['teacher.nav.myQuestionPapers'], icon: 'description' },
  { to: '/class-exams', label: t['teacher.nav.classExams'], icon: 'fact_check' },
  { to: '/holidays', label: t['teacher.nav.holidays'], icon: 'celebration' },
  { to: '/my-attendance', label: t['teacher.nav.myAttendance'], icon: 'badge' },
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
