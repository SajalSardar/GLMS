import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AppShell } from '@ttla/ui'
import { AuthProvider } from '@ttla/auth'
import DashboardPlaceholder from './pages/DashboardPlaceholder'

const navItems = [{ to: '/', label: 'Dashboard' }]

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<AppShell title="Student Portal" navItems={navItems} />}>
            <Route index element={<DashboardPlaceholder />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
