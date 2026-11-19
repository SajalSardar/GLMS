import { Link, useLocation, useParams } from 'react-router-dom'
import { useAuth } from '@ttla/auth'
import type { CommonStrings } from '@ttla/i18n'
import { useLocale } from '../lib/locale'

interface CertificateState {
  score: number
  total: number
  percent: number
  tier: 'pass' | 'good' | 'best'
  date: string
}

const TIER_HEADING_KEY: Record<CertificateState['tier'], keyof CommonStrings> = {
  pass: 'student.exams.tierPass',
  good: 'student.exams.tierGood',
  best: 'student.exams.tierBest',
}

function isCertificateState(value: unknown): value is CertificateState {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Partial<CertificateState>
  return (
    typeof candidate.score === 'number' &&
    typeof candidate.total === 'number' &&
    typeof candidate.percent === 'number' &&
    typeof candidate.tier === 'string' &&
    typeof candidate.date === 'string'
  )
}

export default function CertificatePage() {
  const { id } = useParams<{ id: string }>()
  const location = useLocation()
  const { token, name } = useAuth()
  const { t } = useLocale()

  const state = isCertificateState(location.state) ? location.state : null

  if (!state) {
    return (
      <section className="mx-auto max-w-xl px-4 py-12 text-center">
        <p className="text-sm text-primary-600">{t['student.certificate.missing']}</p>
        <Link to="/exams" className="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
          {t['student.certificate.backToExams']}
        </Link>
      </section>
    )
  }

  // `name` is null for a token stored before AuthProvider persisted it,
  // so the generic label stays as the fallback for signed-in viewers.
  const studentName = token
    ? (name ?? t['student.certificate.studentName'])
    : t['student.certificate.guestName']
  const formattedDate = new Date(state.date).toLocaleDateString('bn-BD', { year: 'numeric', month: 'long', day: 'numeric' })

  return (
    <section className="mx-auto max-w-2xl px-4 py-12">
      <div className="rounded-2xl border-4 border-double border-primary-500 bg-white p-10 text-center shadow-lg print:border-2 print:shadow-none">
        <img src="/logo.webp" alt="" aria-hidden="true" className="mx-auto h-14 w-14 object-contain" />
        <h1 className="mt-4 text-2xl font-bold font-display text-primary-800">{t['student.certificate.title']}</h1>

        <p className="mt-6 text-lg font-semibold text-primary-800">{studentName}</p>

        <p className="mt-2 text-base text-primary-700">
          {t['student.certificate.achieved']} <strong>{t[TIER_HEADING_KEY[state.tier]]}</strong>
        </p>

        <p className="mt-2 text-sm text-primary-600">
          {t['student.certificate.scoreLine'].replace('{score}', String(state.score)).replace('{total}', String(state.total))}
        </p>

        <p className="mt-6 text-xs text-primary-500">{formattedDate}</p>
        <p className="mt-1 text-xs text-primary-400">#{id}</p>
      </div>

      <div className="mt-6 flex justify-center gap-4 print:hidden">
        <button
          type="button"
          onClick={() => window.print()}
          className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-[#EB1F27]"
        >
          {t['student.certificate.print']}
        </button>
        <Link to="/exams" className="text-sm font-semibold text-primary-700 underline">
          {t['student.certificate.backToExams']}
        </Link>
      </div>
    </section>
  )
}
