import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from '@ttla/auth'
import { LocaleProvider } from './lib/locale'
import { GuardianChildProvider } from './lib/guardianChild'
import SiteShell from './components/SiteShell'
import StudentAppShell from './components/StudentAppShell'
import LandingLogin from './pages/LandingLogin'
import LoginPage from './pages/LoginPage'
import SelectChildPage from './pages/SelectChildPage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import VideoLecturePage from './pages/VideoLecturePage'
import VideoPlaylistPage from './pages/VideoPlaylistPage'
import ExamsPage from './pages/ExamsPage'
import ExamRunnerPage from './pages/ExamRunnerPage'
import CertificatePage from './pages/CertificatePage'
import NotFoundPage from './pages/NotFoundPage'
import DashboardHomePage from './pages/dashboard/DashboardHomePage'
import DashboardClassMaterialsPage from './pages/dashboard/ClassMaterialsPage'
import DashboardClassExamsPage from './pages/dashboard/ClassExamsPage'
import DashboardClassRoutinePage from './pages/dashboard/ClassRoutinePage'
import DashboardLeaveApplicationPage from './pages/dashboard/LeaveApplicationPage'
import DashboardHolidayCalendarPage from './pages/dashboard/HolidayCalendarPage'
import DashboardAttendancePage from './pages/dashboard/AttendancePage'
import DashboardBooksPage from './pages/dashboard/BooksPage'
import DashboardVideoLecturePage from './pages/dashboard/VideoLecturePage'
import DashboardExamsPage from './pages/dashboard/ExamsPage'
import DashboardResultsPage from './pages/dashboard/ResultsPage'
import DashboardMyProfilePage from './pages/dashboard/MyProfilePage'

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

// books/video-lecture/exams are the three routes shared between the public
// website and the dashboard (guest browsing is intentionally supported on
// all three). The website and dashboard designs are not meant to match
// (see pages/dashboard/*.tsx's own comments), so each route picks between
// two entirely separate page components by auth state rather than a single
// component branching on style internally.
function BooksRoute() {
  const { token } = useAuth()
  return token ? <DashboardBooksPage /> : <BooksPage />
}

function VideoLectureRoute() {
  const { token } = useAuth()
  return token ? <DashboardVideoLecturePage /> : <VideoLecturePage />
}

function ExamsRoute() {
  const { token } = useAuth()
  return token ? <DashboardExamsPage /> : <ExamsPage />
}

function App() {
  return (
    <LocaleProvider>
      <AuthProvider>
        <GuardianChildProvider>
          <BrowserRouter>
            <Routes>
              <Route element={<SiteShell />}>
                <Route index element={<LandingLogin />} />
                <Route path="login" element={<LoginPage />} />
                <Route path="*" element={<NotFoundPage />} />
              </Route>
              <Route element={<StudentAreaShell />}>
                <Route element={<RequireAuth />}>
                  <Route path="select-child" element={<SelectChildPage />} />
                  <Route path="dashboard" element={<DashboardHomePage />} />
                  <Route path="class-materials" element={<DashboardClassMaterialsPage />} />
                  <Route path="class-routine" element={<DashboardClassRoutinePage />} />
                  <Route path="class-exams" element={<DashboardClassExamsPage />} />
                  <Route path="leave-application" element={<DashboardLeaveApplicationPage />} />
                  <Route path="holidays" element={<DashboardHolidayCalendarPage />} />
                  <Route path="attendance" element={<DashboardAttendancePage />} />
                  <Route path="results" element={<DashboardResultsPage />} />
                  <Route path="profile" element={<DashboardMyProfilePage />} />
                </Route>
                <Route path="books" element={<BooksRoute />} />
                <Route path="books/:id" element={<BookDetailPage />} />
                <Route path="video-lecture" element={<VideoLectureRoute />} />
                <Route path="video-lecture/:classId/:subjectId/:chapterId?" element={<VideoPlaylistPage />} />
                <Route path="exams" element={<ExamsRoute />} />
                <Route path="exams/:id" element={<ExamRunnerPage />} />
                <Route path="exams/:id/certificate" element={<CertificatePage />} />
              </Route>
            </Routes>
          </BrowserRouter>
        </GuardianChildProvider>
      </AuthProvider>
    </LocaleProvider>
  )
}

export default App
