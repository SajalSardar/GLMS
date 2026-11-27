import { useEffect, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { listMyChildren, type StudentProfile } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../lib/locale'
import { useGuardianChild } from '../lib/guardianChild'

// A guardian's post-login landing spot: pick which linked child's
// dashboard to view. Skipped automatically (no UI flash) when there's
// only one child — the common case — so only a guardian with more than
// one linked child ever actually sees this screen themselves; everyone
// else reaches it only via the header's "switch child" link.
export default function SelectChildPage() {
  const { token, role, clearToken } = useAuth()
  const { setSelectedChildId } = useGuardianChild()
  const { t } = useLocale()
  const navigate = useNavigate()

  const [children, setChildren] = useState<StudentProfile[] | null>(null)

  useEffect(() => {
    if (!token || role !== 'parent_guardian') return

    listMyChildren(token)
      .then((result) => {
        if (result.data.length === 1) {
          setSelectedChildId(result.data[0].id)
          navigate('/dashboard', { replace: true })
          return
        }

        setChildren(result.data)
      })
      .catch(() => setChildren([]))
  }, [token, role, navigate, setSelectedChildId])

  if (role !== 'parent_guardian') {
    return <Navigate to="/dashboard" replace />
  }

  function selectChild(profile: StudentProfile) {
    setSelectedChildId(profile.id)
    navigate('/dashboard')
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-10">
      <h1 className="text-xl font-semibold text-primary-900">{t['student.selectChild.title']}</h1>

      {children === null && <p className="mt-4 text-sm text-primary-600">{t['student.selectChild.loading']}</p>}

      {children !== null && children.length === 0 && (
        <div className="mt-4 rounded-lg border border-primary-100 bg-white p-5 shadow-sm">
          <p className="text-sm text-primary-600">{t['student.selectChild.empty']}</p>
          <button
            type="button"
            onClick={() => clearToken()}
            className="mt-4 cursor-pointer rounded-md border border-primary-200 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-50"
          >
            {t['student.nav.logout']}
          </button>
        </div>
      )}

      {children !== null && children.length > 1 && (
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          {children.map((child) => (
            <button
              key={child.id}
              type="button"
              onClick={() => selectChild(child)}
              className="cursor-pointer rounded-lg border border-primary-100 bg-white p-5 text-left shadow-sm transition-colors hover:border-primary-500"
            >
              <span className="block text-base font-semibold text-primary-900">{child.user?.name ?? child.student_id}</span>
              <span className="mt-1 block text-sm text-primary-600">
                {child.class?.name ?? ''}
                {child.section?.name ? ` · ${child.section.name}` : ''}
                {child.roll_number ? ` · ${t['student.selectChild.roll']} ${child.roll_number}` : ''}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
