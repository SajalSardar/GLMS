import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listMyChapterMaterials, type ChapterMaterial } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../lib/locale'

interface QuickLink {
  to: string
  title: string
  desc: string
}

function MaterialsNotice({ count }: { count: number }) {
  const { t } = useLocale()

  return (
    <Link
      to="/class-materials"
      className="mt-6 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-700 hover:border-primary-400"
    >
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <path d="M14 2v6h6" />
      </svg>
      <p>{t['student.dashboard.newMaterials'].replace('{count}', String(count))}</p>
    </Link>
  )
}

export default function DashboardPage() {
  const { t } = useLocale()
  const { name, token } = useAuth()

  const [myMaterials, setMyMaterials] = useState<ChapterMaterial[]>([])

  useEffect(() => {
    if (!token) return

    let active = true

    // Scoped entirely server-side to this student's own school/district/
    // shift/section/class/class_version tuple (ChapterMaterialController::
    // mine()) — an empty result here just means nothing new for their
    // exact class, not a loading/error state worth showing its own UI for.
    listMyChapterMaterials(token)
      .then((result) => {
        if (active) setMyMaterials(result.data)
      })
      .catch(() => {
        if (active) setMyMaterials([])
      })

    return () => {
      active = false
    }
  }, [token])

  const quickLinks: QuickLink[] = [
    { to: '/books', title: t['student.dashboard.booksTitle'], desc: t['student.dashboard.booksDesc'] },
    {
      to: '/video-lecture',
      title: t['student.dashboard.videoLectureTitle'],
      desc: t['student.dashboard.videoLectureDesc'],
    },
    { to: '/exams', title: t['student.dashboard.examsTitle'], desc: t['student.dashboard.examsDesc'] },
  ]

  const welcome = name ? t['student.dashboard.welcome'].replace('{name}', name) : t['student.dashboard.welcomeGeneric']

  return (
    <section className="mx-auto max-w-5xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.dashboard.title']}</h1>
      <p className="mt-1 text-base font-medium text-primary-700">{welcome}</p>
      <p className="mt-1 text-sm text-primary-600">{t['student.dashboard.subtitle']}</p>

      {myMaterials.length > 0 && <MaterialsNotice count={myMaterials.length} />}

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {quickLinks.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="rounded-lg border border-primary-100 p-5 shadow-sm transition hover:border-primary-300 hover:shadow"
          >
            <p className="font-semibold text-primary-800">{link.title}</p>
            <p className="mt-1 text-sm text-primary-600">{link.desc}</p>
          </Link>
        ))}
      </div>
    </section>
  )
}
