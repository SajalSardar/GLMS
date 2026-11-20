import { Link } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

export default function DashboardHomePage() {
  const { name } = useAuth()

  const welcome = name ? t['teacher.dashboard.welcome'].replace('{name}', name) : t['teacher.dashboard.welcomeGeneric']

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.dashboard.title']}</h1>
      <p className="mt-1 text-base font-medium text-primary-700">{welcome}</p>
      <p className="mt-1 text-sm text-gray-500">{t['teacher.dashboard.subtitle']}</p>

      <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Link
          to="/leave-applications"
          className="rounded-lg border border-gray-100 bg-white p-5 shadow-sm transition hover:border-primary-300 hover:shadow"
        >
          <p className="font-semibold text-primary-700">{t['teacher.dashboard.leaveApplicationsTitle']}</p>
          <p className="mt-1 text-sm text-gray-500">{t['teacher.dashboard.leaveApplicationsDesc']}</p>
        </Link>

        <Link
          to="/holidays"
          className="rounded-lg border border-gray-100 bg-white p-5 shadow-sm transition hover:border-primary-300 hover:shadow"
        >
          <p className="font-semibold text-primary-700">{t['teacher.dashboard.holidaysTitle']}</p>
          <p className="mt-1 text-sm text-gray-500">{t['teacher.dashboard.holidaysDesc']}</p>
        </Link>
      </div>
    </section>
  )
}
