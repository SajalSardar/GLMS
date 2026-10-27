import { useEffect, useState } from 'react'
import { getHealth } from '../lib/api'
import en from '../locales/en.json'

type Status = 'loading' | 'ok' | 'error'

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
      <h2>{en['dashboard.title']}</h2>
      <div className={`status-card status-${status}`}>
        <h3>{en['dashboard.backendStatus']}</h3>
        {status === 'loading' && <p>{en['dashboard.statusLoading']}</p>}
        {status === 'ok' && (
          <p>
            {en['dashboard.statusOk']} — {timestamp}
          </p>
        )}
        {status === 'error' && <p>{en['dashboard.statusError']}</p>}
      </div>
    </section>
  )
}
