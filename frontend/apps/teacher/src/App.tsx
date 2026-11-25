import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@ttla/auth'
import TeacherAppShell from './components/TeacherAppShell'
import TeacherLogin from './pages/TeacherLogin'
import DashboardHomePage from './pages/DashboardHomePage'
import LeaveApplicationsPage from './pages/LeaveApplicationsPage'
import HolidaysPage from './pages/HolidaysPage'
import AttendancePage from './pages/AttendancePage'
import ClassRoutinePage from './pages/ClassRoutinePage'
import MarksEntryPage from './pages/MarksEntryPage'
import MarksheetsPage from './pages/MarksheetsPage'
import ClassMaterialsPage from './pages/ClassMaterialsPage'
import TeacherLeavePage from './pages/TeacherLeavePage'
import QuestionBuilderPage from './pages/QuestionBuilderPage'

function LoginRoute() {
  const { token } = useAuth()
  return token ? <Navigate to="/dashboard" replace /> : <TeacherLogin />
}

function RequireAuth() {
  const { token } = useAuth()
  return token ? <Outlet /> : <Navigate to="/" replace />
}

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<LoginRoute />} />
          <Route element={<RequireAuth />}>
            <Route element={<TeacherAppShell />}>
              <Route path="dashboard" element={<DashboardHomePage />} />
              {/* "attendance" itself redirects rather than rendering Take
                  Attendance directly — none of the three paths below may be
                  a literal prefix of another, or the shared Sidebar's child
                  links (which don't take `end`) would show more than one
                  submenu item active at once. */}
              <Route path="attendance" element={<Navigate to="/attendance/take" replace />} />
              <Route path="attendance/take" element={<AttendancePage tab="take" />} />
              <Route path="attendance/list" element={<AttendancePage tab="list" />} />
              <Route path="attendance/report" element={<AttendancePage tab="report" />} />
              <Route path="class-routine" element={<ClassRoutinePage />} />
              <Route path="student-leave" element={<LeaveApplicationsPage />} />
              <Route path="teacher-leave" element={<TeacherLeavePage />} />
              <Route path="question-builder" element={<QuestionBuilderPage />} />
              <Route path="holidays" element={<HolidaysPage />} />
              <Route path="marks-entry" element={<MarksEntryPage />} />
              <Route path="marksheets" element={<MarksheetsPage />} />
              <Route path="class-materials" element={<ClassMaterialsPage />} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
