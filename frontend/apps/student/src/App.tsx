import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import SiteShell from './components/SiteShell'
import LandingLogin from './pages/LandingLogin'
import LoginPage from './pages/LoginPage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import VideoLecturePage from './pages/VideoLecturePage'
import VideoPlaylistPage from './pages/VideoPlaylistPage'
import ExamsPage from './pages/ExamsPage'
import ExamRunnerPage from './pages/ExamRunnerPage'

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route element={<SiteShell />}>
            <Route index element={<LandingLogin />} />
            <Route path="login" element={<LoginPage />} />
            <Route path="books" element={<BooksPage />} />
            <Route path="books/:id" element={<BookDetailPage />} />
            <Route path="video-lecture" element={<VideoLecturePage />} />
            <Route path="video-lecture/:chapterId" element={<VideoPlaylistPage />} />
            <Route path="exams" element={<ExamsPage />} />
            <Route path="exams/:id" element={<ExamRunnerPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
