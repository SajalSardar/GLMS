import { BrowserRouter, Navigate, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { logout as apiLogout } from '@ttla/api-client'
import { AppShell, type SidebarItem } from '@ttla/ui'
import { AuthProvider, useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import AcademicExamTermsPage from './pages/AcademicExamTermsPage'
import AcademicGradeConfiguresPage from './pages/AcademicGradeConfiguresPage'
import AcademicSubjectMarkConfiguresPage from './pages/AcademicSubjectMarkConfiguresPage'
import AcademicYearsPage from './pages/AcademicYearsPage'
import AcademicQuestionsPage from './pages/AcademicQuestionsPage'
import AllChaptersPage from './pages/AllChaptersPage'
import AttendanceReportPage from './pages/AttendanceReportPage'
import BooksPage from './pages/BooksPage'
import ChaptersPage from './pages/ChaptersPage'
import ClassesPage from './pages/ClassesPage'
import ClassVersionsPage from './pages/ClassVersionsPage'
import ClassVideosPage from './pages/ClassVideosPage'
import DashboardPage from './pages/DashboardPage'
import DistrictsPage from './pages/DistrictsPage'
import DivisionsPage from './pages/DivisionsPage'
import ExamQuestionsPage from './pages/ExamQuestionsPage'
import ExamsPage from './pages/ExamsPage'
import HolidaysPage from './pages/HolidaysPage'
import KioskDevicesPage from './pages/KioskDevicesPage'
import ManagementClassRoutinePage from './pages/ManagementClassRoutinePage'
import ManagementLogin from './pages/ManagementLogin'
import MyProfilePage from './pages/MyProfilePage'
import PermissionsPage from './pages/PermissionsPage'
import PromoteStudentsPage from './pages/PromoteStudentsPage'
import QuestionPapersPage from './pages/QuestionPapersPage'
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
import TeacherAttendanceReportPage from './pages/TeacherAttendanceReportPage'
import TeacherDetailPage from './pages/TeacherDetailPage'
import TeacherLeaveApplicationsPage from './pages/TeacherLeaveApplicationsPage'
import TeacherRoutinePage from './pages/TeacherRoutinePage'
import TeachersOnLeavePage from './pages/TeachersOnLeavePage'
import TeachersPage from './pages/TeachersPage'
import UpazilasPage from './pages/UpazilasPage'
import UsersPage from './pages/UsersPage'

const t = getCommonStrings('en')

const baseNavItems: SidebarItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: 'dashboard', end: true }]

// Visible to TEACHER_PROFILE_VIEWERS (national_admin, school_admin,
// district_officer, trainer, auditor — see that constant's own comment),
// but sits as its own top-level item right after Dashboard rather than
// nested under School Setup/System Setup, per the explicit request to
// place it there. school_admin/national_admin hold full teacher_profiles
// CRUD; the other three are view-only (GeoCrudTable's permission-gated
// Add/Edit/Delete on TeachersPage.tsx renders this read-only for them,
// not role-gated like the route itself).
const teachersNavItem: SidebarItem = { to: '/dashboard/teachers', label: t['management.nav.teachers'], icon: 'person' }

// Visible to STUDENT_AND_ATTENDANCE_VIEWERS (national_admin, school_admin,
// district_officer, auditor) — same placement reasoning as teachersNavItem
// above, sitting as its own top-level item too.
const studentsNavItem: SidebarItem = { to: '/dashboard/students', label: t['management.nav.students'], icon: 'face' }

// Visible to every management-portal-eligible role (ALL_MANAGEMENT_ROLES,
// below) — only school_admin/national_admin hold create/update/delete on
// books.view/chapters.view/chapter_videos.view (see RolePermissionSeeder),
// so GeoCrudTable's permission-gated Add/Edit/Delete (see BooksPage.tsx/
// AllChaptersPage.tsx/ClassVideosPage.tsx) renders this read-only for
// everyone else, not role-gated like the route itself. Placed right after
// Students, per the explicit request to move it there.
const classMaterialsNavItem: SidebarItem = {
  label: t['management.nav.classMaterials'],
  icon: 'auto_stories',
  children: [
    { to: '/dashboard/class-materials/books', label: t['management.systemSetup.books'], icon: 'menu_book' },
    { to: '/dashboard/class-materials/chapters', label: t['management.chapters.title'], icon: 'toc' },
    { to: '/dashboard/class-materials/class-videos', label: t['management.systemSetup.classVideos'], icon: 'smart_display' },
  ],
}

// Visible to every management-portal-eligible role (ALL_MANAGEMENT_ROLES,
// below) — only national_admin holds create/update/delete on these three
// (see RolePermissionSeeder); everyone else's .view-only grant renders
// read-only via each page's own GeoCrudTable permission props. Placed
// right after Class Materials, per the explicit request to move it there.
const examControlNavItem: SidebarItem = {
  label: t['management.nav.examControl'],
  icon: 'rule',
  children: [
    { to: '/dashboard/exam-control/academic-exam-terms', label: t['management.systemSetup.academicExamTerms'], icon: 'event_note' },
    { to: '/dashboard/exam-control/academic-grade-configures', label: t['management.systemSetup.academicGradeConfigures'], icon: 'grade' },
    { to: '/dashboard/exam-control/academic-subject-mark-configures', label: t['management.systemSetup.academicSubjectMarkConfigures'], icon: 'checklist' },
  ],
}

// Report-only (see AttendanceReportPage's own comment) — visible to
// STUDENT_AND_ATTENDANCE_VIEWERS, the same role set as studentsNavItem
// above, since recording itself stays in the teacher portal.
const attendanceNavItem: SidebarItem = { to: '/dashboard/attendance', label: t['management.nav.attendance'], icon: 'fact_check' }

// Same placement/visibility reasoning as attendanceNavItem above —
// school_admin/national_admin only; a school_admin's device is scoped to
// their own school automatically (see KioskDevicesPage.tsx), national_admin
// picks which school.
const kioskDevicesNavItem: SidebarItem = { to: '/dashboard/kiosk-devices', label: t['management.nav.kioskDevices'], icon: 'point_of_sale' }

// Same placement/visibility reasoning as attendanceNavItem above — a
// monthly grid of every teacher's attendance at a school, mirroring
// attendanceNavItem's student-side report but driven by kiosk-device
// punches instead of a class teacher's manual entry.
const teacherAttendanceNavItem: SidebarItem = { to: '/dashboard/teacher-attendance', label: t['management.nav.teacherAttendance'], icon: 'badge' }

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
// Visible to TEACHER_PROFILE_VIEWERS — Teacher Routine's own teacher
// picker needs that same permission, which is why this whole group is
// gated on it rather than something routine-specific; each page itself
// restricts a school_admin's own School picker to their own school (see
// TeacherRoutinePage.tsx/ManagementClassRoutinePage.tsx).
const routineNavItem: SidebarItem = {
  label: t['management.nav.routine'],
  icon: 'calendar_month',
  children: [
    { to: '/dashboard/routine/teacher', label: t['management.nav.teacherRoutine'], icon: 'person' },
    { to: '/dashboard/routine/class', label: t['management.nav.classRoutine'], icon: 'groups' },
  ],
}

// The academic holiday calendar is national, not per-school — no
// TenantScope/school filtering applies to it. Visible to every
// management-portal-eligible role (only school_admin/national_admin hold
// create/update/delete, per RolePermissionSeeder — gated via
// GeoCrudTable's permission props on HolidaysPage.tsx, not via this nav
// item's role check).
const holidaysNavItem: SidebarItem = { to: '/dashboard/holidays', label: t['management.nav.holidays'], icon: 'event_busy' }

// national_admin only — the national question bank. teacher browses it
// (academic_questions.view) to build papers in the teacher portal;
// school_admin never gets access (see questionPapersNavItem instead,
// which is school_admin's own, print-only, teacher-paper view).
const questionBankNavItem: SidebarItem = {
  to: '/dashboard/question-bank',
  label: t['management.nav.questionBank'],
  icon: 'quiz',
}

// national_admin/district_officer/trainer (ASSESSMENT_VIEWERS, below) —
// MCQ/true-false exam question authoring and the Exam shells (class/
// subject/mode/availability) that attach them. Fully separate from
// questionBankNavItem above: that's the CQ/MCQ paper-building question
// bank teachers draw from for their own printed papers; this is the
// formal timed-exam system (Exam/Question/ExamAttempt) students take via
// the student portal's Class Exams page. school_admin holds the same
// exams.view/questions.view permissions but is deliberately excluded from
// this UI by product decision.
const assessmentsNavItem: SidebarItem = {
  label: t['management.nav.assessmentsGroup'],
  icon: 'fact_check',
  children: [
    { to: '/dashboard/assessments/exams', label: t['management.nav.exams'], icon: 'fact_check' },
    { to: '/dashboard/assessments/questions', label: t['management.nav.examQuestions'], icon: 'quiz' },
  ],
}

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
// the comment on the "teachers-on-leave" <Route> in App() for why this
// isn't shared.
const teacherLeaveApplicationsNavItem: SidebarItem = {
  to: '/dashboard/teacher-leave-applications',
  label: t['management.nav.teacherLeaveApplications'],
  icon: 'work_off',
}

// school_admin only — print-only view of every question paper built by
// any teacher at their own school (TenantScope-filtered). national_admin
// never gets this nav item: it manages the underlying question bank
// instead (questionBankNavItem), not individual teachers' papers.
const questionPapersNavItem: SidebarItem = {
  to: '/dashboard/question-papers',
  label: t['management.nav.questionPapers'],
  icon: 'description',
}

// national_admin only — read-only. Approving/rejecting stays exclusively
// a school_admin action (see teacherLeaveApplicationsNavItem above);
// national_admin can technically call the same review endpoint (its
// wildcard permission set is never excluded from an ability by role-name
// check in this app) but this portal deliberately builds no UI for it —
// see the comment on the "teachers-on-leave" <Route> in App() for why.
const teachersOnLeaveNavItem: SidebarItem = {
  to: '/dashboard/teachers-on-leave',
  label: t['management.nav.teachersOnLeave'],
  icon: 'person_off',
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

// TEACHER_PROFILE_VIEWERS minus school_admin (national_admin,
// district_officer, trainer, auditor) — each manages every school's
// operations data from one place rather than only their own. Unlike
// schoolAdminSchoolSetupItem below, this doesn't nest Classes/Academic
// Years — those sit under the "System Setup" group instead
// (systemSetupItem), alongside divisions/districts/upazilas/subjects.
const schoolSetupItem: SidebarItem = {
  label: t['management.nav.schoolSetup'],
  icon: 'school',
  children: [
    { to: '/dashboard/schools', label: t['management.schools.title'], icon: 'apartment' },
    ...schoolOperationsNavItems,
  ],
}

// it_admin's own variant of schoolSetupItem above: it holds schools.view/
// class_versions.view/shifts.view/sections.view but not
// subject_teacher_assignments.view (see RolePermissionSeeder), so its
// School Setup group drops that one child rather than reusing
// schoolOperationsNavItems (whose first entry is Subject-Teacher
// Assignments) wholesale.
const itAdminSchoolSetupItem: SidebarItem = {
  label: t['management.nav.schoolSetup'],
  icon: 'school',
  children: [
    { to: '/dashboard/schools', label: t['management.schools.title'], icon: 'apartment' },
    ...schoolOperationsNavItems.slice(1),
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

// Six management-portal-eligible roles now exist (config/portals.php's
// 'management' allowlist), each with its own permission subset from
// RolePermissionSeeder — too many route/role combinations for one named
// guard component per combination, so routes instead pass their own exact
// role list to the single RequireRole component below. Each constant here
// documents which permission(s) it stands in for, and which roles hold
// them, among the six.
// Every management-portal-eligible role holds .view on: schools, classes,
// academic_years, class_versions, shifts, sections, holidays, books,
// chapters, chapter_videos, chapter_materials, academic_exam_terms,
// academic_grade_configures, academic_subject_mark_configures.
const ALL_MANAGEMENT_ROLES = ['national_admin', 'school_admin', 'district_officer', 'trainer', 'it_admin', 'auditor']
const SCHOOL_MANAGERS = ['national_admin', 'school_admin']
// teacher_profiles.view holders — also doubles as "can use the teacher
// picker", so it gates Subject-Teacher Assignments and Routine (Teacher
// Routine's picker needs it) too, not just the Teachers list itself.
const TEACHER_PROFILE_VIEWERS = ['national_admin', 'school_admin', 'district_officer', 'trainer', 'auditor']
// student_profiles.view and attendances.view are held by the exact same
// role set among the six.
const STUDENT_AND_ATTENDANCE_VIEWERS = ['national_admin', 'school_admin', 'district_officer', 'auditor']
// Holds BOTH exams.view and questions.view, which assessmentsNavItem's two
// children each need — school_admin holds both too but is deliberately
// excluded here by product decision (see assessmentsNavItem's own
// comment); it_admin/auditor hold at most one of the two and are excluded
// rather than showing a group with one dead child.
const ASSESSMENT_VIEWERS = ['national_admin', 'district_officer', 'trainer']
// divisions/districts/upazilas/subjects/classes/academic_years.view — held
// by every one of the six roles, but school_admin gets its own "School
// Setup" group instead (schoolAdminSchoolSetupItem) rather than this one,
// by the same kind of product decision as ASSESSMENT_VIEWERS above.
const SYSTEM_SETUP_VIEWERS = ['national_admin', 'district_officer', 'trainer', 'it_admin', 'auditor']
// users.view/roles.view/permissions.view holders — school_admin and
// national_admin get full/partial CRUD there (gated per-page), auditor
// gets the same pages fully read-only (no matching create/update/delete
// permission).
const USERS_ROLES_VIEWERS = ['national_admin', 'school_admin', 'auditor']

function RequireRole({ roles }: { roles: string[] }) {
  const { role } = useAuth()

  return role !== null && roles.includes(role) ? (
    <Outlet />
  ) : (
    <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">
      {t['management.table.accessDenied']}
    </p>
  )
}

function DashboardLayout() {
  const { clearToken, name, role, token } = useAuth()
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

  const isSchoolAdmin = role === 'school_admin'
  const isNationalAdmin = role === 'national_admin'
  const isItAdmin = role === 'it_admin'
  const canViewTeachers = role !== null && TEACHER_PROFILE_VIEWERS.includes(role)
  const canViewStudentsAndAttendance = role !== null && STUDENT_AND_ATTENDANCE_VIEWERS.includes(role)
  const canViewAssessments = role !== null && ASSESSMENT_VIEWERS.includes(role)
  const canViewSystemSetup = role !== null && SYSTEM_SETUP_VIEWERS.includes(role)
  const canViewUsersRoles = role !== null && USERS_ROLES_VIEWERS.includes(role)

  const navItems = [
    ...baseNavItems,
    ...(canViewTeachers ? [teachersNavItem] : []),
    ...(canViewStudentsAndAttendance ? [studentsNavItem] : []),
    // classMaterialsNavItem/examControlNavItem/holidaysNavItem are always
    // shown — every management-portal-eligible role holds .view on all of
    // books/chapters/chapter_videos/chapter_materials, the three exam
    // control config resources, and holidays (see ALL_MANAGEMENT_ROLES).
    classMaterialsNavItem,
    examControlNavItem,
    ...(canViewStudentsAndAttendance ? [attendanceNavItem] : []),
    ...(isNationalAdmin || isSchoolAdmin
      ? [kioskDevicesNavItem, teacherAttendanceNavItem, publishResultsNavItem, promoteStudentsNavItem]
      : []),
    ...(canViewTeachers ? [routineNavItem] : []),
    ...(isSchoolAdmin ? [teacherLeaveApplicationsNavItem, questionPapersNavItem] : []),
    ...(isNationalAdmin ? [teachersOnLeaveNavItem, questionBankNavItem] : []),
    holidaysNavItem,
    ...(canViewAssessments ? [assessmentsNavItem] : []),
    // Three mutually exclusive "School Setup" variants, one per shape of
    // access to schools.view + schoolOperationsNavItems (see each
    // constant's own comment for which roles hold what): school_admin's
    // own (classes/years nested in, since it has no System Setup group of
    // its own); it_admin's (no Subject-Teacher Assignments child, since it
    // lacks that permission); everyone else who reaches here at all also
    // holds every schoolOperationsNavItems permission (same role set as
    // TEACHER_PROFILE_VIEWERS, minus school_admin).
    ...(isSchoolAdmin ? [schoolAdminSchoolSetupItem] : []),
    ...(isItAdmin ? [itAdminSchoolSetupItem] : []),
    ...(canViewTeachers && !isSchoolAdmin ? [schoolSetupItem] : []),
    ...(canViewUsersRoles ? [usersRolesNavItem] : []),
    ...(canViewSystemSetup ? [systemSetupItem] : []),
  ]

  return (
    <AppShell
      title="Management Portal"
      navItems={navItems}
      logoSrc="/logo.webp"
      userName={name ?? undefined}
      userRole={role ? formatRole(role) : undefined}
      profileTo="/dashboard/profile"
      profileLabel={t['management.nav.profile']}
      notificationsLabel={t['management.nav.notifications']}
      noNotificationsLabel={t['management.nav.noNotifications']}
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
              <Route index element={<DashboardPage />} />
              {/* No RequireRole guard — every management-portal role may
                  view/edit their own account, regardless of what else
                  they're permissioned for. */}
              <Route path="profile" element={<MyProfilePage />} />
              <Route element={<RequireRole roles={ALL_MANAGEMENT_ROLES} />}>
                <Route path="classes" element={<ClassesPage />} />
                <Route path="academic-years" element={<AcademicYearsPage />} />
                <Route path="class-versions" element={<ClassVersionsPage />} />
                <Route path="shifts" element={<ShiftsPage />} />
                <Route path="sections" element={<SectionsPage />} />
                <Route path="holidays" element={<HolidaysPage />} />
                <Route path="schools" element={<SchoolsPage />} />
                <Route path="schools/:id" element={<SchoolDetailPage />} />
                <Route path="class-materials/books" element={<BooksPage />} />
                <Route path="class-materials/books/:bookId/chapters" element={<ChaptersPage />} />
                <Route path="class-materials/chapters" element={<AllChaptersPage />} />
                <Route path="class-materials/class-videos" element={<ClassVideosPage />} />
                <Route path="exam-control/academic-exam-terms" element={<AcademicExamTermsPage />} />
                <Route path="exam-control/academic-grade-configures" element={<AcademicGradeConfiguresPage />} />
                <Route path="exam-control/academic-subject-mark-configures" element={<AcademicSubjectMarkConfiguresPage />} />
              </Route>
              <Route element={<RequireRole roles={TEACHER_PROFILE_VIEWERS} />}>
                <Route path="teachers" element={<TeachersPage />} />
                <Route path="teachers/new" element={<TeacherDetailPage />} />
                <Route path="teachers/:id" element={<TeacherDetailPage />} />
                <Route path="subject-teacher-assignments" element={<SubjectTeacherAssignmentsPage />} />
                <Route path="routine/teacher" element={<TeacherRoutinePage />} />
                <Route path="routine/class" element={<ManagementClassRoutinePage />} />
              </Route>
              <Route element={<RequireRole roles={STUDENT_AND_ATTENDANCE_VIEWERS} />}>
                <Route path="students" element={<StudentsPage />} />
                <Route path="students/new" element={<StudentDetailPage />} />
                <Route path="students/:id" element={<StudentDetailPage />} />
                <Route path="attendance" element={<AttendanceReportPage />} />
              </Route>
              <Route element={<RequireRole roles={SCHOOL_MANAGERS} />}>
                <Route path="kiosk-devices" element={<KioskDevicesPage />} />
                <Route path="teacher-attendance" element={<TeacherAttendanceReportPage />} />
                <Route path="publish-results" element={<PublishResultsPage />} />
                <Route path="promote-students" element={<PromoteStudentsPage />} />
              </Route>
              <Route element={<RequireRole roles={USERS_ROLES_VIEWERS} />}>
                <Route path="users-roles/users" element={<UsersPage />} />
                <Route path="users-roles/roles" element={<RolesPage />} />
                <Route path="users-roles/roles/:id/permissions" element={<RolePermissionsPage />} />
                <Route path="users-roles/permissions" element={<PermissionsPage />} />
              </Route>
              <Route path="teacher-leave-applications" element={<RequireRole roles={['school_admin']} />}>
                <Route index element={<TeacherLeaveApplicationsPage />} />
              </Route>
              <Route path="question-papers" element={<RequireRole roles={['school_admin']} />}>
                <Route index element={<QuestionPapersPage />} />
              </Route>
              {/*
                national_admin technically holds teacher_leave_applications.update
                too (its all-permissions wildcard — see
                TeacherLeaveApplicationPolicy::review()'s own comment), but this
                review page is deliberately school_admin-only per the product
                decision that national_admin gets a read-only view instead
                (TeachersOnLeavePage, below).
              */}
              <Route path="teachers-on-leave" element={<RequireRole roles={['national_admin']} />}>
                <Route index element={<TeachersOnLeavePage />} />
              </Route>
              <Route path="question-bank" element={<RequireRole roles={['national_admin']} />}>
                <Route index element={<AcademicQuestionsPage />} />
              </Route>
              <Route path="assessments" element={<RequireRole roles={ASSESSMENT_VIEWERS} />}>
                <Route path="exams" element={<ExamsPage />} />
                <Route path="questions" element={<ExamQuestionsPage />} />
              </Route>
              <Route path="system-setup" element={<RequireRole roles={SYSTEM_SETUP_VIEWERS} />}>
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
