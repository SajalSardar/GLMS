import { useEffect, useState } from 'react'
import {
  assignStaffUserRole,
  createStaffUser,
  deleteStaffUser,
  listRoles,
  listStaffUsers,
  updateStaffUser,
  type Role,
  type StaffUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  name: '',
  email: '',
  phone: '',
  password: '',
  role_id: '',
}

const requiredFields = ['name', 'email']

export default function UsersPage() {
  const { token } = useAuth()
  const [roles, setRoles] = useState<Role[]>([])

  useEffect(() => {
    if (!token) return

    listRoles(token)
      .then((result) => setRoles(result.data))
      .catch(() => setRoles([]))
  }, [token])

  const columns: GeoColumn<StaffUser>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'email', label: t['management.fields.email'], render: (row) => row.email ?? '—' },
    { key: 'phone', label: t['management.fields.phone'], render: (row) => row.phone ?? '—' },
    { key: 'role', label: t['management.fields.role'], render: (row) => row.role?.label ?? t['management.fields.noRole'] },
    { key: 'school', label: t['management.fields.school'], render: (row) => row.school?.name ?? '—' },
    { key: 'district', label: t['management.fields.district'], render: (row) => row.district?.name ?? '—' },
  ]

  return (
    <GeoCrudTable<StaffUser>
      title={t['management.users.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        name: row.name,
        email: row.email ?? '',
        phone: row.phone ?? '',
        password: '',
        role_id: row.role ? String(row.role.id) : '',
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
          <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
            <input
              className={inputClass}
              value={values.name}
              onChange={(event) => onChange('name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.email']} required error={fieldErrors.email}>
            <input
              type="email"
              className={inputClass}
              value={values.email}
              onChange={(event) => onChange('email', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.phone']} error={fieldErrors.phone}>
            <input
              className={inputClass}
              value={values.phone}
              onChange={(event) => onChange('phone', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.password']} required={!editingId} error={fieldErrors.password}>
            <input
              type="password"
              autoComplete="new-password"
              className={inputClass}
              value={values.password}
              onChange={(event) => onChange('password', event.target.value)}
              placeholder={editingId ? t['management.fields.passwordHintEdit'] : undefined}
            />
          </GeoField>

          <GeoField label={t['management.fields.role']} error={fieldErrors.role_id}>
            <SearchableSelect
              value={values.role_id}
              onChange={(next) => onChange('role_id', next)}
              placeholder={t['management.fields.noRole']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={roles.map((role) => ({ value: String(role.id), label: role.label }))}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page, filters) => {
        if (!token) return Promise.reject(new Error('Not authenticated'))

        return listStaffUsers({ page, search: filters.search }, token)
      }}
      createRow={(values, authToken) =>
        createStaffUser(
          {
            name: values.name,
            email: values.email,
            phone: values.phone || null,
            password: values.password,
            role_id: values.role_id ? Number(values.role_id) : null,
          },
          authToken,
        )
      }
      updateRow={async (id, values, authToken) => {
        const updated = await updateStaffUser(
          id,
          {
            name: values.name,
            email: values.email,
            phone: values.phone || null,
            ...(values.password.trim() ? { password: values.password } : {}),
          },
          authToken,
        )

        const nextRoleId = values.role_id ? Number(values.role_id) : null

        if (nextRoleId !== null && nextRoleId !== updated.data.role?.id) {
          return assignStaffUserRole(id, { role_id: nextRoleId }, authToken)
        }

        return updated
      }}
      deleteRow={(id, authToken) => deleteStaffUser(id, authToken)}
      createPermission="users.create"
      updatePermission="users.update"
      deletePermission="users.delete"
    />
  )
}
