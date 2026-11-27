import { useId, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError, teacherLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import './TeacherLogin.css'

const t = getCommonStrings('en')
const currentYear = new Date().getFullYear()

function EyeIcon({ open }: { open: boolean }) {
  const common = {
    width: 20,
    height: 20,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  }

  return open ? (
    <svg {...common}>
      <path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12Z" />
      <circle cx="12" cy="12" r="2.6" />
    </svg>
  ) : (
    <svg {...common}>
      <path d="M3 3l18 18" />
      <path d="M9.9 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 6.5 10 7a12.6 12.6 0 0 1-3.2 3.7M6.6 6.7A12.7 12.7 0 0 0 2 12s3.5 6.5 10 6.5c1.3 0 2.5-.2 3.6-.7" />
      <path d="M9.9 13.1a2.6 2.6 0 0 0 3.6 3.1" />
    </svg>
  )
}

export default function TeacherLogin() {
  const { setToken, setRole, setName, setUserId } = useAuth()
  const navigate = useNavigate()
  const loginFieldId = useId()
  const passwordFieldId = useId()

  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await teacherLogin(login.trim(), password, 'web')
      setToken(result.token)
      setRole(result.data.role?.name ?? null)
      setName(result.data.name)
      setUserId(result.data.id)
      navigate('/dashboard', { replace: true })
    } catch (caught) {
      setError(caught instanceof ApiError ? (caught.errors?.login?.[0] ?? t['teacher.login.errorGeneric']) : t['teacher.login.errorGeneric'])
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="tch-page">
      <div className="tch-card">
        <img className="tch-card__seal" src="/logo.webp" alt="" aria-hidden="true" />
        <p className="tch-card__brand">{t['teacher.login.brandFull']}</p>
        <h1 className="tch-card__portal">{t['teacher.login.portalName']}</h1>
        <p className="tch-card__subtitle">{t['teacher.login.subtitle']}</p>

        <form className="flex flex-col gap-4 text-left" onSubmit={handleSubmit} noValidate>
          <label className="flex flex-col gap-1.5 text-sm" htmlFor={loginFieldId}>
            <span className="font-medium text-gray-700">{t['teacher.login.loginLabel']}</span>
            <input
              id={loginFieldId}
              className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              type="text"
              autoComplete="username"
              placeholder={t['teacher.login.loginPlaceholder']}
              value={login}
              onChange={(event) => setLogin(event.target.value)}
              required
            />
          </label>

          <label className="flex flex-col gap-1.5 text-sm" htmlFor={passwordFieldId}>
            <span className="font-medium text-gray-700">{t['teacher.login.password']}</span>
            <div className="relative">
              <input
                id={passwordFieldId}
                className="h-11 w-full rounded-md border border-gray-200 bg-white px-3 pr-10 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder={t['teacher.login.passwordPlaceholder']}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
              <button
                type="button"
                className="absolute inset-y-0 right-0 flex cursor-pointer items-center px-3 text-gray-400 hover:text-gray-600"
                aria-label={showPassword ? t['teacher.login.hidePassword'] : t['teacher.login.showPassword']}
                onClick={() => setShowPassword((show) => !show)}
              >
                <EyeIcon open={showPassword} />
              </button>
            </div>
          </label>

          {error && (
            <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600" role="alert">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="mt-2 cursor-pointer rounded-md bg-primary-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? t['teacher.login.submitting'] : t['teacher.login.submit']}
          </button>
        </form>

        <p className="tch-card__help">{t['teacher.login.helpNote']}</p>
      </div>

      <p className="tch-page__footer">
        {t['teacher.login.footerNote']} © {currentYear} {t['teacher.login.brandFull']}
      </p>
    </div>
  )
}
