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

export interface AuthContextValue {
  token: string | null
  role: string | null
  setToken: (token: string) => void
  setRole: (role: string | null) => void
  clearToken: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setTokenState] = useState<string | null>(() => getStoredToken())
  const [role, setRoleState] = useState<string | null>(() => getStoredRole())

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

  const clearToken = useCallback(() => {
    clearStoredToken()
    clearStoredRole()
    setTokenState(null)
    setRoleState(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ token, role, setToken, setRole, clearToken }),
    [token, role, setToken, setRole, clearToken],
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
