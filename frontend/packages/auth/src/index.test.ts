import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearStoredName,
  clearStoredRole,
  clearStoredToken,
  clearStoredUserId,
  getStoredName,
  getStoredRole,
  getStoredToken,
  getStoredUserId,
  storeName,
  storeRole,
  storeToken,
  storeUserId,
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

describe('name storage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', new MemoryStorage())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  // Null here is what makes CertificatePage fall back to the generic
  // "Student" label for a token stored before names were persisted.
  it('returns null when no name is stored', () => {
    expect(getStoredName()).toBeNull()
  })

  it('stores and retrieves a name', () => {
    storeName('রহিম উদ্দিন')

    expect(getStoredName()).toBe('রহিম উদ্দিন')
  })

  it('clears a stored name', () => {
    storeName('রহিম উদ্দিন')
    clearStoredName()

    expect(getStoredName()).toBeNull()
  })

  it('keeps the name under its own key, separate from token and role', () => {
    storeToken('abc123')
    storeRole('student')
    storeName('রহিম উদ্দিন')
    clearStoredName()

    expect(getStoredToken()).toBe('abc123')
    expect(getStoredRole()).toBe('student')
  })
})

describe('userId storage', () => {
  beforeEach(() => {
    vi.stubGlobal('localStorage', new MemoryStorage())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns null when no userId is stored', () => {
    expect(getStoredUserId()).toBeNull()
  })

  it('stores and retrieves a userId as a number', () => {
    storeUserId(42)

    expect(getStoredUserId()).toBe(42)
  })

  it('clears a stored userId', () => {
    storeUserId(42)
    clearStoredUserId()

    expect(getStoredUserId()).toBeNull()
  })
})
