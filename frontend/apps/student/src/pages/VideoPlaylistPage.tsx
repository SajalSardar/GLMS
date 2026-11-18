import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError, listChapterVideos, type ChapterVideo } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('bn')

// Same pattern as BooksPage.tsx/VideosPage.tsx/ExamsPage.tsx — see the
// comment there for why a translated `fallback` is used instead of the
// raw `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? error.message ?? fallback
  }

  return fallback
}

function formatDuration(seconds: number | null): string {
  if (seconds == null) return ''
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`
}

export default function VideoPlaylistPage() {
  const { chapterId } = useParams<{ chapterId: string }>()
  const id = Number(chapterId)

  const [videos, setVideos] = useState<ChapterVideo[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) {
      setLoading(false)
      setVideos([])
      return
    }

    let active = true
    setLoading(true)
    setError(null)

    // ChapterVideoController::index() already orders by order_index, so no
    // client-side re-sort is needed here.
    listChapterVideos({ chapterId: id })
      .then((result) => {
        if (!active) return
        setVideos(result.data)
        setSelectedId(result.data[0]?.id ?? null)
      })
      .catch((caught) => {
        if (active) {
          setVideos([])
          setError(messageFromApiError(caught, t['student.videos.loadError']))
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [id])

  const selectedVideo = videos.find((video) => video.id === selectedId) ?? null

  if (loading) {
    return (
      <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
        <p className="text-sm text-primary-600">{t['student.videos.loading']}</p>
      </section>
    )
  }

  if (error) {
    return (
      <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
        <p className="text-sm text-danger-600">{error}</p>
        <Link to="/videos" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
          {t['student.videos.title']}
        </Link>
      </section>
    )
  }

  if (videos.length === 0) {
    return (
      <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
        <p className="text-sm text-primary-600">{t['student.videos.empty']}</p>
        <Link to="/videos" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
          {t['student.videos.title']}
        </Link>
      </section>
    )
  }

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <Link to="/videos" className="text-sm font-semibold text-primary-700 underline">
        {t['student.videos.title']}
      </Link>

      <div className="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          {selectedVideo && (
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
          )}

          {selectedVideo && (
            <div className="mt-4">
              <h1 className="text-xl font-bold text-primary-800">{selectedVideo.bn_title ?? selectedVideo.title}</h1>
              {selectedVideo.instructor_name && (
                <p className="mt-1 text-sm text-primary-600">
                  {t['student.videos.instructor']}: {selectedVideo.instructor_name}
                </p>
              )}
              {selectedVideo.description && (
                <p className="mt-3 text-sm text-primary-700">{selectedVideo.description}</p>
              )}
            </div>
          )}
        </div>

        <ol className="flex flex-col gap-2">
          {videos.map((video) => (
            <li key={video.id}>
              <button
                type="button"
                onClick={() => setSelectedId(video.id)}
                className={`w-full cursor-pointer rounded-md border px-4 py-3 text-left text-sm transition ${
                  video.id === selectedId
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
      </div>
    </section>
  )
}
