import { BrowserRouter, Navigate, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { AppShell, type SidebarItem } from '@ttla/ui'
import { AuthProvider, useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import AcademicExamTermsPage from './pages/AcademicExamTermsPage'
import AcademicGradeConfiguresPage from './pages/AcademicGradeConfiguresPage'
import AcademicYearsPage from './pages/AcademicYearsPage'
import AttendanceReportPage from './pages/AttendanceReportPage'
import ClassesPage from './pages/ClassesPage'
import ClassVersionsPage from './pages/ClassVersionsPage'
import DashboardPlaceholder from './pages/DashboardPlaceholder'
import DistrictsPage from './pages/DistrictsPage'
import DivisionsPage from './pages/DivisionsPage'
import HolidaysPage from './pages/HolidaysPage'
import ManagementLogin from './pages/ManagementLogin'
import SchoolDetailPage from './pages/SchoolDetailPage'
import SchoolsPage from './pages/SchoolsPage'
import SectionsPage from './pages/SectionsPage'
import ShiftsPage from './pages/ShiftsPage'
import StudentDetailPage from './pages/StudentDetailPage'
import StudentsPage from './pages/StudentsPage'
import SubjectsPage from './pages/SubjectsPage'
import SubjectTeacherAssignmentsPage from './pages/SubjectTeacherAssignmentsPage'
import TeacherDetailPage from './pages/TeacherDetailPage'
import TeachersPage from './pages/TeachersPage'
import UpazilasPage from './pages/UpazilasPage'

const t = getCommonStrings('en')

const baseNavItems: SidebarItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: 'dashboard', end: true }]

// Teacher accounts are managed by the same roles that manage sections/
// shifts/etc. (school_admin and national_admin — see RolePermissionSeeder's
// users.* grants), but sit as their own top-level item right after
// Dashboard rather than nested under schoolManagedNavItems/System Setup,
// per the explicit request to place it there.
const teachersNavItem: SidebarItem = { to: '/dashboard/teachers', label: t['management.nav.teachers'], icon: 'person' }

// Same placement reasoning as teachersNavItem above — student accounts
// share the same users.* grants and sit as their own top-level item too.
const studentsNavItem: SidebarItem = { to: '/dashboard/students', label: t['management.nav.students'], icon: 'face' }

// Report-only (see AttendanceReportPage's own comment) — visible to the
// same school_admin/national_admin pair as teachersNavItem/studentsNavItem
// above, since recording itself stays in the teacher portal.
const attendanceNavItem: SidebarItem = { to: '/dashboard/attendance', label: t['management.nav.attendance'], icon: 'fact_check' }

// The academic holiday calendar is national, not per-school — only a
// national_admin may create/update/delete it (see HolidayPolicy), so
// unlike teachersNavItem/studentsNavItem above this is national_admin
// only, not also school_admin. Teachers/students view it read-only from
// their own portals instead of through this management UI.
const holidaysNavItem: SidebarItem = { to: '/dashboard/holidays', label: t['management.nav.holidays'], icon: 'event_busy' }

// Classes and academic years are configured per-school, not only by a
// national admin — the backend grants school_admin create/update/delete
// on both (see RolePermissionSeeder), unlike the divisions/districts/
// upazilas/schools reference data below. A national admin sees them
// nested under "System Setup" (last two items, after Schools); a school
// admin — who has no access to the rest of that national-admin-only
// group — sees these same two as flat top-level items instead.
const schoolManagedNavItems: SidebarItem[] = [
  { to: '/dashboard/classes', label: t['management.nav.classes'], icon: 'class' },
  { to: '/dashboard/academic-years', label: t['management.nav.academicYears'], icon: 'event' },
  { to: '/dashboard/class-versions', label: t['management.nav.classVersions'], icon: 'layers' },
  { to: '/dashboard/shifts', label: t['management.nav.shifts'], icon: 'schedule' },
  { to: '/dashboard/sections', label: t['management.nav.sections'], icon: 'groups' },
  {
    to: '/dashboard/subject-teacher-assignments',
    label: t['management.nav.subjectTeacherAssignments'],
    icon: 'assignment_ind',
  },
]

const systemSetupItem: SidebarItem = {
  label: t['management.systemSetup.menu'],
  icon: 'settings',
  children: [
    { to: '/dashboard/system-setup/divisions', label: t['management.systemSetup.divisions'], icon: 'layers' },
    {
      to: '/dashboard/system-setup/districts',
      label: t['management.systemSetup.districts'],
      icon: 'location_city',
    },
    { to: '/dashboard/system-setup/upazilas', label: t['management.systemSetup.upazilas'], icon: 'location_on' },
    { to: '/dashboard/system-setup/schools', label: t['management.systemSetup.schools'], icon: 'school' },
    { to: '/dashboard/system-setup/subjects', label: t['management.systemSetup.subjects'], icon: 'book' },
    { to: '/dashboard/system-setup/academic-exam-terms', label: t['management.systemSetup.academicExamTerms'], icon: 'event_note' },
    { to: '/dashboard/system-setup/academic-grade-configures', label: t['management.systemSetup.academicGradeConfigures'], icon: 'grade' },
    ...schoolManagedNavItems,
  ],
}

function formatRole(role: string): string {
  return role
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function LoginRoute() {
  const { token } = useAuth()

  return token ? <Navigate to="/dashboard" replace /> : <ManagementLogin />
}

function RequireAuth() {
  const { token } = useAuth()

  return token ? <Outlet /> : <Navigate to="/" replace />
}

function RequireNationalAdmin() {
  const { role } = useAuth()

  return role === 'national_admin' ? (
    <Outlet />
  ) : (
    <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">
      {t['management.table.accessDenied']}
    </p>
  )
}

function RequireSchoolManager() {
  const { role } = useAuth()

  return role === 'national_admin' || role === 'school_admin' ? (
    <Outlet />
  ) : (
    <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">
      {t['management.table.accessDenied']}
    </p>
  )
}

function DashboardLayout() {
  const { clearToken, role, token } = useAuth()
  const navigate = useNavigate()

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
    navigate('/', { replace: true })
  }

  const navItems = [
    ...baseNavItems,
    ...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem, attendanceNavItem] : []),
    ...(role === 'national_admin' ? [holidaysNavItem] : []),
    ...(role === 'national_admin' ? [systemSetupItem] : role === 'school_admin' ? schoolManagedNavItems : []),
  ]

  return (
    <AppShell
      title="Management Portal"
      navItems={navItems}
      logoSrc="/logo.webp"
      userLabel={role ? formatRole(role) : undefined}
      onLogout={handleLogout}
      logoutLabel={t['management.logout']}
    />
  )
}

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<LoginRoute />} />
          <Route element={<RequireAuth />}>
            <Route path="/dashboard" element={<DashboardLayout />}>
              <Route index element={<DashboardPlaceholder />} />
              <Route element={<RequireSchoolManager />}>
                <Route path="teachers" element={<TeachersPage />} />
                <Route path="teachers/new" element={<TeacherDetailPage />} />
                <Route path="teachers/:id" element={<TeacherDetailPage />} />
                <Route path="students" element={<StudentsPage />} />
                <Route path="students/new" element={<StudentDetailPage />} />
                <Route path="students/:id" element={<StudentDetailPage />} />
                <Route path="classes" element={<ClassesPage />} />
                <Route path="academic-years" element={<AcademicYearsPage />} />
                <Route path="class-versions" element={<ClassVersionsPage />} />
                <Route path="shifts" element={<ShiftsPage />} />
                <Route path="sections" element={<SectionsPage />} />
                <Route path="subject-teacher-assignments" element={<SubjectTeacherAssignmentsPage />} />
                <Route path="attendance" element={<AttendanceReportPage />} />
              </Route>
              <Route path="holidays" element={<RequireNationalAdmin />}>
                <Route index element={<HolidaysPage />} />
              </Route>
              <Route path="system-setup" element={<RequireNationalAdmin />}>
                <Route path="divisions" element={<DivisionsPage />} />
                <Route path="districts" element={<DistrictsPage />} />
                <Route path="upazilas" element={<UpazilasPage />} />
                <Route path="schools" element={<SchoolsPage />} />
                <Route path="schools/:id" element={<SchoolDetailPage />} />
                <Route path="subjects" element={<SubjectsPage />} />
                <Route path="academic-exam-terms" element={<AcademicExamTermsPage />} />
                <Route path="academic-grade-configures" element={<AcademicGradeConfiguresPage />} />
              </Route>
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
