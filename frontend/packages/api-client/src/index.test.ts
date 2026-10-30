import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, getHealth } from './index'

describe('apiFetch', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns parsed JSON when the response is ok', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: { status: 'ok', timestamp: '2026-10-30T00:00:00Z' },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getHealth()

    expect(result.data.status).toBe('ok')
    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/health`)
  })

  it('throws when the response is not ok', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 503 }))

    await expect(apiFetch('/health')).rejects.toThrow('503')
  })
})
