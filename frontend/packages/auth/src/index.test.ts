import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearStoredRole,
  clearStoredToken,
  getStoredRole,
  getStoredToken,
  storeRole,
  storeToken,
} from './index'

class MemoryStorage implements Storage {
  private store = new Map<string, string>()

  get length() {
    return this.store.size
  }

  clear() {
    this.store.clear()
  }

  getItem(key: string) {
    return this.store.get(key) ?? null
  }

  key(index: number) {
    return Array.from(this.store.keys())[index] ?? null
  }

  removeItem(key: string) {
    this.store.delete(key)
  }

  setItem(key: string, value: string) {
    this.store.set(key, value)
  }
}

describe('token storage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', new MemoryStorage())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns null when no token is stored', () => {
    expect(getStoredToken()).toBeNull()
  })

  it('stores and retrieves a token', () => {
    storeToken('abc123')

    expect(getStoredToken()).toBe('abc123')
  })

  it('clears a stored token', () => {
    storeToken('abc123')
    clearStoredToken()

    expect(getStoredToken()).toBeNull()
  })
})

describe('role storage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', new MemoryStorage())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns null when no role is stored', () => {
    expect(getStoredRole()).toBeNull()
  })

  it('stores and retrieves a role', () => {
    storeRole('national_admin')

    expect(getStoredRole()).toBe('national_admin')
  })

  it('clears a stored role', () => {
    storeRole('national_admin')
    clearStoredRole()

    expect(getStoredRole()).toBeNull()
  })
})
