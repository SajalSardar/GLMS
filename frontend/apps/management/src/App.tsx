import { BrowserRouter, Navigate, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { AppShell, type SidebarItem } from '@ttla/ui'
import { AuthProvider, useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import AcademicYearsPage from './pages/AcademicYearsPage'
import ClassesPage from './pages/ClassesPage'
import ClassVersionsPage from './pages/ClassVersionsPage'
import DashboardPlaceholder from './pages/DashboardPlaceholder'
import DistrictsPage from './pages/DistrictsPage'
import DivisionsPage from './pages/DivisionsPage'
import ManagementLogin from './pages/ManagementLogin'
import SchoolDetailPage from './pages/SchoolDetailPage'
import SchoolsPage from './pages/SchoolsPage'
import SectionsPage from './pages/SectionsPage'
import ShiftsPage from './pages/ShiftsPage'
import SubjectsPage from './pages/SubjectsPage'
import UpazilasPage from './pages/UpazilasPage'

const t = getCommonStrings('en')

const baseNavItems: SidebarItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: 'dashboard' }]

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
  const { clearToken, role } = useAuth()
  const navigate = useNavigate()

  function handleLogout() {
    clearToken()
    navigate('/', { replace: true })
  }

  const navItems = [
    ...baseNavItems,
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
                <Route path="classes" element={<ClassesPage />} />
                <Route path="academic-years" element={<AcademicYearsPage />} />
                <Route path="class-versions" element={<ClassVersionsPage />} />
                <Route path="shifts" element={<ShiftsPage />} />
                <Route path="sections" element={<SectionsPage />} />
              </Route>
              <Route path="system-setup" element={<RequireNationalAdmin />}>
                <Route path="divisions" element={<DivisionsPage />} />
                <Route path="districts" element={<DistrictsPage />} />
                <Route path="upazilas" element={<UpazilasPage />} />
                <Route path="schools" element={<SchoolsPage />} />
                <Route path="schools/:id" element={<SchoolDetailPage />} />
                <Route path="subjects" element={<SubjectsPage />} />
              </Route>
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
