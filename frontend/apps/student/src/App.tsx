import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@ttla/auth'
import { LocaleProvider } from './lib/locale'
import SiteShell from './components/SiteShell'
import StudentAppShell from './components/StudentAppShell'
import LandingLogin from './pages/LandingLogin'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import VideoLecturePage from './pages/VideoLecturePage'
import VideoPlaylistPage from './pages/VideoPlaylistPage'
import ExamsPage from './pages/ExamsPage'
import ExamRunnerPage from './pages/ExamRunnerPage'
import CertificatePage from './pages/CertificatePage'
import NotFoundPage from './pages/NotFoundPage'

// Logged-in students get the same sidebar+topbar AppShell the management
// portal uses; guests browsing these same routes (books/video-lecture/
// exams all support guest access) keep the public top-nav SiteShell.
function StudentAreaShell() {
  const { token } = useAuth()
  return token ? <StudentAppShell /> : <SiteShell />
}

// Unlike books/video-lecture/exams (which deliberately support guest
// browsing), the dashboard landing page is student-only — a guest hitting
// /dashboard directly is sent to the login form instead.
function RequireAuth() {
  const { token } = useAuth()
  return token ? <Outlet /> : <Navigate to="/login" replace />
}

function App() {
  return (
    <LocaleProvider>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route element={<SiteShell />}>
              <Route index element={<LandingLogin />} />
              <Route path="login" element={<LoginPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
            <Route element={<StudentAreaShell />}>
              <Route element={<RequireAuth />}>
                <Route path="dashboard" element={<DashboardPage />} />
              </Route>
              <Route path="books" element={<BooksPage />} />
              <Route path="books/:id" element={<BookDetailPage />} />
              <Route path="video-lecture" element={<VideoLecturePage />} />
              <Route path="video-lecture/:classId/:subjectId/:chapterId?" element={<VideoPlaylistPage />} />
              <Route path="exams" element={<ExamsPage />} />
              <Route path="exams/:id" element={<ExamRunnerPage />} />
              <Route path="exams/:id/certificate" element={<CertificatePage />} />
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </LocaleProvider>
  )
}

export default App
