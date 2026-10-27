import { BrowserRouter, Route, Routes } from 'react-router-dom'
import AppShell from './components/layout/AppShell'
import DashboardPlaceholder from './pages/DashboardPlaceholder'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<AppShell />}>
          <Route index element={<DashboardPlaceholder />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}

export default App
