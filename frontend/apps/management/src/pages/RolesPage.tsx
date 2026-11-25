import { useNavigate } from 'react-router-dom'
import { createRole, deleteRole, listRoles, updateRole, type Role } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = { name: '', label: '', description: '' }
const requiredFields = ['name', 'label']

function toPayload(values: Record<string, string>) {
  return {
    name: values.name,
    label: values.label,
    description: values.description || null,
  }
}

function RoleDetails({ role }: { role: Role }) {
  const permissions = role.permissions ?? []

  return (
    <div className="flex flex-col gap-3">
      <div>
        <p className="text-sm text-gray-500">{t['management.fields.description']}</p>
        <p className="text-sm font-medium text-gray-800">{role.description ?? '—'}</p>
      </div>
      <div>
        <p className="mb-1.5 text-sm text-gray-500">{t['management.fields.permissions']}</p>
        {permissions.length === 0 ? (
          <p className="text-sm text-gray-500">{t['management.fields.noPermissions']}</p>
        ) : (
          <div className="flex flex-wrap gap-1.5">
            {permissions.map((permission) => (
              <span
                key={permission.id}
                className="rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-600"
              >
                {permission.label}
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

export default function RolesPage() {
  const navigate = useNavigate()
  const { token, can } = useAuth()

  const columns: GeoColumn<Role>[] = [
    { key: 'label', label: t['management.fields.label'], render: (row) => row.label },
    { key: 'name', label: t['management.fields.code'], render: (row) => row.name },
    {
      key: 'permissions',
      label: t['management.fields.permissions'],
      render: (row) => String(row.permissions?.length ?? 0),
    },
  ]

  return (
    <GeoCrudTable<Role>
      title={t['management.roles.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({ name: row.name, label: row.label, description: row.description ?? '' })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.code']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.label']} required error={fieldErrors.label}>
            <input
              className={inputClass}
              value={values.label}
              onChange={(event) => onChange('label', event.target.value)}
            />
          </GeoField>
          <GeoField label={t['management.fields.description']} error={fieldErrors.description}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={3}
              value={values.description}
              onChange={(event) => onChange('description', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(_page, filters) => {
        if (!token) return Promise.reject(new Error('Not authenticated'))

        const search = filters.search.trim().toLowerCase()

        return listRoles(token).then((result) => {
          const filtered = search
            ? result.data.filter(
                (role) => role.name.toLowerCase().includes(search) || role.label.toLowerCase().includes(search),
              )
            : result.data

          return toSinglePage(filtered)
        })
      }}
      createRow={(values, authToken) => createRole(toPayload(values), authToken)}
      updateRow={(id, values, authToken) => updateRole(id, toPayload(values), authToken)}
      deleteRow={(id, authToken) => deleteRole(id, authToken)}
      renderDetails={(row) => <RoleDetails role={row} />}
      rowActions={(row) =>
        can('roles.update') ? (
          <button
            type="button"
            className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
            onClick={() => navigate(`/dashboard/users-roles/roles/${row.id}/permissions`)}
            aria-label={t['management.roles.assignPermissions']}
            title={t['management.roles.assignPermissions']}
          >
            <span className="material-symbols-outlined !text-md">admin_panel_settings</span>
          </button>
        ) : null
      }
      createPermission="roles.create"
      updatePermission="roles.update"
      deletePermission="roles.delete"
    />
  )
}
