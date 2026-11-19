import { Link } from 'react-router-dom'
import { useLocale } from '../lib/locale'

export default function NotFoundPage() {
  const { t } = useLocale()

  return (
    <section className="mx-auto max-w-xl px-4 py-16 text-center">
      <p className="text-5xl font-bold text-primary-300">404</p>
      <h1 className="mt-4 text-2xl font-bold text-primary-800">{t['student.notFound.title']}</h1>
      <p className="mt-2 text-sm text-primary-600">{t['student.notFound.body']}</p>
      <Link
        to="/"
        className="mt-6 inline-block cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-[#EB1F27]"
      >
        {t['student.notFound.backHome']}
      </Link>
    </section>
  )
}
