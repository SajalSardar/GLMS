import { listPermissions, type Permission } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { toSinglePage, type GeoColumn } from '../components/GeoCrudTable'

const t = getCommonStrings('en')

// The permission catalog is read-only everywhere — there is no create/
// update/delete endpoint for it at all (PermissionController only exposes
// index). The permission names passed below don't exist in the catalog,
// so `can()` is false for every actor and GeoCrudTable hides the Add
// button and the whole Actions column (see its showActionsColumn).
export default function PermissionsPage() {
  const { token } = useAuth()

  const columns: GeoColumn<Permission>[] = [
    { key: 'label', label: t['management.fields.label'], render: (row) => row.label },
    { key: 'name', label: t['management.fields.code'], render: (row) => row.name },
    { key: 'description', label: t['management.fields.description'], render: (row) => row.description ?? '—' },
  ]

  return (
    <GeoCrudTable<Permission>
      title={t['management.permissions.title']}
      columns={columns}
      fetchPage={(_page, filters) => {
        if (!token) return Promise.reject(new Error('Not authenticated'))

        const search = filters.search.trim().toLowerCase()

        return listPermissions(token).then((result) => {
          const filtered = search
            ? result.data.filter(
                (permission) =>
                  permission.name.toLowerCase().includes(search) || permission.label.toLowerCase().includes(search),
              )
            : result.data

          return toSinglePage(filtered)
        })
      }}
      deleteRow={() => Promise.resolve()}
      createPermission="permissions.create"
      updatePermission="permissions.update"
      deletePermission="permissions.delete"
    />
  )
}
