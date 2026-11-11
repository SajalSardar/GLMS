const API_BASE_URL = import.meta.env.VITE_API_BASE_URL

export interface HealthResponse {
  data: {
    status: string
    timestamp: string
  }
}

interface ErrorResponseBody {
  message?: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  status: number
  errors?: Record<string, string[]>

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, init)

  if (!response.ok) {
    let body: ErrorResponseBody | null = null

    try {
      body = (await response.json()) as ErrorResponseBody
    } catch {
      body = null
    }

    throw new ApiError(
      response.status,
      body?.message ?? `Request to ${path} failed with status ${response.status}`,
      body?.errors,
    )
  }

  if (response.status === 204) {
    return undefined as T
  }

  return response.json() as Promise<T>
}

export function getHealth(): Promise<HealthResponse> {
  return apiFetch<HealthResponse>('/health')
}

export interface PortalUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  role?: { id: number; name: string; label: string; permissions: string[] } | null
  [key: string]: unknown
}

export interface PortalLoginResponse {
  data: PortalUser
  token: string
}

function portalLogin(
  portal: 'student' | 'teacher' | 'management',
  login: string,
  password: string,
  deviceName?: string,
): Promise<PortalLoginResponse> {
  return apiFetch<PortalLoginResponse>(`/auth/${portal}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ login, password, device_name: deviceName }),
  })
}

export function studentLogin(
  login: string,
  password: string,
  deviceName?: string,
): Promise<PortalLoginResponse> {
  return portalLogin('student', login, password, deviceName)
}

export function managementLogin(
  login: string,
  password: string,
  deviceName?: string,
): Promise<PortalLoginResponse> {
  return portalLogin('management', login, password, deviceName)
}

// ---------- Geographic reference data (divisions / districts / upazilas) ----------

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PaginationMeta
}

export interface GeoRef {
  id: number
  name: string
  bn_name: string
}

export interface Division extends GeoRef {
  url: string | null
}

export interface District extends GeoRef {
  division_id: number
  lat: number | null
  lon: number | null
  url: string | null
  division?: GeoRef | null
}

export interface Upazila extends GeoRef {
  district_id: number
  url: string | null
  district?: GeoRef | null
}

export interface Subject {
  id: number
  name: string
  bn_name: string | null
  code: string | null
  description: string | null
}

export interface SubjectPayload {
  name: string
  bn_name?: string | null
  code?: string | null
  description?: string | null
}

export interface DivisionPayload {
  name: string
  bn_name: string
  url?: string | null
}

export interface DistrictPayload {
  division_id: number
  name: string
  bn_name: string
  lat?: number | null
  lon?: number | null
  url?: string | null
}

export interface UpazilaPayload {
  district_id: number
  name: string
  bn_name: string
  url?: string | null
}

function authHeaders(token: string): HeadersInit {
  return {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      query.set(key, String(value))
    }
  }

  const qs = query.toString()
  return qs ? `?${qs}` : ''
}

function listGeo<T>(
  resource: string,
  params: { page?: number; perPage?: number; search?: string; filterKey?: string; filterValue?: number },
): Promise<PaginatedResponse<T>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
  }

  if (params.filterKey && params.filterValue !== undefined) {
    query[params.filterKey] = params.filterValue
  }

  return apiFetch<PaginatedResponse<T>>(`/${resource}${buildQuery(query)}`)
}

function createGeo<T, P>(resource: string, payload: P, token: string): Promise<{ data: T }> {
  return apiFetch(`/${resource}`, {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

function updateGeo<T, P>(resource: string, id: number, payload: P, token: string): Promise<{ data: T }> {
  return apiFetch(`/${resource}/${id}`, {
    method: 'PATCH',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

function deleteGeo(resource: string, id: number, token: string): Promise<void> {
  return apiFetch(`/${resource}/${id}`, {
    method: 'DELETE',
    headers: authHeaders(token),
  })
}

export function listDivisions(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<Division>> {
  return listGeo<Division>('divisions', params)
}

export function createDivision(payload: DivisionPayload, token: string): Promise<{ data: Division }> {
  return createGeo<Division, DivisionPayload>('divisions', payload, token)
}

export function updateDivision(
  id: number,
  payload: Partial<DivisionPayload>,
  token: string,
): Promise<{ data: Division }> {
  return updateGeo<Division, Partial<DivisionPayload>>('divisions', id, payload, token)
}

export function deleteDivision(id: number, token: string): Promise<void> {
  return deleteGeo('divisions', id, token)
}

export function listSubjects(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<Subject>> {
  return listGeo<Subject>('subjects', params)
}

export function createSubject(payload: SubjectPayload, token: string): Promise<{ data: Subject }> {
  return createGeo<Subject, SubjectPayload>('subjects', payload, token)
}

export function updateSubject(
  id: number,
  payload: Partial<SubjectPayload>,
  token: string,
): Promise<{ data: Subject }> {
  return updateGeo<Subject, Partial<SubjectPayload>>('subjects', id, payload, token)
}

export function deleteSubject(id: number, token: string): Promise<void> {
  return deleteGeo('subjects', id, token)
}

export function listDistricts(
  params: { page?: number; perPage?: number; search?: string; divisionId?: number } = {},
): Promise<PaginatedResponse<District>> {
  return listGeo<District>('districts', {
    page: params.page,
    perPage: params.perPage,
    search: params.search,
    filterKey: 'division_id',
    filterValue: params.divisionId,
  })
}

export function createDistrict(payload: DistrictPayload, token: string): Promise<{ data: District }> {
  return createGeo<District, DistrictPayload>('districts', payload, token)
}

export function updateDistrict(
  id: number,
  payload: Partial<DistrictPayload>,
  token: string,
): Promise<{ data: District }> {
  return updateGeo<District, Partial<DistrictPayload>>('districts', id, payload, token)
}

export function deleteDistrict(id: number, token: string): Promise<void> {
  return deleteGeo('districts', id, token)
}

export function listUpazilas(
  params: { page?: number; perPage?: number; search?: string; districtId?: number } = {},
): Promise<PaginatedResponse<Upazila>> {
  return listGeo<Upazila>('upazilas', {
    page: params.page,
    perPage: params.perPage,
    search: params.search,
    filterKey: 'district_id',
    filterValue: params.districtId,
  })
}

export function createUpazila(payload: UpazilaPayload, token: string): Promise<{ data: Upazila }> {
  return createGeo<Upazila, UpazilaPayload>('upazilas', payload, token)
}

export function updateUpazila(
  id: number,
  payload: Partial<UpazilaPayload>,
  token: string,
): Promise<{ data: Upazila }> {
  return updateGeo<Upazila, Partial<UpazilaPayload>>('upazilas', id, payload, token)
}

export function deleteUpazila(id: number, token: string): Promise<void> {
  return deleteGeo('upazilas', id, token)
}

// ---------- Schools ----------

export type SchoolType = 'government' | 'non_government' | 'private'
export type SchoolLevel = 'primary' | 'secondary' | 'higher_secondary' | 'college'

export interface School {
  id: number
  division_id: number
  district_id: number
  upazila_id: number
  name: string
  bn_name: string | null
  eiin: string | null
  type: SchoolType
  level: SchoolLevel
  address: string | null
  phone: string | null
  email: string | null
  established_year: number | null
  logo_url: string | null
  division?: GeoRef | null
  district?: GeoRef | null
  upazila?: GeoRef | null
}

function multipartHeaders(token: string): HeadersInit {
  // No Content-Type here: the browser sets the multipart boundary itself
  // when the body is a FormData instance.
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}

export function listSchools(
  params: {
    page?: number
    perPage?: number
    search?: string
    divisionId?: number
    districtId?: number
    upazilaId?: number
    type?: SchoolType
    level?: SchoolLevel
  } = {},
): Promise<PaginatedResponse<School>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    division_id: params.divisionId,
    district_id: params.districtId,
    upazila_id: params.upazilaId,
    type: params.type,
    level: params.level,
  }

  return apiFetch<PaginatedResponse<School>>(`/schools${buildQuery(query)}`)
}

export function getSchool(id: number): Promise<{ data: School }> {
  return apiFetch<{ data: School }>(`/schools/${id}`)
}

export function createSchool(formData: FormData, token: string): Promise<{ data: School }> {
  return apiFetch('/schools', {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function updateSchool(id: number, formData: FormData, token: string): Promise<{ data: School }> {
  // Laravel can't parse a PATCH request with a multipart body, so this
  // spoofs the method the same way an HTML form would: POST with a
  // `_method` field, which Laravel's method-override support honors.
  formData.append('_method', 'PATCH')

  return apiFetch(`/schools/${id}`, {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function deleteSchool(id: number, token: string): Promise<void> {
  return deleteGeo('schools', id, token)
}

// ---------- School classes ----------

export interface SchoolClass {
  id: number
  school_id: number
  district_id: number
  name: string
  bn_name: string | null
  numeric_level: number | null
  section: string | null
  school?: School | null
  subjects?: Array<{ id: number; name: string; bn_name: string | null }> | null
}

export interface SchoolClassPayload {
  school_id: number
  name: string
  bn_name?: string | null
  numeric_level?: number | null
  section?: string | null
}

export function listClasses(
  params: { page?: number; perPage?: number; search?: string; schoolId?: number; districtId?: number } = {},
): Promise<PaginatedResponse<SchoolClass>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    school_id: params.schoolId,
    district_id: params.districtId,
  }

  return apiFetch<PaginatedResponse<SchoolClass>>(`/classes${buildQuery(query)}`)
}

export function createClass(payload: SchoolClassPayload, token: string): Promise<{ data: SchoolClass }> {
  return createGeo<SchoolClass, SchoolClassPayload>('classes', payload, token)
}

export function updateClass(
  id: number,
  payload: Partial<SchoolClassPayload>,
  token: string,
): Promise<{ data: SchoolClass }> {
  return updateGeo<SchoolClass, Partial<SchoolClassPayload>>('classes', id, payload, token)
}

export function deleteClass(id: number, token: string): Promise<void> {
  return deleteGeo('classes', id, token)
}

// ---------- Academic years ----------

export interface AcademicYear {
  id: number
  school_id: number
  district_id: number
  name: string
  start_date: string
  end_date: string
  is_current: boolean
  description: string | null
  school?: School | null
}

export interface AcademicYearPayload {
  school_id: number
  name: string
  start_date: string
  end_date: string
  is_current?: boolean
  description?: string | null
}

export function listAcademicYears(
  params: { page?: number; perPage?: number; search?: string; schoolId?: number; districtId?: number } = {},
): Promise<PaginatedResponse<AcademicYear>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    school_id: params.schoolId,
    district_id: params.districtId,
  }

  return apiFetch<PaginatedResponse<AcademicYear>>(`/academic-years${buildQuery(query)}`)
}

export function createAcademicYear(
  payload: AcademicYearPayload,
  token: string,
): Promise<{ data: AcademicYear }> {
  return createGeo<AcademicYear, AcademicYearPayload>('academic-years', payload, token)
}

export function updateAcademicYear(
  id: number,
  payload: Partial<AcademicYearPayload>,
  token: string,
): Promise<{ data: AcademicYear }> {
  return updateGeo<AcademicYear, Partial<AcademicYearPayload>>('academic-years', id, payload, token)
}

export function deleteAcademicYear(id: number, token: string): Promise<void> {
  return deleteGeo('academic-years', id, token)
}

// ---------- Class versions ----------

export interface ClassVersion {
  id: number
  class_id: number
  school_id: number
  district_id: number
  name: string
  bn_name: string | null
  is_active: boolean
  class?: SchoolClass | null
}

export interface ClassVersionPayload {
  class_id: number
  name: string
  bn_name?: string | null
  is_active?: boolean
}

export function listClassVersions(
  params: { page?: number; perPage?: number; search?: string; classId?: number; schoolId?: number } = {},
): Promise<PaginatedResponse<ClassVersion>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    class_id: params.classId,
    school_id: params.schoolId,
  }

  return apiFetch<PaginatedResponse<ClassVersion>>(`/class-versions${buildQuery(query)}`)
}

export function createClassVersion(
  payload: ClassVersionPayload,
  token: string,
): Promise<{ data: ClassVersion }> {
  return createGeo<ClassVersion, ClassVersionPayload>('class-versions', payload, token)
}

export function updateClassVersion(
  id: number,
  payload: Partial<ClassVersionPayload>,
  token: string,
): Promise<{ data: ClassVersion }> {
  return updateGeo<ClassVersion, Partial<ClassVersionPayload>>('class-versions', id, payload, token)
}

export function deleteClassVersion(id: number, token: string): Promise<void> {
  return deleteGeo('class-versions', id, token)
}

// ---------- Shifts ----------

export const DAYS_OF_WEEK = [
  'saturday',
  'sunday',
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
] as const

export type DayOfWeek = (typeof DAYS_OF_WEEK)[number]

export interface Shift {
  id: number
  class_version_id: number
  school_id: number
  district_id: number
  academic_year_id: number | null
  name: string
  bn_name: string | null
  start_time: string
  end_time: string
  days_of_week: DayOfWeek[]
  period_duration_minutes: number | null
  is_active: boolean
  class_version?: ClassVersion | null
}

export interface ShiftPayload {
  class_version_id: number
  name: string
  bn_name?: string | null
  start_time: string
  end_time: string
  days_of_week: DayOfWeek[]
  period_duration_minutes?: number | null
  academic_year_id?: number | null
  is_active?: boolean
}

export function listShifts(
  params: { page?: number; perPage?: number; search?: string; classVersionId?: number; schoolId?: number } = {},
): Promise<PaginatedResponse<Shift>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    class_version_id: params.classVersionId,
    school_id: params.schoolId,
  }

  return apiFetch<PaginatedResponse<Shift>>(`/shifts${buildQuery(query)}`)
}

export function createShift(payload: ShiftPayload, token: string): Promise<{ data: Shift }> {
  return createGeo<Shift, ShiftPayload>('shifts', payload, token)
}

export function updateShift(
  id: number,
  payload: Partial<ShiftPayload>,
  token: string,
): Promise<{ data: Shift }> {
  return updateGeo<Shift, Partial<ShiftPayload>>('shifts', id, payload, token)
}

export function deleteShift(id: number, token: string): Promise<void> {
  return deleteGeo('shifts', id, token)
}

// ---------- Sections ----------

export interface Section {
  id: number
  shift_id: number
  school_id: number
  district_id: number
  name: string
  bn_name: string | null
  code: string | null
  capacity: number | null
  class_teacher_id: number | null
  default_classroom_id: number | null
  is_active: boolean
  shift?: Shift | null
}

export interface SectionPayload {
  shift_id: number
  name: string
  bn_name?: string | null
  code?: string | null
  capacity?: number | null
  is_active?: boolean
}

export function listSections(
  params: { page?: number; perPage?: number; search?: string; shiftId?: number; schoolId?: number } = {},
): Promise<PaginatedResponse<Section>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    shift_id: params.shiftId,
    school_id: params.schoolId,
  }

  return apiFetch<PaginatedResponse<Section>>(`/sections${buildQuery(query)}`)
}

export function createSection(payload: SectionPayload, token: string): Promise<{ data: Section }> {
  return createGeo<Section, SectionPayload>('sections', payload, token)
}

export function updateSection(
  id: number,
  payload: Partial<SectionPayload>,
  token: string,
): Promise<{ data: Section }> {
  return updateGeo<Section, Partial<SectionPayload>>('sections', id, payload, token)
}

export function deleteSection(id: number, token: string): Promise<void> {
  return deleteGeo('sections', id, token)
}
