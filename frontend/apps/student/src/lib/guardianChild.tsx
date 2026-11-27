import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from '@ttla/auth'

const SELECTED_CHILD_STORAGE_KEY = 'ttla.student.selectedChildId'

function getStoredChildId(): number | null {
  const raw = localStorage.getItem(SELECTED_CHILD_STORAGE_KEY)
  return raw === null ? null : Number(raw)
}

export interface GuardianChildContextValue {
  selectedChildId: number | null
  setSelectedChildId: (studentProfileId: number | null) => void
}

const GuardianChildContext = createContext<GuardianChildContextValue | null>(null)

// Which of a guardian's linked children the dashboard is currently
// scoped to — student-portal-local (not part of the shared @ttla/auth
// package, which the teacher/management apps also use and have no
// concept of "child selection" at all). Cleared automatically when the
// session's own token goes away, so a stale child selection never
// survives into a different person's later login on the same browser.
export function GuardianChildProvider({ children }: { children: ReactNode }) {
  const { token } = useAuth()
  const [selectedChildId, setSelectedChildIdState] = useState<number | null>(() => getStoredChildId())

  useEffect(() => {
    if (token === null) {
      localStorage.removeItem(SELECTED_CHILD_STORAGE_KEY)
      setSelectedChildIdState(null)
    }
  }, [token])

  const setSelectedChildId = useCallback((studentProfileId: number | null) => {
    if (studentProfileId === null) {
      localStorage.removeItem(SELECTED_CHILD_STORAGE_KEY)
    } else {
      localStorage.setItem(SELECTED_CHILD_STORAGE_KEY, String(studentProfileId))
    }
    setSelectedChildIdState(studentProfileId)
  }, [])

  const value = useMemo<GuardianChildContextValue>(
    () => ({ selectedChildId, setSelectedChildId }),
    [selectedChildId, setSelectedChildId],
  )

  return <GuardianChildContext.Provider value={value}>{children}</GuardianChildContext.Provider>
}

export function useGuardianChild(): GuardianChildContextValue {
  const context = useContext(GuardianChildContext)

  if (!context) {
    throw new Error('useGuardianChild must be used within a GuardianChildProvider')
  }

  return context
}
