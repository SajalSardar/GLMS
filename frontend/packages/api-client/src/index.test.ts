import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  apiFetch,
  createAcademicYear,
  createClass,
  createClassVersion,
  createDivision,
  createSchool,
  createSection,
  createShift,
  deleteDivision,
  getHealth,
  listAcademicYears,
  listClasses,
  listClassVersions,
  listDistricts,
  listDivisions,
  listSchools,
  listSections,
  listShifts,
  listSubjects,
  listUpazilas,
  managementLogin,
  studentLogin,
  updateDivision,
  updateSchool,
} from './index'

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

  it('throws an ApiError with a fallback message when the response has no JSON body', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 503 }))

    await expect(apiFetch('/health')).rejects.toThrow('503')
  })

  it('throws an ApiError carrying the server-provided message and status', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 422,
        json: () =>
          Promise.resolve({
            message: 'The provided credentials are incorrect.',
            errors: { login: ['The provided credentials are incorrect.'] },
          }),
      }),
    )

    const error = await apiFetch('/auth/student/login').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).message).toBe('The provided credentials are incorrect.')
    expect((error as ApiError).errors).toEqual({
      login: ['The provided credentials are incorrect.'],
    })
  })

  it('resolves with undefined for a 204 No Content response', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 204 }))

    await expect(apiFetch('/divisions/1')).resolves.toBeUndefined()
  })
})

describe('studentLogin', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('posts the student id and password to the student login endpoint', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 }, token: 'plain-text-token' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await studentLogin('STU-001', 'secret', 'web')

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/auth/student/login`,
    )
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ login: 'STU-001', password: 'secret', device_name: 'web' }),
    })
    expect(result.token).toBe('plain-text-token')
  })
})

describe('managementLogin', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('posts the phone/email and password to the management login endpoint', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 }, token: 'plain-text-token' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await managementLogin('admin@example.com', 'secret', 'web')

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/auth/management/login`,
    )
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ login: 'admin@example.com', password: 'secret', device_name: 'web' }),
    })
    expect(result.token).toBe('plain-text-token')
  })
})

describe('geographic reference data', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists divisions with page and per_page query params', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: [{ id: 1, name: 'Dhaka', bn_name: 'ঢাকা', url: null }],
          meta: { current_page: 2, last_page: 3, per_page: 10, total: 25 },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await listDivisions({ page: 2, perPage: 10 })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/divisions?page=2&per_page=10`,
    )
    expect(result.meta.total).toBe(25)
  })

  it('lists divisions filtered by a search term', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listDivisions({ search: 'dha' })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/divisions?search=dha`)
  })

  it('lists districts filtered by division_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listDistricts({ divisionId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/districts?division_id=5`,
    )
  })

  it('lists upazilas filtered by district_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listUpazilas({ districtId: 9 })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/upazilas?district_id=9`,
    )
  })

  it('lists subjects filtered by a search term', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listSubjects({ search: 'math' })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/subjects?search=math`)
  })

  it('creates a division with a bearer token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1, name: 'Dhaka', bn_name: 'ঢাকা', url: null } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createDivision({ name: 'Dhaka', bn_name: 'ঢাকা' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/divisions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
      body: JSON.stringify({ name: 'Dhaka', bn_name: 'ঢাকা' }),
    })
  })

  it('updates a division with PATCH and a bearer token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1, name: 'Dhaka', bn_name: 'ঢাকা', url: null } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await updateDivision(1, { name: 'Dhaka Updated' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/divisions/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PATCH',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('deletes a division with DELETE and resolves on 204', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await expect(deleteDivision(1, 'secret-token')).resolves.toBeUndefined()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/divisions/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'DELETE',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('schools', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists schools with its filters', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listSchools({ divisionId: 1, districtId: 2, upazilaId: 3, type: 'government', level: 'secondary' })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/schools?division_id=1&district_id=2&upazila_id=3&type=government&level=secondary`,
    )
  })

  it('creates a school with a multipart body and no Content-Type header', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('name', 'Test School')

    await createSchool(formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/schools`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(init.body).toBe(formData)
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer secret-token')
  })

  it('updates a school by POSTing with a _method=PATCH spoof field', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('name', 'Updated School')

    await updateSchool(1, formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/schools/1`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(formData.get('_method')).toBe('PATCH')
  })
})

describe('school classes', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists classes filtered by school_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClasses({ schoolId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes?school_id=5`)
  })

  it('creates a class with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClass({ school_id: 5, name: 'Class Six' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ school_id: 5, name: 'Class Six' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('academic years', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists academic years filtered by school_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listAcademicYears({ schoolId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/academic-years?school_id=5`)
  })

  it('creates an academic year with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const payload = { school_id: 5, name: '2026', start_date: '2026-01-01', end_date: '2026-12-31' }
    await createAcademicYear(payload, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/academic-years`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify(payload),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('class versions', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists class versions filtered by class_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClassVersions({ classId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-versions?class_id=5`)
  })

  it('creates a class version with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClassVersion({ class_id: 5, name: 'English Version' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-versions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ class_id: 5, name: 'English Version' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('shifts', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists shifts filtered by class_version_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listShifts({ classVersionId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/shifts?class_version_id=5`)
  })

  it('creates a shift with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const payload = {
      class_version_id: 5,
      name: 'Morning',
      start_time: '08:00',
      end_time: '12:00',
      days_of_week: ['saturday', 'sunday'] as const,
    }
    await createShift(payload, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/shifts`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify(payload),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('sections', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists sections filtered by shift_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listSections({ shiftId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/sections?shift_id=5`)
  })

  it('creates a section with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createSection({ shift_id: 5, name: 'A' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/sections`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ shift_id: 5, name: 'A' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})
