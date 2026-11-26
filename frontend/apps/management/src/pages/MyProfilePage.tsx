import { useEffect, useState } from 'react'
import { ApiError, getMe, updateMe, updateMyPassword, type Me } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function AccountInfoForm({ me, onUpdated }: { me: Me; onUpdated: (me: Me) => void }) {
  const { token, setName } = useAuth()
  const [name, setNameField] = useState(me.name)
  const [email, setEmail] = useState(me.email ?? '')
  const [phone, setPhone] = useState(me.phone ?? '')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const { toast, showToast } = useToast()

  async function handleSubmit() {
    if (!token) return

    setSubmitting(true)
    setErrors({})

    try {
      const result = await updateMe({ name, email: email || null, phone: phone || null }, token)
      onUpdated(result.data)
      setName(result.data.name)
      showToast({ type: 'success', message: t['management.profile.updateSuccess'] })
    } catch (caught) {
      if (caught instanceof ApiError && caught.errors) {
        const mapped: Record<string, string> = {}
        for (const [key, messages] of Object.entries(caught.errors)) {
          mapped[key] = messages[0]
        }
        setErrors(mapped)
      } else {
        showToast({ type: 'error', message: caught instanceof Error ? caught.message : String(caught) })
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-md bg-white p-5 shadow-sm md:p-6">
      <h3 className="mb-4 text-base font-semibold text-gray-800">{t['management.profile.accountInfo']}</h3>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label className="flex flex-col gap-1.5 text-sm sm:col-span-2">
          <span className="font-medium text-gray-700">{t['management.fields.name']}</span>
          <input className={inputClass} value={name} onChange={(event) => setNameField(event.target.value)} />
          {errors.name && <span className="text-xs text-danger-500">{errors.name}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.email']}</span>
          <input className={inputClass} type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
          {errors.email && <span className="text-xs text-danger-500">{errors.email}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.fields.phone']}</span>
          <input className={inputClass} value={phone} onChange={(event) => setPhone(event.target.value)} />
          {errors.phone && <span className="text-xs text-danger-500">{errors.phone}</span>}
        </label>
      </div>
      <div className="mt-5">
        <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
          {submitting ? t['management.table.saving'] : t['management.table.save']}
        </button>
      </div>
      <ToastViewport toast={toast} />
    </div>
  )
}

function ChangePasswordForm() {
  const { token } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const { toast, showToast } = useToast()

  async function handleSubmit() {
    if (!token) return

    setSubmitting(true)
    setErrors({})

    try {
      await updateMyPassword(
        { current_password: currentPassword, password, password_confirmation: passwordConfirmation },
        token,
      )
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      showToast({ type: 'success', message: t['management.profile.passwordUpdateSuccess'] })
    } catch (caught) {
      if (caught instanceof ApiError && caught.errors) {
        const mapped: Record<string, string> = {}
        for (const [key, messages] of Object.entries(caught.errors)) {
          mapped[key] = messages[0]
        }
        setErrors(mapped)
      } else {
        showToast({ type: 'error', message: caught instanceof Error ? caught.message : String(caught) })
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-md bg-white p-5 shadow-sm md:p-6">
      <h3 className="mb-4 text-base font-semibold text-gray-800">{t['management.profile.changePassword']}</h3>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.profile.currentPassword']}</span>
          <input
            className={inputClass}
            type="password"
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
          />
          {errors.current_password && <span className="text-xs text-danger-500">{errors.current_password}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.profile.newPassword']}</span>
          <input className={inputClass} type="password" value={password} onChange={(event) => setPassword(event.target.value)} />
          {errors.password && <span className="text-xs text-danger-500">{errors.password}</span>}
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.profile.confirmPassword']}</span>
          <input
            className={inputClass}
            type="password"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
          />
        </label>
      </div>
      <div className="mt-5">
        <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
          {submitting ? t['management.table.saving'] : t['management.table.save']}
        </button>
      </div>
      <ToastViewport toast={toast} />
    </div>
  )
}

export default function MyProfilePage() {
  const { token } = useAuth()
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
        <h2 className="text-xl font-semibold text-gray-800">{t['management.profile.title']}</h2>
        <p className="mt-1 text-sm text-gray-500">{t['management.profile.subtitle']}</p>
      </div>

      {me && (
        <>
          <AccountInfoForm me={me} onUpdated={setMe} />
          <ChangePasswordForm />
        </>
      )}
    </section>
  )
}
