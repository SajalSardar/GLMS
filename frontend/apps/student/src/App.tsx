import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import SiteShell from './components/SiteShell'
import LandingLogin from './pages/LandingLogin'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import VideosPage from './pages/VideosPage'
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
            <Route path="books" element={<BooksPage />} />
            <Route path="books/:id" element={<BookDetailPage />} />
            <Route path="videos" element={<VideosPage />} />
            <Route path="videos/:chapterId" element={<VideoPlaylistPage />} />
            <Route path="exams" element={<ExamsPage />} />
            <Route path="exams/:id" element={<ExamRunnerPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
