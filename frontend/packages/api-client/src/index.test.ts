import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  apiFetch,
  createAcademicYear,
  createChapterMaterial,
  createClass,
  createClassSchool,
  createClassVersion,
  createDivision,
  createPracticeExam,
  createSchool,
  createSection,
  createShift,
  createSubjectTeacherAssignment,
  deleteBookPdf,
  deleteChapterMaterial,
  deleteClassSchool,
  deleteDivision,
  deleteSubjectTeacherAssignment,
  getBook,
  getBookPdfLinks,
  getChapterMaterialDownloadLink,
  getDashboardSummary,
  getExam,
  getHealth,
  getMe,
  getPracticeExamConfig,
  getStudentDashboard,
  listAcademicYears,
  listBooks,
  listChapterMaterials,
  listMyChapterMaterialAssignments,
  listMyChapterMaterials,
  listChapters,
  listChapterVideos,
  listClasses,
  listClassSchools,
  listClassVersions,
  listDistricts,
  listDivisions,
  listExams,
  listMyExamAttempts,
  listMyUploadedChapterMaterials,
  listSchools,
  listSections,
  listShifts,
  listStudentProfiles,
  listSubjectTeacherAssignments,
  listSubjects,
  listTeacherUsers,
  listUpazilas,
  logout,
  managementLogin,
  randomDrawExamQuestions,
  saveExamAttemptAnswer,
  type ShiftPayload,
  startExamAttempt,
  studentLogin,
  submitExamAttempt,
  submitGuestExamAttempt,
  updateChapterMaterial,
  updateClassSchoolSubjects,
  updateDivision,
  updateMe,
  updateMyPassword,
  updateSchool,
  updateSubjectTeacherAssignment,
  uploadBookPdf,
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

describe('logout', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('posts to the logout endpoint with a bearer token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await logout('plain-text-token')

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/auth/logout`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: { Authorization: 'Bearer plain-text-token' },
    })
  })
})

describe('practice exams', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('fetches the practice exam config', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          single_chapter_question_count: 20,
          multi_chapter_question_count: 40,
          tier_pass_percent: 40,
          tier_good_percent: 60,
          tier_best_percent: 80,
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getPracticeExamConfig()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/practice-exams/config`)
    expect(result.single_chapter_question_count).toBe(20)
  })

  it('creates a practice exam without a token for a guest', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 7 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await createPracticeExam({ subjectId: 3, chapterIds: [1, 2] })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/practice-exams`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ subject_id: 3, chapter_ids: [1, 2] }),
    })
    expect((mockFetch.mock.calls[0][1] as RequestInit).headers).not.toHaveProperty('Authorization')
    expect(result.data.id).toBe(7)
  })

  it('creates a practice exam with a bearer token when logged in', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 8 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createPracticeExam({ subjectId: 3, chapterIds: [1] }, 'secret-token')

    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
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

  it('lists classes with no school filter', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClasses({ search: 'six' })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes?search=six`)
  })

  it('creates a class with no school_id in the body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClass({ name: 'Class Six' }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/classes`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ name: 'Class Six' }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('class schools', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists class schools filtered by school_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listClassSchools({ schoolId: 5 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools?school_id=5`)
  })

  it('creates a class school with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createClassSchool({ class_id: 7, school_id: 5 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ class_id: 7, school_id: 5 }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('deletes a class school', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await deleteClassSchool(9, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools/9`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'DELETE',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('syncs a class school\'s subjects with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 9 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await updateClassSchoolSubjects(9, [1, 2, 3], 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-schools/9/subjects`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PUT',
      body: JSON.stringify({ subject_ids: [1, 2, 3] }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('academic years', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists academic years filtered by search', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listAcademicYears({ search: '2026' })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/academic-years?search=2026`)
  })

  it('lists academic years filtered by current_only', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listAcademicYears({ currentOnly: true })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/academic-years?current_only=true`)
  })

  it('creates an academic year with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const payload = { name: '2026', start_date: '2026-01-01', end_date: '2026-12-31' }
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

    await createClassVersion({ class_id: 5, school_id: 3, version_id: 2 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/class-versions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ class_id: 5, school_id: 3, version_id: 2 }),
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

    const payload: ShiftPayload = {
      class_version_id: 5,
      name: 'Morning',
      start_time: '08:00',
      end_time: '12:00',
      days_of_week: ['saturday', 'sunday'],
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

describe('teacher users', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists teacher users filtered by school_id, with a bearer token when given', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listTeacherUsers({ schoolId: 5 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/teacher-users?school_id=5`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      headers: { Authorization: 'Bearer secret-token' },
    })
  })

  it('omits the Authorization header when no token is given', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listTeacherUsers({ schoolId: 5 })

    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: undefined })
  })
})

describe('subject-teacher assignments', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists assignments filtered by section_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listSubjectTeacherAssignments({ sectionId: 7 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/subject-teacher-assignments?section_id=7`)
  })

  it('creates an assignment with a JSON body', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await createSubjectTeacherAssignment({ section_id: 7, subject_id: 2, teacher_id: 3 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/subject-teacher-assignments`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ section_id: 7, subject_id: 2, teacher_id: 3 }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('updates an assignment with only teacher_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await updateSubjectTeacherAssignment(1, { teacher_id: 9 }, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/subject-teacher-assignments/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PATCH',
      body: JSON.stringify({ teacher_id: 9 }),
    })
  })

  it('deletes an assignment', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await deleteSubjectTeacherAssignment(1, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/subject-teacher-assignments/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'DELETE' })
  })
})

describe('books', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists books filtered by class_version_id and subject_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listBooks({ classVersionId: 5, subjectId: 2 })

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/books?class_version_id=5&subject_id=2`,
    )
  })

  it('lists books filtered by academic_year_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listBooks({ academicYearId: 3 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books?academic_year_id=3`)
  })

  it('gets a single book', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getBook(1)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1`)
  })

  it('requests a book\'s pdf download/preview links with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ download_url: 'https://x/d', preview_url: 'https://x/p' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getBookPdfLinks(1)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1/pdf/download-link`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'POST' })
    expect((mockFetch.mock.calls[0][1]?.headers as Record<string, string>).Authorization).toBeUndefined()
  })

  it('requests a book\'s pdf download/preview links with a bearer token when one is given', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ download_url: 'https://x/d', preview_url: 'https://x/p' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getBookPdfLinks(1, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1/pdf/download-link`)
    expect((mockFetch.mock.calls[0][1]?.headers as Record<string, string>).Authorization).toBe('Bearer secret-token')
  })

  it('lists books filtered by class_id and version_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listBooks({ classId: 6, versionId: 1 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books?class_id=6&version_id=1`)
  })

  it('uploads a pdf for a book as multipart form data', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('pdf', new File(['x'], 'book.pdf', { type: 'application/pdf' }))

    await uploadBookPdf(1, formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1/pdf`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'POST', body: formData })
    expect((mockFetch.mock.calls[0][1]?.headers as Record<string, string>).Authorization).toBe('Bearer secret-token')
  })

  it('deletes a book\'s pdf', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await deleteBookPdf(1, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/books/1/pdf`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'DELETE' })
  })
})

describe('chapters', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists chapters filtered by book_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listChapters({ bookId: 3 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapters?book_id=3`)
  })
})

describe('chapter videos', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists chapter videos filtered by chapter_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listChapterVideos({ chapterId: 9 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-videos?chapter_id=9`)
  })
})

describe('chapter materials', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists chapter materials filtered by chapter_id', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listChapterMaterials({ chapterId: 7 })

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials?chapter_id=7`)
  })

  it("lists the current student's own scoped materials with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyChapterMaterials('tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/mine`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it('requests a chapter material\'s download/preview links with a required token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ download_url: 'https://x/d', preview_url: 'https://x/p' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getChapterMaterialDownloadLink(5, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/5/download-link`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: { Authorization: 'Bearer tok' },
    })
  })
})

describe('chapter materials upload (teacher)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lists the current teacher's own subject assignments with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyChapterMaterialAssignments('tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/my-assignments`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it("lists the current teacher's own uploaded materials with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyUploadedChapterMaterials('tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/my-uploads`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it('creates a chapter material with a multipart body and no Content-Type header', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('chapter_id', '1')
    formData.append('section_id', '2')
    formData.append('type', 'primary')

    await createChapterMaterial(formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(init.body).toBe(formData)
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer secret-token')
  })

  it('updates a chapter material by POSTing with a _method=PATCH spoof field', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('title', 'Updated title')

    await updateChapterMaterial(1, formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/1`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(formData.get('_method')).toBe('PATCH')
  })

  it('deletes a chapter material with DELETE and resolves on 204', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await expect(deleteChapterMaterial(1, 'secret-token')).resolves.toBeUndefined()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'DELETE',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('exams (public browsing)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists exams', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listExams()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams`)
  })

  it('gets a single exam', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 4 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getExam(4)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4`)
  })
})

describe('exam attempts (authenticated)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('starts an attempt with a token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await startExamAttempt(4, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/attempts`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('saves an answer', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ question_id: 7, selected_option_ids: [1], saved_at: '2026-01-01' }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await saveExamAttemptAnswer(10, 7, [1, 2], 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exam-attempts/10/answers/7`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PUT',
      body: JSON.stringify({ selected_option_ids: [1, 2] }),
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })

  it('submits an attempt', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 10, status: 'submitted' } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await submitExamAttempt(10, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exam-attempts/10/submit`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})

describe('guest exam attempts', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('draws random questions with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await randomDrawExamQuestions(4)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/random-draw`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'POST' })
  })

  it('submits a guest attempt with no token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({ exam_id: 4, score: 2, total_possible_marks: 4, passed: null, answers: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const answers = [{ question_id: 1, selected_option_ids: [3] }]
    await submitGuestExamAttempt(4, answers)

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/exams/4/guest-submissions`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      body: JSON.stringify({ answers }),
    })
  })
})

describe('student profiles', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists student profiles filtered by user_id with a token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: {}, links: {} }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listStudentProfiles({ userId: 1, perPage: 1 }, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(
      `${import.meta.env.VITE_API_BASE_URL}/student-profiles?per_page=1&user_id=1`,
    )
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it("fetches a student's own dashboard rollup with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: { student_profile_id: 1, class_version_id: 10, academic_year_id: 2, subjects: [] },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await getStudentDashboard(1, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/student-profiles/1/dashboard`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })
})

describe('dashboard summary', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('fetches the management dashboard rollup with a required token', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: {
            schools_count: 2,
            teachers_count: null,
            students_count: null,
            attendance_today: null,
            attendance_trend: null,
            upcoming_holidays: null,
            recent_students: null,
          },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getDashboardSummary('tok')

    expect(result.data.schools_count).toBe(2)
    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/dashboard/summary`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })
})

describe('my account', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('fetches the current actor profile', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          data: { name: 'Jane', bn_name: null, email: 'jane@example.test', phone: null, role: 'school_admin', role_label: 'School Admin' },
        }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const result = await getMe('tok')

    expect(result.data.name).toBe('Jane')
    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/me`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it('updates the current actor profile', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({ data: { name: 'Jane Updated', bn_name: null, email: null, phone: null, role: null, role_label: null } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await updateMe({ name: 'Jane Updated' }, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/me`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'PATCH',
      headers: { Authorization: 'Bearer tok' },
      body: JSON.stringify({ name: 'Jane Updated' }),
    })
  })

  it('changes the current actor password', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await updateMyPassword({ current_password: 'old', password: 'new-password', password_confirmation: 'new-password' }, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/me/password`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ method: 'PATCH', headers: { Authorization: 'Bearer tok' } })
  })
})

describe('my exam attempts', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lists the current student's own exam attempts with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [], meta: {}, links: {} }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyExamAttempts({ perPage: 10 }, 'tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/my-exam-attempts?per_page=10`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })
})
