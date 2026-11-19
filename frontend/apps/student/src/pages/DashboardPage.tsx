import { Link } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../lib/locale'

interface QuickLink {
  to: string
  title: string
  desc: string
}

export default function DashboardPage() {
  const { t } = useLocale()
  const { name } = useAuth()

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
