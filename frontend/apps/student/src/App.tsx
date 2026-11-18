import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import SiteShell from './components/SiteShell'
import LandingLogin from './pages/LandingLogin'

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route element={<SiteShell />}>
            <Route index element={<LandingLogin />} />
            {/* Books/Videos/Exams routes are added by Tasks 4-6 */}
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
