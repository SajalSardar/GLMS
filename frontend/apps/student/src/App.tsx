import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@ttla/auth'
import SiteShell from './components/SiteShell'
import LandingLogin from './pages/LandingLogin'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import VideosPage from './pages/VideosPage'
import VideoPlaylistPage from './pages/VideoPlaylistPage'

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
            {/* Exams routes are added by Task 6 */}
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
