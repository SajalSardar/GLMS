import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AppShell } from '@ttla/ui'
import { AuthProvider, useAuth } from '@ttla/auth'
import DashboardPlaceholder from './pages/DashboardPlaceholder'
import LandingLogin from './pages/LandingLogin'

const navItems = [{ to: '/dashboard', label: 'Dashboard' }]

function RequireAuth() {
  const { token } = useAuth()

  return token ? <Outlet /> : <Navigate to="/" replace />
}

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<LandingLogin />} />
          <Route element={<RequireAuth />}>
            <Route path="/dashboard" element={<AppShell title="Student Portal" navItems={navItems} />}>
              <Route index element={<DashboardPlaceholder />} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
