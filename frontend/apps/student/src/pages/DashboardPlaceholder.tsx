import { useEffect, useState } from 'react'
import { getHealth } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

type Status = 'loading' | 'ok' | 'error'

const strings = getCommonStrings()

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
    <section>
      <h2>Dashboard</h2>
      <div className={`status-card status-${status}`}>
        <h3>{strings['dashboard.backendStatus']}</h3>
        {status === 'loading' && <p>{strings['dashboard.statusLoading']}</p>}
        {status === 'ok' && (
          <p>
            {strings['dashboard.statusOk']} — {timestamp}
          </p>
        )}
        {status === 'error' && <p>{strings['dashboard.statusError']}</p>}
      </div>
    </section>
  )
}
