import { useId, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { studentLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../lib/locale'

type IconName = 'eyeOpen' | 'eyeClosed'

const FOCUS_RING =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-accent-dark)]'

const LOGIN_CARD_TOP_BAR =
  "before:absolute before:inset-x-0 before:top-0 before:h-1 before:rounded-t-[1.1rem] before:bg-linear-to-r before:from-[color:var(--color-primary)] before:to-[color:var(--color-accent)] before:content-['']"

function Icon({ name }: { name: IconName }) {
  const common = {
    width: 24,
    height: 24,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  }

  switch (name) {
    case 'eyeOpen':
      return (
        <svg {...common}>
          <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12Z" />
          <circle cx="12" cy="12" r="2.6" />
        </svg>
      )
    case 'eyeClosed':
      return (
        <svg {...common}>
          <path d="M3 3l18 18" />
          <path d="M9.9 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 6.5 10 7a12.6 12.6 0 0 1-3.2 3.7M6.6 6.7A12.7 12.7 0 0 0 2 12s3.5 6.5 10 6.5c1.3 0 2.5-.2 3.6-.7" />
          <path d="M9.9 13.1a2.6 2.6 0 0 0 3.6 3.1" />
        </svg>
      )
  }
}

function LoginCard() {
  const { setToken, setName, setUserId } = useAuth()
  const { t } = useLocale()
  const navigate = useNavigate()
  const studentIdFieldId = useId()
  const passwordFieldId = useId()

  const [studentId, setStudentId] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [showContactNote, setShowContactNote] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await studentLogin(studentId.trim(), password, 'web')
      setToken(result.token)
      // Persisted so the certificate can print the student's real name
      // rather than a generic "Student" label.
      setName(result.data?.name ?? null)
      // Persisted so BooksPage can look up this student's own class via
      // /student-profiles?user_id=... without a dedicated "me" endpoint.
      setUserId(result.data?.id ?? null)
      navigate('/dashboard')
    } catch {
      setError(t['landing.login.errorGeneric'])
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form
      className={`relative mx-auto max-w-[26rem] rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 pt-7 pb-6 shadow-[0_18px_40px_-24px_rgba(15,107,76,0.35)] ${LOGIN_CARD_TOP_BAR}`}
      onSubmit={handleSubmit}
      noValidate
    >
      <img className="mx-auto h-16 w-16 object-contain" src="/logo.webp" alt="" aria-hidden="true" />
      <p className="m-0 mt-3 text-center text-[0.78rem] text-[color:var(--color-muted)]">{t['landing.login.brandFull']}</p>
      <h1 className="mt-[0.3rem] text-center text-[1.45rem] font-bold font-display text-[color:var(--color-primary-dark)]">
        {t['landing.login.portalName']}
      </h1>
      <p className="mt-[0.5rem] mb-5 text-center text-[0.9rem] text-[color:var(--color-muted)]">{t['landing.login.subtitle']}</p>

      <label className="mt-[0.9rem] mb-[0.4rem] block text-[0.85rem] font-medium" htmlFor={studentIdFieldId}>
        {t['landing.login.studentId']}
      </label>
      <input
        id={studentIdFieldId}
        className={`w-full rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-base text-[color:var(--color-ink)] focus:border-primary-500 ${FOCUS_RING}`}
        type="text"
        autoComplete="username"
        placeholder={t['landing.login.studentIdPlaceholder']}
        value={studentId}
        onChange={(event) => setStudentId(event.target.value)}
        required
      />

      <label className="mt-[0.9rem] mb-[0.4rem] block text-[0.85rem] font-medium" htmlFor={passwordFieldId}>
        {t['landing.login.password']}
      </label>
      <div className="flex items-center gap-[0.4rem]">
        <input
          id={passwordFieldId}
          className={`w-full flex-1 rounded-[0.6rem] border border-[color:var(--color-border)] bg-white px-[0.8rem] py-[0.65rem] text-base text-[color:var(--color-ink)] focus:border-primary-500 ${FOCUS_RING}`}
          type={showPassword ? 'text' : 'password'}
          autoComplete="current-password"
          placeholder={t['landing.login.passwordPlaceholder']}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
        />
        <button
          type="button"
          className={`flex h-10 w-10 flex-shrink-0 cursor-pointer items-center justify-center rounded-[0.6rem] border border-[color:var(--color-border)] text-[color:var(--color-muted)] ${FOCUS_RING}`}
          aria-label={showPassword ? t['landing.login.hidePassword'] : t['landing.login.showPassword']}
          onClick={() => setShowPassword((show) => !show)}
        >
          <Icon name={showPassword ? 'eyeClosed' : 'eyeOpen'} />
        </button>
      </div>

      {error && (
        <p className="mt-[0.9rem] rounded-lg bg-[#fbe9e7] px-3 py-[0.6rem] text-[0.85rem] text-[#9c2b22]" role="alert">
          {error}
        </p>
      )}

      <button
        className={`mt-5 block w-full cursor-pointer rounded-[0.6rem] bg-primary-500 p-3 text-center text-base font-semibold text-white transition-colors duration-150 hover:enabled:bg-[#EB1F27] disabled:cursor-default disabled:bg-[color:var(--color-muted)] ${FOCUS_RING}`}
        type="submit"
        disabled={submitting}
      >
        {submitting ? t['landing.login.submitting'] : t['landing.login.submit']}
      </button>

      <button
        type="button"
        className={`mx-auto mt-[0.9rem] block cursor-pointer border-0 bg-transparent p-0 text-center text-[0.82rem] font-semibold text-primary-700 ${FOCUS_RING}`}
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.forgot']}
      </button>

      <div className="my-4 flex items-center gap-3 text-[0.78rem] text-[color:var(--color-muted)] before:h-px before:flex-1 before:bg-[color:var(--color-border)] before:content-[''] after:h-px after:flex-1 after:bg-[color:var(--color-border)] after:content-['']">
        <span>{t['landing.login.or']}</span>
      </div>

      <button
        type="button"
        className={`block w-full cursor-pointer rounded-[0.6rem] border-[1.5px] border-primary-500 bg-transparent py-[0.7rem] text-[0.92rem] font-semibold text-primary-700 hover:bg-[#EB1F27] hover:text-white ${FOCUS_RING}`}
        onClick={() => setShowContactNote((show) => !show)}
      >
        {t['landing.login.needHelp']}
      </button>

      {showContactNote && (
        <p className="mt-[0.9rem] text-center text-[0.8rem] leading-[1.5] text-[color:var(--color-muted)]">
          {t['landing.login.contactNote']}
        </p>
      )}
    </form>
  )
}

export default function LoginPage() {
  const { token } = useAuth()
  const { t } = useLocale()

  return (
    <section className="mx-auto max-w-[30rem] px-5 py-12 lg:py-20">
      {token ? (
        <div
          className={`relative rounded-[1.1rem] border border-[color:var(--color-border)] bg-white px-6 py-10 text-center shadow-[0_18px_40px_-24px_rgba(15,107,76,0.35)] ${LOGIN_CARD_TOP_BAR}`}
        >
          <p className="m-0 text-[1.2rem] font-semibold font-display">{t['landing.login.alreadyTitle']}</p>
          <Link
            className="mt-4 block w-full rounded-[0.6rem] bg-primary-500 p-3 text-center text-base font-semibold text-white hover:bg-[#EB1F27]"
            to="/dashboard"
          >
            {t['landing.login.alreadyCta']}
          </Link>
        </div>
      ) : (
        <LoginCard />
      )}
    </section>
  )
}
