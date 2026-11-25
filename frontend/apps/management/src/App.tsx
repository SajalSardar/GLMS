import { BrowserRouter, Navigate, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { AppShell, type SidebarItem } from '@ttla/ui'
import { AuthProvider, useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import AcademicExamTermsPage from './pages/AcademicExamTermsPage'
import AcademicGradeConfiguresPage from './pages/AcademicGradeConfiguresPage'
import AcademicSubjectMarkConfiguresPage from './pages/AcademicSubjectMarkConfiguresPage'
import AcademicYearsPage from './pages/AcademicYearsPage'
import AllChaptersPage from './pages/AllChaptersPage'
import AttendanceReportPage from './pages/AttendanceReportPage'
import BooksPage from './pages/BooksPage'
import ChaptersPage from './pages/ChaptersPage'
import ClassesPage from './pages/ClassesPage'
import ClassVersionsPage from './pages/ClassVersionsPage'
import ClassVideosPage from './pages/ClassVideosPage'
import DashboardPlaceholder from './pages/DashboardPlaceholder'
import DistrictsPage from './pages/DistrictsPage'
import DivisionsPage from './pages/DivisionsPage'
import HolidaysPage from './pages/HolidaysPage'
import ManagementClassRoutinePage from './pages/ManagementClassRoutinePage'
import ManagementLogin from './pages/ManagementLogin'
import PermissionsPage from './pages/PermissionsPage'
import PromoteStudentsPage from './pages/PromoteStudentsPage'
import PublishResultsPage from './pages/PublishResultsPage'
import RolePermissionsPage from './pages/RolePermissionsPage'
import RolesPage from './pages/RolesPage'
import SchoolDetailPage from './pages/SchoolDetailPage'
import SchoolsPage from './pages/SchoolsPage'
import SectionsPage from './pages/SectionsPage'
import ShiftsPage from './pages/ShiftsPage'
import StudentDetailPage from './pages/StudentDetailPage'
import StudentsPage from './pages/StudentsPage'
import SubjectsPage from './pages/SubjectsPage'
import SubjectTeacherAssignmentsPage from './pages/SubjectTeacherAssignmentsPage'
import TeacherDetailPage from './pages/TeacherDetailPage'
import TeacherLeaveApplicationsPage from './pages/TeacherLeaveApplicationsPage'
import TeacherRoutinePage from './pages/TeacherRoutinePage'
import TeachersPage from './pages/TeachersPage'
import UpazilasPage from './pages/UpazilasPage'
import UsersPage from './pages/UsersPage'

const t = getCommonStrings('en')

const baseNavItems: SidebarItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: 'dashboard', end: true }]

// Teacher accounts are managed by the same roles that manage sections/
// shifts/etc. (school_admin and national_admin — see RolePermissionSeeder's
// users.* grants), but sit as their own top-level item right after
// Dashboard rather than nested under School Setup/System Setup, per the
// explicit request to place it there.
const teachersNavItem: SidebarItem = { to: '/dashboard/teachers', label: t['management.nav.teachers'], icon: 'person' }

// Same placement reasoning as teachersNavItem above — student accounts
// share the same users.* grants and sit as their own top-level item too.
const studentsNavItem: SidebarItem = { to: '/dashboard/students', label: t['management.nav.students'], icon: 'face' }

// Visible to both school_admin and national_admin — school_admin holds
// books.view/chapters.view/chapter_videos.view but not create/update/
// delete (see RolePermissionSeeder), so GeoCrudTable's permission-gated
// Add/Edit/Delete (see BooksPage.tsx/AllChaptersPage.tsx/
// ClassVideosPage.tsx) renders this read-only for them, not role-gated
// like the route itself. Placed right after Students, per the explicit
// request to move it there.
const classMaterialsNavItem: SidebarItem = {
  label: t['management.nav.classMaterials'],
  icon: 'auto_stories',
  children: [
    { to: '/dashboard/class-materials/books', label: t['management.systemSetup.books'], icon: 'menu_book' },
    { to: '/dashboard/class-materials/chapters', label: t['management.chapters.title'], icon: 'toc' },
    { to: '/dashboard/class-materials/class-videos', label: t['management.systemSetup.classVideos'], icon: 'smart_display' },
  ],
}

// Visible to both school_admin and national_admin — school_admin only
// holds the .view permission on each of these (see RolePermissionSeeder),
// not create/update/delete, so each page's own GeoCrudTable permission
// props render it read-only for them. Placed right after Class
// Materials, per the explicit request to move it there.
const examControlNavItem: SidebarItem = {
  label: t['management.nav.examControl'],
  icon: 'rule',
  children: [
    { to: '/dashboard/exam-control/academic-exam-terms', label: t['management.systemSetup.academicExamTerms'], icon: 'event_note' },
    { to: '/dashboard/exam-control/academic-grade-configures', label: t['management.systemSetup.academicGradeConfigures'], icon: 'grade' },
    { to: '/dashboard/exam-control/academic-subject-mark-configures', label: t['management.systemSetup.academicSubjectMarkConfigures'], icon: 'checklist' },
  ],
}

// Report-only (see AttendanceReportPage's own comment) — visible to the
// same school_admin/national_admin pair as teachersNavItem/studentsNavItem
// above, since recording itself stays in the teacher portal.
const attendanceNavItem: SidebarItem = { to: '/dashboard/attendance', label: t['management.nav.attendance'], icon: 'fact_check' }

// Same placement/visibility reasoning as attendanceNavItem above —
// school_admin/national_admin only; recording stays in the teacher
// portal, this page only reports status and fires the publish action.
const publishResultsNavItem: SidebarItem = { to: '/dashboard/publish-results', label: t['management.nav.publishResults'], icon: 'campaign' }

// Same placement/visibility reasoning as attendanceNavItem/publishResultsNavItem
// above — school_admin/national_admin only.
const promoteStudentsNavItem: SidebarItem = { to: '/dashboard/promote-students', label: t['management.nav.promoteStudents'], icon: 'move_up' }

// A collapsible group (same shape as systemSetupItem below) rather than
// two flat items — Teacher Routine mirrors the Teacher Portal's own
// Class Routine page (any teacher, not just "mine"); Class Routine
// mirrors the Student Portal's own Class Routine page (any section).
// Same school_admin/national_admin visibility as the items above; each
// page itself restricts a school_admin's own School picker to their own
// school (see TeacherRoutinePage.tsx/ManagementClassRoutinePage.tsx).
const routineNavItem: SidebarItem = {
  label: t['management.nav.routine'],
  icon: 'calendar_month',
  children: [
    { to: '/dashboard/routine/teacher', label: t['management.nav.teacherRoutine'], icon: 'person' },
    { to: '/dashboard/routine/class', label: t['management.nav.classRoutine'], icon: 'groups' },
  ],
}

// The academic holiday calendar is national, not per-school — no
// TenantScope/school filtering applies to it. Visible to both
// school_admin and national_admin now (school_admin holds holidays.view
// but not create/update/delete, per RolePermissionSeeder — gated via
// GeoCrudTable's permission props on HolidaysPage.tsx, not via this nav
// item's role check).
const holidaysNavItem: SidebarItem = { to: '/dashboard/holidays', label: t['management.nav.holidays'], icon: 'event_busy' }

// Visible to both school_admin and national_admin. school_admin holds full
// CRUD on Users (its own school's staff accounts, auto-scoped by
// TenantScope) but only roles.view/permissions.view — no roles.create/
// update/delete or roles.updatePermissions — so UsersPage renders fully
// editable for it while RolesPage/PermissionsPage render read-only, all
// via each page's own GeoCrudTable permission props (see
// RolePermissionSeeder), not via this nav item's role check.
const usersRolesNavItem: SidebarItem = {
  label: t['management.nav.usersRoles'],
  icon: 'manage_accounts',
  children: [
    { to: '/dashboard/users-roles/users', label: t['management.nav.users'], icon: 'person' },
    { to: '/dashboard/users-roles/roles', label: t['management.nav.roles'], icon: 'shield_person' },
    { to: '/dashboard/users-roles/permissions', label: t['management.nav.permissions'], icon: 'key' },
  ],
}

// school_admin only — reviews their own school's teacher leave requests
// (TenantScope-filtered). national_admin gets a separate, read-only
// "Teachers on Leave" item instead (teachersOnLeaveNavItem, below) — see
// RequireSchoolAdmin's own comment for why this isn't shared.
const teacherLeaveApplicationsNavItem: SidebarItem = {
  to: '/dashboard/teacher-leave-applications',
  label: t['management.nav.teacherLeaveApplications'],
  icon: 'work_off',
}

// Class versions, shifts, sections, and subject-teacher assignments are
// configured per-school by either role (see RolePermissionSeeder) — nested
// under a "School Setup" group for both roles (schoolSetupItem for
// national_admin, schoolAdminSchoolSetupItem for school_admin, below).
const schoolOperationsNavItems: SidebarItem[] = [
  {
    to: '/dashboard/subject-teacher-assignments',
    label: t['management.nav.subjectTeacherAssignments'],
    icon: 'assignment_ind',
  },
  { to: '/dashboard/class-versions', label: t['management.nav.classVersions'], icon: 'layers' },
  { to: '/dashboard/shifts', label: t['management.nav.shifts'], icon: 'schedule' },
  { to: '/dashboard/sections', label: t['management.nav.sections'], icon: 'groups' },
]

// national_admin only — a national_admin manages every school's
// operations data from one place. Unlike schoolAdminSchoolSetupItem
// below, this doesn't nest Classes/Academic Years — those sit under
// national_admin's own "System Setup" group instead (systemSetupItem).
const schoolSetupItem: SidebarItem = {
  label: t['management.nav.schoolSetup'],
  icon: 'school',
  children: [
    { to: '/dashboard/schools', label: t['management.schools.title'], icon: 'apartment' },
    ...schoolOperationsNavItems,
  ],
}

// Classes and academic years are configured per-school, not only by a
// national admin — the backend grants school_admin create/update/delete
// on both (see RolePermissionSeeder), unlike the divisions/districts/
// upazilas reference data below. A national admin sees them nested under
// "System Setup" (last two items in that list); a school admin sees them
// nested under their own "School Setup" group instead
// (schoolAdminSchoolSetupItem, below).
const classesAndYearsNavItems: SidebarItem[] = [
  { to: '/dashboard/classes', label: t['management.nav.classes'], icon: 'class' },
  { to: '/dashboard/academic-years', label: t['management.nav.academicYears'], icon: 'event' },
]

// school_admin has no System Setup group of its own (that's national_admin
// only — see systemSetupItem below), so Schools, Classes, Academic Years,
// and the four school-operations items all collapse into this one "School
// Setup" group instead, in the order they're managed day to day: school,
// classes, academic years, assign teacher, class versions, shifts,
// sections.
const schoolAdminSchoolSetupItem: SidebarItem = {
  label: t['management.nav.schoolSetup'],
  icon: 'school',
  children: [
    { to: '/dashboard/schools', label: t['management.schools.title'], icon: 'apartment' },
    ...classesAndYearsNavItems,
    ...schoolOperationsNavItems,
  ],
}

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
    { to: '/dashboard/system-setup/subjects', label: t['management.systemSetup.subjects'], icon: 'book' },
    ...classesAndYearsNavItems,
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

// Narrower than RequireSchoolManager: national_admin technically holds
// teacher_leave_applications.update too (its all-permissions wildcard —
// see TeacherLeaveApplicationPolicy::review()'s own comment), but this
// review page is deliberately school_admin-only per the product decision
// that national_admin gets a read-only view instead (TeachersOnLeavePage).
function RequireSchoolAdmin() {
  const { role } = useAuth()

  return role === 'school_admin' ? (
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
    ...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem] : []),
    ...(role === 'national_admin' || role === 'school_admin' ? [classMaterialsNavItem, examControlNavItem] : []),
    ...(role === 'national_admin' || role === 'school_admin'
      ? [attendanceNavItem, publishResultsNavItem, promoteStudentsNavItem, routineNavItem]
      : []),
    ...(role === 'school_admin' ? [teacherLeaveApplicationsNavItem] : []),
    ...(role === 'national_admin' ? [holidaysNavItem, schoolSetupItem] : []),
    ...(role === 'school_admin' ? [holidaysNavItem, schoolAdminSchoolSetupItem] : []),
    ...(role === 'national_admin' || role === 'school_admin' ? [usersRolesNavItem] : []),
    ...(role === 'national_admin' ? [systemSetupItem] : []),
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
                <Route path="publish-results" element={<PublishResultsPage />} />
                <Route path="promote-students" element={<PromoteStudentsPage />} />
                <Route path="routine/teacher" element={<TeacherRoutinePage />} />
                <Route path="routine/class" element={<ManagementClassRoutinePage />} />
                <Route path="users-roles/users" element={<UsersPage />} />
                <Route path="users-roles/roles" element={<RolesPage />} />
                <Route path="users-roles/roles/:id/permissions" element={<RolePermissionsPage />} />
                <Route path="users-roles/permissions" element={<PermissionsPage />} />
              </Route>
              <Route path="teacher-leave-applications" element={<RequireSchoolAdmin />}>
                <Route index element={<TeacherLeaveApplicationsPage />} />
              </Route>
              <Route path="holidays" element={<RequireSchoolManager />}>
                <Route index element={<HolidaysPage />} />
              </Route>
              <Route path="schools" element={<RequireSchoolManager />}>
                <Route index element={<SchoolsPage />} />
                <Route path=":id" element={<SchoolDetailPage />} />
              </Route>
              <Route path="class-materials" element={<RequireSchoolManager />}>
                <Route path="books" element={<BooksPage />} />
                <Route path="books/:bookId/chapters" element={<ChaptersPage />} />
                <Route path="chapters" element={<AllChaptersPage />} />
                <Route path="class-videos" element={<ClassVideosPage />} />
              </Route>
              <Route path="exam-control" element={<RequireSchoolManager />}>
                <Route path="academic-exam-terms" element={<AcademicExamTermsPage />} />
                <Route path="academic-grade-configures" element={<AcademicGradeConfiguresPage />} />
                <Route path="academic-subject-mark-configures" element={<AcademicSubjectMarkConfiguresPage />} />
              </Route>
              <Route path="system-setup" element={<RequireNationalAdmin />}>
                <Route path="divisions" element={<DivisionsPage />} />
                <Route path="districts" element={<DistrictsPage />} />
                <Route path="upazilas" element={<UpazilasPage />} />
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
