import { useEffect, useState } from 'react'
import { ApiError, getMe, updateMe, updateMyPassword, type Me } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../../lib/locale'

const inputClass =
  'h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500'
const buttonPrimary =
  'cursor-pointer self-start rounded-md bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#EB1F27] disabled:cursor-not-allowed disabled:opacity-60'

function AccountInfoForm({
  me,
  onUpdated,
  t,
}: {
  me: Me
  onUpdated: (me: Me) => void
  t: ReturnType<typeof useLocale>['t']
}) {
  const { token, setName } = useAuth()
  const [name, setNameField] = useState(me.name)
  const [bnName, setBnName] = useState(me.bn_name ?? '')
  const [email, setEmail] = useState(me.email ?? '')
  const [phone, setPhone] = useState(me.phone ?? '')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    if (!token) return

    setSubmitting(true)
    setErrors({})
    setFormError(null)
    setSuccess(false)

    try {
      const result = await updateMe({ name, bn_name: bnName || null, email: email || null, phone: phone || null }, token)
      onUpdated(result.data)
      setName(result.data.name)
      setSuccess(true)
    } catch (caught) {
      if (caught instanceof ApiError && caught.errors) {
        const mapped: Record<string, string> = {}
        for (const [key, messages] of Object.entries(caught.errors)) {
          mapped[key] = messages[0]
        }
        setErrors(mapped)
      } else {
        setFormError(caught instanceof Error ? caught.message : String(caught))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-md bg-white p-5 shadow-sm md:p-6">
      <h3 className="mb-4 text-base font-semibold text-gray-800">{t['student.profile.accountInfo']}</h3>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.name']}</span>
          <input className={inputClass} value={name} onChange={(event) => setNameField(event.target.value)} />
          {errors.name && <span className="text-xs text-danger-500">{errors.name}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.bnName']}</span>
          <input className={inputClass} value={bnName} onChange={(event) => setBnName(event.target.value)} />
          {errors.bn_name && <span className="text-xs text-danger-500">{errors.bn_name}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.email']}</span>
          <input className={inputClass} type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
          {errors.email && <span className="text-xs text-danger-500">{errors.email}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.phone']}</span>
          <input className={inputClass} value={phone} onChange={(event) => setPhone(event.target.value)} />
          {errors.phone && <span className="text-xs text-danger-500">{errors.phone}</span>}
        </label>
      </div>

      {formError && <p className="mt-4 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
      {success && <p className="mt-4 rounded-md bg-success-50 px-4 py-3 text-sm text-success-700">{t['student.profile.updateSuccess']}</p>}

      <div className="mt-5">
        <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
          {submitting ? t['student.profile.saving'] : t['student.profile.save']}
        </button>
      </div>
    </div>
  )
}

function ChangePasswordForm({ t }: { t: ReturnType<typeof useLocale>['t'] }) {
  const { token } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    if (!token) return

    setSubmitting(true)
    setErrors({})
    setFormError(null)
    setSuccess(false)

    try {
      await updateMyPassword(
        { current_password: currentPassword, password, password_confirmation: passwordConfirmation },
        token,
      )
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      setSuccess(true)
    } catch (caught) {
      if (caught instanceof ApiError && caught.errors) {
        const mapped: Record<string, string> = {}
        for (const [key, messages] of Object.entries(caught.errors)) {
          mapped[key] = messages[0]
        }
        setErrors(mapped)
      } else {
        setFormError(caught instanceof Error ? caught.message : String(caught))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-md bg-white p-5 shadow-sm md:p-6">
      <h3 className="mb-4 text-base font-semibold text-gray-800">{t['student.profile.changePassword']}</h3>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.currentPassword']}</span>
          <input
            className={inputClass}
            type="password"
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
          />
          {errors.current_password && <span className="text-xs text-danger-500">{errors.current_password}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.newPassword']}</span>
          <input className={inputClass} type="password" value={password} onChange={(event) => setPassword(event.target.value)} />
          {errors.password && <span className="text-xs text-danger-500">{errors.password}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['student.profile.confirmPassword']}</span>
          <input
            className={inputClass}
            type="password"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
          />
        </label>
      </div>

      {formError && <p className="mt-4 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
      {success && (
        <p className="mt-4 rounded-md bg-success-50 px-4 py-3 text-sm text-success-700">
          {t['student.profile.passwordUpdateSuccess']}
        </p>
      )}

      <div className="mt-5">
        <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
          {submitting ? t['student.profile.saving'] : t['student.profile.save']}
        </button>
      </div>
    </div>
  )
}

export default function MyProfilePage() {
  const { token } = useAuth()
  const { t } = useLocale()
  const [me, setMe] = useState<Me | null>(null)

  useEffect(() => {
    if (!token) return
    let cancelled = false

    getMe(token).then((result) => {
      if (!cancelled) setMe(result.data)
    })

    return () => {
      cancelled = true
    }
  }, [token])

  return (
    <section className="space-y-5">
      <div>
        <h2 className="text-xl font-semibold text-gray-800">{t['student.profile.title']}</h2>
        <p className="mt-1 text-sm text-gray-500">{t['student.profile.subtitle']}</p>
      </div>

      {me && (
        <>
          <AccountInfoForm me={me} onUpdated={setMe} t={t} />
          <ChangePasswordForm t={t} />
        </>
      )}
    </section>
  )
}
