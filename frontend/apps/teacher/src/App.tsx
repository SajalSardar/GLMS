import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@ttla/auth'
import TeacherAppShell from './components/TeacherAppShell'
import TeacherLogin from './pages/TeacherLogin'
import DashboardHomePage from './pages/DashboardHomePage'
import LeaveApplicationsPage from './pages/LeaveApplicationsPage'
import HolidaysPage from './pages/HolidaysPage'
import AttendancePage from './pages/AttendancePage'

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
              <Route path="attendance" element={<AttendancePage />} />
              <Route path="leave-applications" element={<LeaveApplicationsPage />} />
              <Route path="holidays" element={<HolidaysPage />} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
