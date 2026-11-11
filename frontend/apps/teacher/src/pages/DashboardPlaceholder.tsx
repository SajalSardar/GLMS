import { useEffect, useState } from 'react'
import { getHealth } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

type Status = 'loading' | 'ok' | 'error'

const strings = getCommonStrings()

const statusDot: Record<Status, string> = {
  loading: 'bg-gray-300',
  ok: 'bg-success-500',
  error: 'bg-danger-500',
}

export default function DashboardPlaceholder() {
  const [status, setStatus] = useState<Status>('loading')
  const [timestamp, setTimestamp] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    getHealth()
      .then((result) => {
        if (cancelled) return
        setStatus('ok')
        setTimestamp(result.data.timestamp)
      })
      .catch(() => {
        if (cancelled) return
        setStatus('error')
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <section className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold text-gray-800">Dashboard</h2>
        <p className="mt-1 text-sm text-gray-500">Overview and system status.</p>
      </div>

      <div className="max-w-md rounded-md bg-white p-5 shadow-sm md:p-6">
        <h3 className="text-sm font-medium text-gray-500">{strings['dashboard.backendStatus']}</h3>
        <div className="mt-3 flex items-center gap-2.5">
          <span className={`h-2.5 w-2.5 rounded-full ${statusDot[status]}`} />
          {status === 'loading' && <p className="text-sm text-gray-600">{strings['dashboard.statusLoading']}</p>}
          {status === 'ok' && (
            <p className="text-sm text-gray-700">
              {strings['dashboard.statusOk']} — {timestamp}
            </p>
          )}
          {status === 'error' && <p className="text-sm text-danger-500">{strings['dashboard.statusError']}</p>}
        </div>
      </div>
    </section>
  )
}
