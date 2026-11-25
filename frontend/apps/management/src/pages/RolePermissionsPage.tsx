import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError, getRole, listPermissions, updateRolePermissions, type Permission, type Role } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { buttonNeutral, buttonPrimary } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

// Groups "schools.view"/"schools.update"/... under a "schools" heading —
// purely a display grouping, derived from the permission name's own
// dot-separated convention (see PermissionSeeder), not a separate concept
// the backend tracks.
function groupByResource(permissions: Permission[]): Array<[string, Permission[]]> {
  const groups = new Map<string, Permission[]>()

  for (const permission of permissions) {
    const resource = permission.name.split('.')[0]
    const group = groups.get(resource) ?? []
    group.push(permission)
    groups.set(resource, group)
  }

  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))
}

export default function RolePermissionsPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { token, can } = useAuth()
  const { toast, showToast } = useToast()

  const [role, setRole] = useState<Role | null>(null)
  const [permissions, setPermissions] = useState<Permission[]>([])
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)

  const roleId = Number(id)

  useEffect(() => {
    if (!token || !Number.isFinite(roleId)) return

    setLoading(true)
    setLoadError(null)

    Promise.all([getRole(roleId, token), listPermissions(token)])
      .then(([roleResult, permissionsResult]) => {
        setRole(roleResult.data)
        setPermissions(permissionsResult.data)
        setSelected(new Set((roleResult.data.permissions ?? []).map((permission) => permission.name)))
      })
      .catch((error: unknown) => {
        setLoadError(error instanceof ApiError ? error.message : String(error))
      })
      .finally(() => setLoading(false))
  }, [token, roleId])

  if (!can('roles.update')) {
    return <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">{t['management.table.accessDenied']}</p>
  }

  function toggle(name: string) {
    setSelected((previous) => {
      const next = new Set(previous)
      if (next.has(name)) {
        next.delete(name)
      } else {
        next.add(name)
      }
      return next
    })
  }

  async function handleSave() {
    if (!token || !role) return

    setSaving(true)

    try {
      const updated = await updateRolePermissions(role.id, [...selected], token)
      setRole(updated.data)
      showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
    } catch (error) {
      const message = error instanceof ApiError ? error.message : String(error)
      showToast({ type: 'error', message })
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-gray-500">{t['management.table.loading']}</p>
  }

  if (loadError || !role) {
    return <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{loadError}</p>
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-gray-800">
          {t['management.roles.assignPermissionsFor']} {role.label}
        </h2>
        <button type="button" className={buttonNeutral} onClick={() => navigate('/dashboard/users-roles/roles')}>
          {t['management.table.close']}
        </button>
      </div>

      <div className="flex flex-col gap-5 rounded-md bg-white p-5 shadow-sm">
        {groupByResource(permissions).map(([resource, group]) => (
          <div key={resource}>
            <h3 className="mb-2 text-sm font-semibold text-gray-700 capitalize">{resource.replace(/_/g, ' ')}</h3>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
              {group.map((permission) => (
                <label key={permission.id} className="flex items-center gap-2 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    checked={selected.has(permission.name)}
                    onChange={() => toggle(permission.name)}
                  />
                  {permission.label}
                </label>
              ))}
            </div>
          </div>
        ))}

        <div className="flex justify-end gap-3 border-t border-gray-100 pt-4">
          <button
            type="button"
            className={buttonNeutral}
            onClick={() => navigate('/dashboard/users-roles/roles')}
            disabled={saving}
          >
            {t['management.table.cancel']}
          </button>
          <button type="button" className={buttonPrimary} onClick={handleSave} disabled={saving}>
            {saving ? t['management.table.saving'] : t['management.table.save']}
          </button>
        </div>
      </div>

      <ToastViewport toast={toast} />
    </section>
  )
}
