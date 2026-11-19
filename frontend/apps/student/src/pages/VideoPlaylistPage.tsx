import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  listChapterVideos,
  listClasses,
  type ChapterVideo,
  type ClassVersion,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import {
  chaptersWithVideosForClassAndSubject,
  classVersionsForClass,
  subjectsForClass,
  type ChapterWithVideoCount,
} from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

// Same pattern as BooksPage.tsx/VideoLecturePage.tsx — see the comment
// there for why a translated `fallback` is used instead of the raw
// `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

function formatDuration(seconds: number | null): string {
  if (seconds == null) return ''
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`
}

function chapterTitle(entry: ChapterWithVideoCount): string {
  const number = entry.chapter.bn_chapter_number ?? entry.chapter.chapter_number ?? ''
  const name = entry.chapter.bn_name ?? entry.chapter.name
  return `${number} ${name}`.trim()
}

export default function VideoPlaylistPage() {
  const { t } = useLocale()
  const navigate = useNavigate()
  const { classId: classIdParam, subjectId: subjectIdParam, chapterId: chapterIdParam } = useParams<{
    classId: string
    subjectId: string
    chapterId?: string
  }>()
  const classId = Number(classIdParam)
  const subjectId = Number(subjectIdParam)
  const chapterId = chapterIdParam ? Number(chapterIdParam) : null

  // Re-derived from classId/subjectId (route params) rather than carried
  // via navigation state, so a bookmarked/shared link to this exact
  // chapter renders the same breadcrumb + sidebar without requiring the
  // visitor to have come from VideoLecturePage first.
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [versions, setVersions] = useState<ClassVersion[]>([])

  const [chapters, setChapters] = useState<ChapterWithVideoCount[]>([])
  const [chaptersLoading, setChaptersLoading] = useState(true)
  const [chaptersError, setChaptersError] = useState<string | null>(null)

  const [videos, setVideos] = useState<ChapterVideo[]>([])
  const [selectedVideoId, setSelectedVideoId] = useState<number | null>(null)
  const [videosLoading, setVideosLoading] = useState(false)
  const [videosError, setVideosError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  useEffect(() => {
    let active = true

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch(() => {
        if (active) setSubjects([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    let active = true

    classVersionsForClass(classId)
      .then((result) => {
        if (active) setVersions(result)
      })
      .catch(() => {
        if (active) setVersions([])
      })

    return () => {
      active = false
    }
  }, [classId])

  useEffect(() => {
    let active = true
    setChaptersLoading(true)
    setChaptersError(null)

    chaptersWithVideosForClassAndSubject(classId, subjectId)
      .then((result) => {
        if (active) setChapters(result)
      })
      .catch((caught) => {
        if (active) {
          setChapters([])
          setChaptersError(messageFromApiError(caught, t['student.videoLecture.loadError']))
        }
      })
      .finally(() => {
        if (active) setChaptersLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, subjectId, t])

  // No chapter in the URL (arrived straight from VideoLecturePage's Start
  // button) — default to the first chapter once the sidebar list loads,
  // and fold it into the URL so this exact chapter becomes bookmarkable.
  useEffect(() => {
    if (chapterId !== null || chaptersLoading || chapters.length === 0) return
    navigate(`/video-lecture/${classId}/${subjectId}/${chapters[0].chapter.id}`, { replace: true })
  }, [chapterId, chaptersLoading, chapters, classId, subjectId, navigate])

  useEffect(() => {
    setSelectedVideoId(null)

    if (!chapterId) {
      setVideos([])
      return
    }

    let active = true
    setVideosLoading(true)
    setVideosError(null)

    // ChapterVideoController::index() already orders by order_index, so no
    // client-side re-sort is needed here.
    listChapterVideos({ chapterId })
      .then((result) => {
        if (!active) return
        setVideos(result.data)
        setSelectedVideoId(result.data[0]?.id ?? null)
      })
      .catch((caught) => {
        if (active) {
          setVideos([])
          setVideosError(messageFromApiError(caught, t['student.videoLecture.loadError']))
        }
      })
      .finally(() => {
        if (active) setVideosLoading(false)
      })

    return () => {
      active = false
    }
  }, [chapterId, t])

  const selectedVideo = videos.find((video) => video.id === selectedVideoId) ?? null
  const selectedClass = classes.find((schoolClass) => schoolClass.id === classId)
  const selectedSubject = subjects.find((subject) => subject.id === subjectId)
  const className = selectedClass ? (selectedClass.bn_name ?? selectedClass.name) : ''
  const subjectName = selectedSubject ? (selectedSubject.bn_name ?? selectedSubject.name) : ''

  const versionsById = new Map(versions.map((version) => [version.id, version]))
  const selectedChapterEntry = chapters.find((entry) => entry.chapter.id === chapterId)
  const selectedVersion = selectedChapterEntry ? versionsById.get(selectedChapterEntry.book.class_version_id) : undefined
  const versionName = selectedVersion ? (selectedVersion.bn_name ?? selectedVersion.name) : ''

  const breadcrumb = [className, subjectName, versionName].filter(Boolean).join(' : ')

  return (
    <section className="mx-auto max-w-7xl px-4 py-8 lg:px-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        {breadcrumb && (
          <span className="rounded-full bg-primary-50 px-3 py-1 text-sm font-medium text-primary-700">{breadcrumb}</span>
        )}
        <Link to="/video-lecture" className="text-sm font-semibold text-primary-700 underline">
          {t['student.videoLecture.changeSelection']}
        </Link>
      </div>

      {chaptersLoading && <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.loading']}</p>}

      {!chaptersLoading && chaptersError && <p className="mt-6 text-sm text-danger-600">{chaptersError}</p>}

      {!chaptersLoading && !chaptersError && chapters.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.noChapters']}</p>
      )}

      {!chaptersLoading && !chaptersError && chapters.length > 0 && (
        <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-[14rem_1fr_16rem]">
          <nav
            className="flex h-[32rem] flex-col gap-1 overflow-y-auto rounded-lg border border-primary-100 p-2"
            aria-label={t['student.videoLecture.chaptersHeading']}
          >
            <p className="sticky top-0 bg-white px-2 py-1 text-xs font-semibold tracking-wide text-primary-500 uppercase">
              {t['student.videoLecture.chaptersHeading']}
            </p>
            {chapters.map((entry) => (
              <button
                key={entry.chapter.id}
                type="button"
                onClick={() => navigate(`/video-lecture/${classId}/${subjectId}/${entry.chapter.id}`)}
                className={`cursor-pointer rounded-md px-3 py-2 text-left text-sm transition ${
                  entry.chapter.id === chapterId
                    ? 'bg-primary-500 font-semibold text-white'
                    : 'text-primary-800 hover:bg-primary-50'
                }`}
              >
                {chapterTitle(entry)}
              </button>
            ))}
          </nav>

          <div>
            {videosLoading && <p className="text-sm text-primary-600">{t['student.videoLecture.loading']}</p>}
            {!videosLoading && videosError && <p className="text-sm text-danger-600">{videosError}</p>}
            {!videosLoading && !videosError && videos.length === 0 && (
              <p className="text-sm text-primary-600">{t['student.videoLecture.empty']}</p>
            )}

            {selectedVideo && (
              <>
                <div className="aspect-video w-full overflow-hidden rounded-lg bg-black">
                  <iframe
                    key={selectedVideo.id}
                    className="h-full w-full"
                    src={selectedVideo.embed_url}
                    title={selectedVideo.bn_title ?? selectedVideo.title}
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                  />
                </div>

                <div className="mt-4">
                  <h1 className="text-xl font-bold text-primary-800">{selectedVideo.bn_title ?? selectedVideo.title}</h1>
                  {selectedVideo.instructor_name && (
                    <p className="mt-1 text-sm text-primary-600">
                      {t['student.videoLecture.instructor']}: {selectedVideo.instructor_name}
                    </p>
                  )}
                  {selectedVideo.description && (
                    <p className="mt-3 text-sm text-primary-700">{selectedVideo.description}</p>
                  )}
                </div>
              </>
            )}
          </div>

          <div className="flex flex-col gap-4">
            <ol className="flex h-[32rem] flex-col gap-2 overflow-y-auto rounded-lg border border-primary-100 p-2">
              {videos.map((video) => (
                <li key={video.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedVideoId(video.id)}
                    className={`w-full cursor-pointer rounded-md border px-4 py-3 text-left text-sm transition ${
                      video.id === selectedVideoId
                        ? 'border-primary-400 bg-primary-50 text-primary-800'
                        : 'border-primary-100 text-primary-800 hover:border-primary-300'
                    }`}
                  >
                    <p className="font-semibold">{video.bn_title ?? video.title}</p>
                    <p className="mt-1 text-xs text-primary-500">
                      {formatDuration(video.duration_seconds)}
                      {video.instructor_name ? ` · ${video.instructor_name}` : ''}
                    </p>
                  </button>
                </li>
              ))}
            </ol>

            {chapterId !== null && (
              <button
                type="button"
                onClick={() => navigate('/exams', { state: { classId, subjectId, chapterId } })}
                className="cursor-pointer rounded-md border border-primary-300 px-4 py-2 text-sm font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white"
              >
                {t['student.videoLecture.startExam']}
              </button>
            )}
          </div>
        </div>
      )}
    </section>
  )
}
