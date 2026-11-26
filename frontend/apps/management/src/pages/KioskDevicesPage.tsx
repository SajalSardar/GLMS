import { useEffect, useState } from 'react'
import {
  createKioskDevice,
  deleteKioskDevice,
  listKioskDevices,
  listSchools,
  updateKioskDevice,
  type KioskDevice,
  type School,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { type GeoColumn } from '../components/GeoCrudTable'
import { schoolLabel } from '../components/schoolOptions'
import SearchableSelect from '../components/SearchableSelect'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'

const t = getCommonStrings('en')

// Shown exactly once, right after creation — the backend never returns
// the plaintext token again (only its hash is stored), so this is the
// only chance to copy it into the physical device.
function TokenRevealModal({ token, onClose }: { token: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(token)
      setCopied(true)
    } catch {
      // Clipboard access can be denied/unavailable — the token is still
      // selectable text in the box below, so this is a soft failure.
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5">
      <div className="w-full max-w-md rounded-md bg-white p-6 shadow-2xl" role="dialog" aria-modal="true">
        <h3 className="text-base font-semibold text-gray-800">{t['management.kioskDevices.tokenRevealTitle']}</h3>
        <p className="mt-2 text-sm text-gray-600">{t['management.kioskDevices.tokenRevealBody']}</p>

        <code className="mt-4 block w-full overflow-x-auto rounded-md bg-gray-50 px-3 py-2 text-xs break-all text-gray-800">
          {token}
        </code>

        <div className="mt-5 flex justify-end gap-3">
          <button type="button" className={buttonNeutral} onClick={handleCopy}>
            {copied ? t['management.kioskDevices.copied'] : t['management.kioskDevices.copyToken']}
          </button>
          <button type="button" className={buttonPrimary} onClick={onClose}>
            {t['management.kioskDevices.done']}
          </button>
        </div>
      </div>
    </div>
  )
}

function AddDeviceModal({
  schools,
  needsSchoolPicker,
  onCreated,
  onClose,
}: {
  schools: School[]
  needsSchoolPicker: boolean
  onCreated: (token: string) => void
  onClose: () => void
}) {
  const { token } = useAuth()
  const [name, setName] = useState('')
  const [schoolId, setSchoolId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    if (!token) return
    if (!name.trim()) {
      setError(t['management.kioskDevices.nameRequired'])
      return
    }

    setSubmitting(true)
    setError(null)

    try {
      const created = await createKioskDevice(
        { name: name.trim(), ...(needsSchoolPicker && schoolId ? { school_id: Number(schoolId) } : {}) },
        token,
      )
      onCreated(created.data.token)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div className="w-full max-w-md rounded-md bg-white p-6 shadow-2xl" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true">
        <h3 className="text-base font-semibold text-gray-800">{t['management.kioskDevices.addNew']}</h3>

        <label className="mt-4 flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.kioskDevices.name']}</span>
          <input className={inputClass} value={name} onChange={(event) => setName(event.target.value)} />
        </label>

        {needsSchoolPicker && (
          <label className="mt-4 flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['management.kioskDevices.school']}</span>
            <SearchableSelect
              value={schoolId}
              onChange={setSchoolId}
              placeholder={t['management.kioskDevices.school']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))}
            />
          </label>
        )}

        {error && <p className="mt-3 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}

        <div className="mt-5 flex justify-end gap-3">
          <button type="button" className={buttonNeutral} onClick={onClose} disabled={submitting}>
            {t['management.table.cancel']}
          </button>
          <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
            {submitting ? t['management.table.saving'] : t['management.table.save']}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function KioskDevicesPage() {
  const { token } = useAuth()
  const [schools, setSchools] = useState<School[]>([])
  const [adding, setAdding] = useState(false)
  const [revealToken, setRevealToken] = useState<string | null>(null)
  const [refreshKey, setRefreshKey] = useState(0)

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [token])

  const columns: GeoColumn<KioskDevice>[] = [
    { key: 'name', label: t['management.kioskDevices.name'], render: (row) => row.name },
    { key: 'school', label: t['management.kioskDevices.school'], render: (row) => row.school_name ?? '—' },
    {
      key: 'status',
      label: t['management.kioskDevices.status'],
      render: (row) => (row.is_active ? t['management.kioskDevices.active'] : t['management.kioskDevices.inactive']),
    },
    {
      key: 'last_used_at',
      label: t['management.kioskDevices.lastUsed'],
      render: (row) => (row.last_used_at ? new Date(row.last_used_at).toLocaleString() : t['management.kioskDevices.never']),
    },
  ]

  return (
    <section className="space-y-5">
      <GeoCrudTable<KioskDevice>
        key={refreshKey}
        title={t['management.kioskDevices.title']}
        columns={columns}
        fetchPage={(page) => listKioskDevices({ page, perPage: 15 }, token ?? undefined)}
        deleteRow={(id, authToken) => deleteKioskDevice(id, authToken)}
        onAdd={() => setAdding(true)}
        createPermission="kiosk_devices.create"
        updatePermission="kiosk_devices.update"
        deletePermission="kiosk_devices.delete"
        onEdit={(row) => {
          if (!token) return
          const nextActive = !row.is_active
          updateKioskDevice(row.id, { is_active: nextActive }, token).then(() => setRefreshKey((key) => key + 1))
        }}
      />

      {adding && (
        <AddDeviceModal
          schools={schools}
          // A school_admin's own listSchools call is already narrowed to
          // just their own school by TenantScope (it applies regardless of
          // the route's own public/auth:sanctum status — see TenantScope's
          // own comment), so `schools.length > 1` is only ever true for an
          // unscoped actor (national_admin), who is the only one who needs
          // to pick which school the device belongs to.
          needsSchoolPicker={schools.length > 1}
          onCreated={(plainToken) => {
            setAdding(false)
            setRevealToken(plainToken)
            setRefreshKey((key) => key + 1)
          }}
          onClose={() => setAdding(false)}
        />
      )}

      {revealToken && <TokenRevealModal token={revealToken} onClose={() => setRevealToken(null)} />}
    </section>
  )
}
