import { useId, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { managementLogin } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import './ManagementLogin.css'

const t = getCommonStrings('bn')
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

function LoginForm() {
  const { setToken, setRole, setSchoolId } = useAuth()
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
      const result = await managementLogin(login.trim(), password, 'web')
      setToken(result.token)
      setRole(result.data.role?.name ?? null)
      setSchoolId(result.data.school?.id ?? null)
      navigate('/dashboard', { replace: true })
    } catch {
      setError(t['management.errorGeneric'])
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form className="mgmt-card__form" onSubmit={handleSubmit} noValidate>
      <label className="mgmt-card__label" htmlFor={loginFieldId}>
        {t['management.loginLabel']}
      </label>
      <input
        id={loginFieldId}
        className="mgmt-card__input"
        type="text"
        autoComplete="username"
        placeholder={t['management.loginPlaceholder']}
        value={login}
        onChange={(event) => setLogin(event.target.value)}
        required
      />

      <label className="mgmt-card__label" htmlFor={passwordFieldId}>
        {t['management.password']}
      </label>
      <div className="mgmt-card__password-row">
        <input
          id={passwordFieldId}
          className="mgmt-card__input"
          type={showPassword ? 'text' : 'password'}
          autoComplete="current-password"
          placeholder={t['management.passwordPlaceholder']}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
        />
        <button
          type="button"
          className="mgmt-card__eye"
          aria-label={showPassword ? t['management.hidePassword'] : t['management.showPassword']}
          onClick={() => setShowPassword((show) => !show)}
        >
          <EyeIcon open={showPassword} />
        </button>
      </div>

      {error && (
        <p className="mgmt-card__error" role="alert">
          {error}
        </p>
      )}

      <button className="mgmt-card__submit" type="submit" disabled={submitting}>
        {submitting ? t['management.submitting'] : t['management.submit']}
      </button>
    </form>
  )
}

export default function ManagementLogin() {
  return (
    <div className="mgmt-page">
      <div className="mgmt-card">
        <img className="mgmt-card__seal" src="/logo.webp" alt="" aria-hidden="true" />
        <p className="mgmt-card__brand">{t['management.brandFull']}</p>
        <h1 className="mgmt-card__portal">{t['management.portalName']}</h1>
        <p className="mgmt-card__subtitle">{t['management.subtitle']}</p>
        <LoginForm />
        <p className="mgmt-card__help">{t['management.helpNote']}</p>
      </div>

      <p className="mgmt-page__footer">
        {t['management.footerNote']} © {currentYear} {t['management.brandFull']}
      </p>
    </div>
  )
}
