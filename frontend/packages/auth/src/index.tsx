import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

const TOKEN_STORAGE_KEY = 'ttla.authToken'
const ROLE_STORAGE_KEY = 'ttla.authRole'
const NAME_STORAGE_KEY = 'ttla.authName'
const USER_ID_STORAGE_KEY = 'ttla.authUserId'
const SCHOOL_ID_STORAGE_KEY = 'ttla.authSchoolId'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function storeToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearStoredToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

export function getStoredRole(): string | null {
  return localStorage.getItem(ROLE_STORAGE_KEY)
}

export function storeRole(role: string): void {
  localStorage.setItem(ROLE_STORAGE_KEY, role)
}

export function clearStoredRole(): void {
  localStorage.removeItem(ROLE_STORAGE_KEY)
}

export function getStoredName(): string | null {
  return localStorage.getItem(NAME_STORAGE_KEY)
}

export function storeName(name: string): void {
  localStorage.setItem(NAME_STORAGE_KEY, name)
}

export function clearStoredName(): void {
  localStorage.removeItem(NAME_STORAGE_KEY)
}

export function getStoredUserId(): number | null {
  const raw = localStorage.getItem(USER_ID_STORAGE_KEY)
  return raw === null ? null : Number(raw)
}

export function storeUserId(userId: number): void {
  localStorage.setItem(USER_ID_STORAGE_KEY, String(userId))
}

export function clearStoredUserId(): void {
  localStorage.removeItem(USER_ID_STORAGE_KEY)
}

export function getStoredSchoolId(): number | null {
  const raw = localStorage.getItem(SCHOOL_ID_STORAGE_KEY)
  return raw === null ? null : Number(raw)
}

export function storeSchoolId(schoolId: number): void {
  localStorage.setItem(SCHOOL_ID_STORAGE_KEY, String(schoolId))
}

export function clearStoredSchoolId(): void {
  localStorage.removeItem(SCHOOL_ID_STORAGE_KEY)
}

export interface AuthContextValue {
  token: string | null
  role: string | null
  /**
   * The signed-in user's display name, when the login flow supplied one.
   * Null for a guest, and also null for a session whose token was stored
   * before this field existed — consumers must have a fallback.
   */
  name: string | null
  /**
   * The signed-in user's own numeric id, when the login flow supplied one.
   * Null for a guest, and also null for a session stored before this field
   * existed — consumers must have a fallback.
   */
  userId: number | null
  /**
   * The signed-in user's own school_id, for a school-scoped actor (e.g.
   * school_admin) — null for a national-level actor who has none, for a
   * guest, and for a session stored before this field existed.
   * Management-portal-only today (teacher/student logins don't set it);
   * used to restrict a school picker to the admin's own school rather
   * than trusting the backend to do it, since the underlying `/schools`
   * endpoint is intentionally public/unscoped (see TenantScope's own
   * doc comment) and returns every school regardless of who's asking.
   */
  schoolId: number | null
  setToken: (token: string) => void
  setRole: (role: string | null) => void
  setName: (name: string | null) => void
  setUserId: (userId: number | null) => void
  setSchoolId: (schoolId: number | null) => void
  clearToken: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setTokenState] = useState<string | null>(() => getStoredToken())
  const [role, setRoleState] = useState<string | null>(() => getStoredRole())
  const [name, setNameState] = useState<string | null>(() => getStoredName())
  const [userId, setUserIdState] = useState<number | null>(() => getStoredUserId())
  const [schoolId, setSchoolIdState] = useState<number | null>(() => getStoredSchoolId())

  const setToken = useCallback((next: string) => {
    storeToken(next)
    setTokenState(next)
  }, [])

  const setRole = useCallback((next: string | null) => {
    if (next) {
      storeRole(next)
    } else {
      clearStoredRole()
    }
    setRoleState(next)
  }, [])

  const setName = useCallback((next: string | null) => {
    if (next) {
      storeName(next)
    } else {
      clearStoredName()
    }
    setNameState(next)
  }, [])

  const setUserId = useCallback((next: number | null) => {
    if (next !== null) {
      storeUserId(next)
    } else {
      clearStoredUserId()
    }
    setUserIdState(next)
  }, [])

  const setSchoolId = useCallback((next: number | null) => {
    if (next !== null) {
      storeSchoolId(next)
    } else {
      clearStoredSchoolId()
    }
    setSchoolIdState(next)
  }, [])

  const clearToken = useCallback(() => {
    clearStoredToken()
    clearStoredRole()
    clearStoredName()
    clearStoredUserId()
    clearStoredSchoolId()
    setTokenState(null)
    setRoleState(null)
    setNameState(null)
    setUserIdState(null)
    setSchoolIdState(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ token, role, name, userId, schoolId, setToken, setRole, setName, setUserId, setSchoolId, clearToken }),
    [token, role, name, userId, schoolId, setToken, setRole, setName, setUserId, setSchoolId, clearToken],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }

  return context
}
