import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import { LocaleProvider } from './lib/locale'
import SiteShell from './components/SiteShell'
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

function App() {
  return (
    <LocaleProvider>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route element={<SiteShell />}>
              <Route index element={<LandingLogin />} />
              <Route path="login" element={<LoginPage />} />
              <Route path="dashboard" element={<DashboardPage />} />
              <Route path="books" element={<BooksPage />} />
              <Route path="books/:id" element={<BookDetailPage />} />
              <Route path="video-lecture" element={<VideoLecturePage />} />
              <Route path="video-lecture/:chapterId" element={<VideoPlaylistPage />} />
              <Route path="exams" element={<ExamsPage />} />
              <Route path="exams/:id" element={<ExamRunnerPage />} />
              <Route path="exams/:id/certificate" element={<CertificatePage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Route>
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </LocaleProvider>
  )
}

export default App
