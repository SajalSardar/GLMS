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
  // Present only for a school-scoped management actor (e.g. school_admin);
  // null for a national-level actor who has none.
  school?: { id: number; name: string; bn_name: string | null } | null
  [key: string]: unknown
}

export interface PortalLoginResponse {
  data: PortalUser
  token: string
}

function portalLogin(
  portal: 'student' | 'management' | 'teacher',
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

export function teacherLogin(
  login: string,
  password: string,
  deviceName?: string,
): Promise<PortalLoginResponse> {
  return portalLogin('teacher', login, password, deviceName)
}

// Revokes the token server-side (AuthController::logout() deletes the
// Sanctum PersonalAccessToken used to authenticate this request) — must be
// called before the caller clears its locally stored token, otherwise the
// token stays valid indefinitely (config/sanctum.php sets no expiration).
export function logout(token: string): Promise<void> {
  return apiFetch<void>('/auth/logout', {
    method: 'POST',
    headers: authHeaders(token),
  })
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
  name: string
  bn_name: string | null
  numeric_level: number | null
  subjects?: Array<{ id: number; name: string; bn_name: string | null }> | null
}

export interface SchoolClassPayload {
  name: string
  bn_name?: string | null
  numeric_level?: number | null
}

export function listClasses(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<SchoolClass>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
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

// ---------- Class schools ----------

export interface ClassSchool {
  id: number
  class_id: number
  school_id: number
  district_id: number
  class?: SchoolClass | null
  school?: School | null
  subjects?: Subject[] | null
}

export interface ClassSchoolPayload {
  class_id: number
  school_id: number
}

export function listClassSchools(
  params: { page?: number; perPage?: number; schoolId?: number; classId?: number } = {},
): Promise<PaginatedResponse<ClassSchool>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    school_id: params.schoolId,
    class_id: params.classId,
  }

  return apiFetch<PaginatedResponse<ClassSchool>>(`/class-schools${buildQuery(query)}`)
}

export function createClassSchool(payload: ClassSchoolPayload, token: string): Promise<{ data: ClassSchool }> {
  return createGeo<ClassSchool, ClassSchoolPayload>('class-schools', payload, token)
}

export function deleteClassSchool(id: number, token: string): Promise<void> {
  return deleteGeo('class-schools', id, token)
}

export function updateClassSchoolSubjects(
  id: number,
  subjectIds: number[],
  token: string,
): Promise<{ data: ClassSchool }> {
  return apiFetch<{ data: ClassSchool }>(`/class-schools/${id}/subjects`, {
    method: 'PUT',
    headers: authHeaders(token),
    body: JSON.stringify({ subject_ids: subjectIds }),
  })
}

// ---------- Academic years ----------

export interface AcademicYear {
  id: number
  name: string
  start_date: string
  end_date: string
  is_current: boolean
  description: string | null
}

export interface AcademicYearPayload {
  name: string
  start_date: string
  end_date: string
  is_current?: boolean
  description?: string | null
}

export function listAcademicYears(
  params: {
    page?: number
    perPage?: number
    search?: string
    currentOnly?: boolean
  } = {},
): Promise<PaginatedResponse<AcademicYear>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    current_only: params.currentOnly ? 'true' : undefined,
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

// ---------- Holidays ----------

export type HolidayType = 'weekly' | 'government' | 'other'

export interface Holiday {
  id: number
  academic_year_id: number | null
  type: HolidayType
  // Set only for type 'weekly' (e.g. 'friday') — a recurring holiday
  // with no specific date, applying every week indefinitely.
  day_of_week: string | null
  // Set only for type 'government'/'other' — a single day sets
  // start_date = end_date; a multi-day holiday spans the range.
  start_date: string | null
  end_date: string | null
  name: string
  bn_name: string | null
  description: string | null
  is_active: boolean
  academic_year?: AcademicYear | null
}

export interface HolidayPayload {
  academic_year_id?: number | null
  type: HolidayType
  day_of_week?: string | null
  start_date?: string | null
  end_date?: string | null
  name: string
  bn_name?: string | null
  description?: string | null
  is_active?: boolean
}

export function listHolidays(
  params: {
    page?: number
    perPage?: number
    academicYearId?: number
    type?: HolidayType
    activeOnly?: boolean
  } = {},
): Promise<PaginatedResponse<Holiday>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    academic_year_id: params.academicYearId,
    type: params.type,
    active_only: params.activeOnly ? 'true' : undefined,
  }

  return apiFetch<PaginatedResponse<Holiday>>(`/holidays${buildQuery(query)}`)
}

export function createHoliday(payload: HolidayPayload, token: string): Promise<{ data: Holiday }> {
  return createGeo<Holiday, HolidayPayload>('holidays', payload, token)
}

export function updateHoliday(
  id: number,
  payload: Partial<HolidayPayload>,
  token: string,
): Promise<{ data: Holiday }> {
  return updateGeo<Holiday, Partial<HolidayPayload>>('holidays', id, payload, token)
}

export function deleteHoliday(id: number, token: string): Promise<void> {
  return deleteGeo('holidays', id, token)
}

// ---------- Versions ----------

export interface Version {
  id: number
  name: string
  bn_name: string | null
  is_active: boolean
}

export interface VersionPayload {
  name?: string
  bn_name?: string | null
  is_active?: boolean
}

export function listVersions(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<Version>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
  }

  return apiFetch<PaginatedResponse<Version>>(`/versions${buildQuery(query)}`)
}

export function updateVersion(id: number, payload: VersionPayload, token: string): Promise<{ data: Version }> {
  return updateGeo<Version, VersionPayload>('versions', id, payload, token)
}

// ---------- Class versions ----------

export interface ClassVersion {
  id: number
  class_id: number
  school_id: number
  district_id: number
  version_id: number
  name: string
  bn_name: string | null
  is_active: boolean
  class?: SchoolClass | null
  subjects?: Subject[] | null
}

export interface ClassVersionPayload {
  class_id: number
  school_id: number
  version_id: number
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

// ---------- Books ----------

export interface Book {
  id: number
  class_version_id: number
  subject_id: number
  academic_year_id: number | null
  name: string
  bn_name: string | null
  order_index: number | null
  description: string | null
  is_active: boolean
  pdf_original_filename: string | null
  pdf_mime_type: string | null
  pdf_size_bytes: number | null
  pdf_uploaded_at: string | null
  pdf_status: string | null
  has_available_pdf: boolean
  class_version?: ClassVersion | null
  subject?: Subject | null
  academic_year?: AcademicYear | null
  chapters?: Chapter[] | null
}

export function listBooks(
  params: { classVersionId?: number; subjectId?: number; academicYearId?: number } = {},
): Promise<{ data: Book[] }> {
  const query: Record<string, string | number | undefined> = {
    class_version_id: params.classVersionId,
    subject_id: params.subjectId,
    academic_year_id: params.academicYearId,
  }

  return apiFetch<{ data: Book[] }>(`/books${buildQuery(query)}`)
}

export function getBook(id: number): Promise<{ data: Book }> {
  return apiFetch<{ data: Book }>(`/books/${id}`)
}

export interface BookPayload {
  class_version_id: number
  subject_id: number
  academic_year_id: number
  name: string
  bn_name?: string | null
  order_index?: number | null
  description?: string | null
  is_active?: boolean
}

// class_version_id/subject_id/academic_year_id are immutable after
// creation (UpdateBookRequest doesn't accept them), so the update payload
// deliberately excludes them.
export type BookUpdatePayload = Partial<
  Omit<BookPayload, 'class_version_id' | 'subject_id' | 'academic_year_id'>
>

export function createBook(payload: BookPayload, token: string): Promise<{ data: Book }> {
  return createGeo<Book, BookPayload>('books', payload, token)
}

export function updateBook(id: number, payload: BookUpdatePayload, token: string): Promise<{ data: Book }> {
  return updateGeo<Book, BookUpdatePayload>('books', id, payload, token)
}

export function deleteBook(id: number, token: string): Promise<void> {
  return deleteGeo('books', id, token)
}

export interface BookPdfLinks {
  download_url: string
  preview_url: string
}

export function getBookPdfLinks(bookId: number): Promise<BookPdfLinks> {
  return apiFetch<BookPdfLinks>(`/books/${bookId}/pdf/download-link`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })
}

// ---------- Chapters ----------

export interface Chapter {
  id: number
  book_id: number
  name: string
  bn_name: string | null
  order_index: number | null
  chapter_number: number | null
  bn_chapter_number: string | null
  description: string | null
  book?: Book | null
}

export function listChapters(params: { bookId?: number } = {}): Promise<{ data: Chapter[] }> {
  const query: Record<string, string | number | undefined> = { book_id: params.bookId }

  return apiFetch<{ data: Chapter[] }>(`/chapters${buildQuery(query)}`)
}

export interface ChapterPayload {
  book_id: number
  name: string
  bn_name?: string | null
  order_index?: number | null
  chapter_number?: string | null
  bn_chapter_number?: string | null
  description?: string | null
}

// book_id is immutable after creation (UpdateChapterRequest doesn't accept
// it), so the update payload deliberately excludes it.
export type ChapterUpdatePayload = Partial<Omit<ChapterPayload, 'book_id'>>

export function createChapter(payload: ChapterPayload, token: string): Promise<{ data: Chapter }> {
  return createGeo<Chapter, ChapterPayload>('chapters', payload, token)
}

export function updateChapter(
  id: number,
  payload: ChapterUpdatePayload,
  token: string,
): Promise<{ data: Chapter }> {
  return updateGeo<Chapter, ChapterUpdatePayload>('chapters', id, payload, token)
}

export function deleteChapter(id: number, token: string): Promise<void> {
  return deleteGeo('chapters', id, token)
}

// ---------- Chapter videos ----------

export interface ChapterVideo {
  id: number
  chapter_id: number
  youtube_video_id: string
  embed_url: string
  title: string
  bn_title: string | null
  description: string | null
  instructor_name: string | null
  duration_seconds: number | null
  learning_objectives: string | null
  completion_threshold_percent: number | null
  order_index: number | null
  is_enabled: boolean
  chapter?: Chapter | null
}

export function listChapterVideos(
  params: { chapterId?: number } = {},
): Promise<{ data: ChapterVideo[] }> {
  const query: Record<string, string | number | undefined> = { chapter_id: params.chapterId }

  return apiFetch<{ data: ChapterVideo[] }>(`/chapter-videos${buildQuery(query)}`)
}

export interface ChapterVideoPayload {
  chapter_id: number
  // A full YouTube URL — the backend extracts and stores the video ID.
  youtube_url: string
  title: string
  bn_title?: string | null
  description?: string | null
  instructor_name?: string | null
  duration_seconds?: number | null
  learning_objectives?: string | null
  completion_threshold_percent?: number | null
  order_index?: number
  is_enabled?: boolean
}

// chapter_id is immutable after creation (UpdateChapterVideoRequest
// doesn't accept it), so the update payload deliberately excludes it.
export type ChapterVideoUpdatePayload = Partial<Omit<ChapterVideoPayload, 'chapter_id'>>

export function createChapterVideo(
  payload: ChapterVideoPayload,
  token: string,
): Promise<{ data: ChapterVideo }> {
  return createGeo<ChapterVideo, ChapterVideoPayload>('chapter-videos', payload, token)
}

export function updateChapterVideo(
  id: number,
  payload: ChapterVideoUpdatePayload,
  token: string,
): Promise<{ data: ChapterVideo }> {
  return updateGeo<ChapterVideo, ChapterVideoUpdatePayload>('chapter-videos', id, payload, token)
}

export function deleteChapterVideo(id: number, token: string): Promise<void> {
  return deleteGeo('chapter-videos', id, token)
}

// ---------- Chapter materials ----------

export interface ChapterMaterial {
  id: number
  chapter_id: number
  // Set when the uploader is a teacher assigned (via
  // SubjectTeacherAssignment) to teach this chapter's subject in this
  // section — null means this material is visible to the whole class
  // version, not restricted to one section (e.g. staff-authored).
  school_id: number | null
  district_id: number | null
  shift_id: number | null
  class_version_id: number | null
  class_id: number | null
  section_id: number | null
  type: string
  title: string
  original_filename: string | null
  mime_type: string | null
  size_bytes: number | null
  order_index: number | null
  status: string
  created_at: string
  chapter?: Chapter | null
}

export function listChapterMaterials(
  params: { chapterId?: number } = {},
): Promise<{ data: ChapterMaterial[] }> {
  const query: Record<string, string | number | undefined> = { chapter_id: params.chapterId }

  return apiFetch<{ data: ChapterMaterial[] }>(`/chapter-materials${buildQuery(query)}`)
}

// The current student's own materials — scoped server-side to their
// exact school/district/shift/section/class/class_version tuple (see
// ChapterMaterialController::mine()), so e.g. DashboardPage.tsx can show
// "new materials for your class" without fetching every chapter's
// materials and filtering client-side.
export function listMyChapterMaterials(token: string): Promise<{ data: ChapterMaterial[] }> {
  return apiFetch<{ data: ChapterMaterial[] }>('/chapter-materials/mine', {
    headers: authHeaders(token),
  })
}

export interface ChapterMaterialLinks {
  download_url: string
  preview_url: string
}

// Unlike getBookPdfLinks(), this route sits behind auth:sanctum on the
// backend (ChapterMaterialController's download-link route + its own
// ChapterMaterialPolicy::view() check) — a guest token is never accepted,
// so token is required here, not optional.
export function getChapterMaterialDownloadLink(materialId: number, token: string): Promise<ChapterMaterialLinks> {
  return apiFetch<ChapterMaterialLinks>(`/chapter-materials/${materialId}/download-link`, {
    method: 'POST',
    headers: authHeaders(token),
  })
}

export interface ChapterMaterialAssignment {
  section_id: number
  section_name: string
  subject_id: number
  subject_name: string
  class_id: number | null
  class_name: string | null
  class_version_id: number | null
  class_version: string | null
  shift_id: number | null
  shift: string | null
  academic_year_id: number | null
  academic_year: string | null
}

// Every (section, subject) the logged-in teacher is explicitly assigned
// to teach — the Section→Subject picker's data source on the teacher
// portal's Class Materials page (see ChapterMaterialController::
// myAssignments()).
export function listMyChapterMaterialAssignments(token: string): Promise<{ data: ChapterMaterialAssignment[] }> {
  return apiFetch<{ data: ChapterMaterialAssignment[] }>('/chapter-materials/my-assignments', {
    headers: authHeaders(token),
  })
}

// Every material the logged-in teacher has personally uploaded, across
// every chapter/section — feeds the "my uploads" list on the same page.
export function listMyUploadedChapterMaterials(token: string): Promise<{ data: ChapterMaterial[] }> {
  return apiFetch<{ data: ChapterMaterial[] }>('/chapter-materials/my-uploads', {
    headers: authHeaders(token),
  })
}

export function createChapterMaterial(formData: FormData, token: string): Promise<{ data: ChapterMaterial }> {
  return apiFetch('/chapter-materials', {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function updateChapterMaterial(id: number, formData: FormData, token: string): Promise<{ data: ChapterMaterial }> {
  // Laravel can't parse a PATCH request with a multipart body — same
  // _method spoof as updateSchool() above.
  formData.append('_method', 'PATCH')

  return apiFetch(`/chapter-materials/${id}`, {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function deleteChapterMaterial(id: number, token: string): Promise<void> {
  return deleteGeo('chapter-materials', id, token)
}

// ---------- Class routines ----------

export interface ClassRoutine {
  id: number
  school_id: number
  district_id: number
  class_id: number
  // Set only once a routine entry has been scoped to a specific section
  // (see ClassRoutineController's section_id denormalization) — null for
  // the legacy class_id-only rows that predate the Version/Shift/Section
  // structure.
  section_id: number | null
  shift_id: number | null
  class_version_id: number | null
  subject_id: number
  teacher_id: number | null
  classroom_id: number | null
  academic_year_id: number | null
  day_of_week: string
  period_no: number
  start_time: string | null
  end_time: string | null
  subject?: Subject | null
  teacher?: { id: number; name: string } | null
  classroom?: { id: number; name: string } | null
  class?: { id: number; name: string; bn_name: string | null; numeric_level: number | null } | null
  academic_year?: { id: number; name: string } | null
  section?: { id: number; name: string; bn_name: string | null } | null
  shift?: { id: number; name: string; bn_name: string | null } | null
  class_version?: { id: number; name: string; bn_name: string | null } | null
}

// The current student's own weekly class routine — scoped server-side to
// their exact school/district/class/section/shift/class_version tuple
// (see ClassRoutineController::mine()), already ordered by day-of-week
// then period.
export function listMyClassRoutines(token: string): Promise<{ data: ClassRoutine[] }> {
  return apiFetch<{ data: ClassRoutine[] }>('/class-routines/mine', {
    headers: authHeaders(token),
  })
}

// Arbitrary-actor class routine lookup (ClassRoutineController::index()) —
// public, same tier as /schools or /classes, no token required. Used by
// the Management Portal's Teacher/Class Routine pages to look up any
// teacher's or any section's routine, unlike listMyClassRoutines() above
// which is always the CALLER's own.
export function listClassRoutines(params: {
  schoolId?: number
  districtId?: number
  classId?: number
  classVersionId?: number
  shiftId?: number
  sectionId?: number
  teacherId?: number
  academicYearId?: number
  dayOfWeek?: string
}): Promise<{ data: ClassRoutine[] }> {
  const query = buildQuery({
    school_id: params.schoolId,
    district_id: params.districtId,
    class_id: params.classId,
    class_version_id: params.classVersionId,
    shift_id: params.shiftId,
    section_id: params.sectionId,
    teacher_id: params.teacherId,
    academic_year_id: params.academicYearId,
    day_of_week: params.dayOfWeek,
  })

  return apiFetch<{ data: ClassRoutine[] }>(`/class-routines${query}`)
}

// ---------- Exams (public browsing + attempts) ----------

export interface ExamQuestionOption {
  id: number
  option_text: string
  is_correct: boolean | null
}

export interface ExamQuestion {
  id: number
  question_type: string
  question_text: string
  bn_question_text: string | null
  marks: number
  order_index: number
  options: ExamQuestionOption[]
}

// The `exam_questions` pivot row wrapping a question within an Exam's
// manual/fixed_random question list — ExamQuestionResource.php serializes
// Exam.questions this way: `{ id: <pivot id, NOT the question id>,
// order_index: <pivot order>, question: <the actual question> }`. Confirmed
// against the live backend; `randomDrawExamQuestions()` and a tracked
// ExamAttempt's own `questions` field are NOT affected by this — both of
// those return the flat `ExamQuestion` shape directly.
export interface ExamQuestionLink {
  id: number
  order_index: number
  question: ExamQuestion
}

export interface Exam {
  id: number
  class_version_id: number
  subject_id: number
  academic_year_id: number
  name: string
  bn_name: string | null
  selection_mode: 'manual' | 'fixed_random' | 'per_attempt_random'
  status: string
  is_practice: boolean
  available_from: string | null
  available_until: string | null
  time_limit_minutes: number | null
  random_question_count: number | null
  marks_per_question: number | null
  passing_score_percent: number | null
  max_attempts: number | null
  subject?: Subject | null
  questions?: ExamQuestionLink[] | null
}

export function listExams(): Promise<{ data: Exam[] }> {
  return apiFetch<{ data: Exam[] }>('/exams')
}

export function getExam(id: number): Promise<{ data: Exam }> {
  return apiFetch<{ data: Exam }>(`/exams/${id}`)
}

export interface PracticeExamConfig {
  single_chapter_question_count: number
  multi_chapter_question_count: number
  tier_pass_percent: number
  tier_good_percent: number
  tier_best_percent: number
}

export function getPracticeExamConfig(): Promise<PracticeExamConfig> {
  return apiFetch<PracticeExamConfig>('/practice-exams/config')
}

export function createPracticeExam(
  payload: { subjectId: number; chapterIds: number[] },
  token?: string,
): Promise<{ data: { id: number } }> {
  return apiFetch<{ data: { id: number } }>('/practice-exams', {
    method: 'POST',
    headers: token ? authHeaders(token) : { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ subject_id: payload.subjectId, chapter_ids: payload.chapterIds }),
  })
}

export interface ExamAttemptQuestionResult {
  question_id: number
  question_type: string
  question_text: string
  bn_question_text: string | null
  marks: number
  order_index: number
  options: Array<{ id: number; option_text: string; is_correct: boolean | null }>
  selected_option_ids: number[] | null
  is_correct: boolean | null
  marks_awarded: number | null
}

export interface ExamAttempt {
  id: number
  exam_id: number
  user_id: number
  attempt_number: number
  status: 'in_progress' | 'submitted'
  started_at: string
  submitted_at: string | null
  score: number | null
  total_possible_marks: number | null
  passed: boolean | null
  questions?: ExamAttemptQuestionResult[] | null
  exam?: Exam | null
}

export function startExamAttempt(examId: number, token: string): Promise<{ data: ExamAttempt }> {
  return apiFetch<{ data: ExamAttempt }>(`/exams/${examId}/attempts`, {
    method: 'POST',
    headers: authHeaders(token),
  })
}

// The current student's own submitted practice-exam attempts (score
// history) — self-scoped on the backend, so no exam id/permission is
// needed beyond being logged in as a student.
export function listMyExamAttempts(
  params: { page?: number; perPage?: number } = {},
  token: string,
): Promise<PaginatedResponse<ExamAttempt>> {
  const query: Record<string, string | number | undefined> = { page: params.page, per_page: params.perPage }

  return apiFetch<PaginatedResponse<ExamAttempt>>(`/my-exam-attempts${buildQuery(query)}`, {
    headers: authHeaders(token),
  })
}

export function saveExamAttemptAnswer(
  attemptId: number,
  questionId: number,
  selectedOptionIds: number[],
  token: string,
): Promise<{ question_id: number; selected_option_ids: number[]; saved_at: string }> {
  return apiFetch(`/exam-attempts/${attemptId}/answers/${questionId}`, {
    method: 'PUT',
    headers: authHeaders(token),
    body: JSON.stringify({ selected_option_ids: selectedOptionIds }),
  })
}

export function submitExamAttempt(attemptId: number, token: string): Promise<{ data: ExamAttempt }> {
  return apiFetch<{ data: ExamAttempt }>(`/exam-attempts/${attemptId}/submit`, {
    method: 'POST',
    headers: authHeaders(token),
  })
}

export function randomDrawExamQuestions(examId: number): Promise<{ data: ExamQuestion[] }> {
  return apiFetch<{ data: ExamQuestion[] }>(`/exams/${examId}/random-draw`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })
}

export interface GuestExamAnswerInput {
  question_id: number
  selected_option_ids: number[]
}

export interface GuestExamAnswerResult extends GuestExamAnswerInput {
  is_correct: boolean
  marks_awarded: number
  marks: number
  correct_option_ids: number[]
}

export interface GuestExamSubmissionResult {
  exam_id: number
  score: number
  total_possible_marks: number
  passed: boolean | null
  answers: GuestExamAnswerResult[]
}

export function submitGuestExamAttempt(
  examId: number,
  answers: GuestExamAnswerInput[],
): Promise<GuestExamSubmissionResult> {
  return apiFetch<GuestExamSubmissionResult>(`/exams/${examId}/guest-submissions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ answers }),
  })
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
  params: {
    page?: number
    perPage?: number
    search?: string
    classVersionId?: number
    schoolId?: number
    classId?: number
  } = {},
): Promise<PaginatedResponse<Shift>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    class_version_id: params.classVersionId,
    school_id: params.schoolId,
    class_id: params.classId,
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
  params: {
    page?: number
    perPage?: number
    search?: string
    shiftId?: number
    schoolId?: number
    classId?: number
  } = {},
): Promise<PaginatedResponse<Section>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    shift_id: params.shiftId,
    school_id: params.schoolId,
    class_id: params.classId,
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

// ---------- Teacher users ----------

export interface TeacherUser {
  id: number
  name: string
  bn_name: string | null
  email: string | null
  phone: string | null
  school?: School | null
  district?: District | null
}

export interface TeacherUserPayload {
  name: string
  bn_name?: string | null
  email: string
  phone?: string | null
  password?: string
  school_id?: number | null
  district_id?: number | null
}

export function listTeacherUsers(
  params: { page?: number; perPage?: number; search?: string; schoolId?: number; districtId?: number } = {},
  token?: string,
): Promise<PaginatedResponse<TeacherUser>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    school_id: params.schoolId,
    district_id: params.districtId,
  }

  // Unlike the geographic/academic reference lists above, /teacher-users
  // requires authentication (it's registered inside the auth:sanctum
  // group), so a bearer token must be sent when the caller has one.
  return apiFetch<PaginatedResponse<TeacherUser>>(`/teacher-users${buildQuery(query)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function getTeacherUser(id: number, token: string): Promise<{ data: TeacherUser }> {
  return apiFetch<{ data: TeacherUser }>(`/teacher-users/${id}`, {
    headers: authHeaders(token),
  })
}

export function createTeacherUser(payload: TeacherUserPayload, token: string): Promise<{ data: TeacherUser }> {
  return createGeo<TeacherUser, TeacherUserPayload>('teacher-users', payload, token)
}

export function updateTeacherUser(
  id: number,
  payload: Partial<TeacherUserPayload>,
  token: string,
): Promise<{ data: TeacherUser }> {
  return updateGeo<TeacherUser, Partial<TeacherUserPayload>>('teacher-users', id, payload, token)
}

export function deleteTeacherUser(id: number, token: string): Promise<void> {
  return deleteGeo('teacher-users', id, token)
}

// ---------- Teacher profiles ----------

export interface TeacherProfile {
  id: number
  user_id: number
  school_id: number
  district_id: number
  employee_id: string | null
  designation: string | null
  subject_specialization: string | null
  qualification: string | null
  joining_date: string | null
  nid_number: string | null
  phone: string | null
  blood_group: string | null
  present_address: string | null
  permanent_address: string | null
  photo_path: string | null
}

export interface TeacherProfilePayload {
  user_id: number
  employee_id?: string | null
  designation?: string | null
  subject_specialization?: string | null
  qualification?: string | null
  joining_date?: string | null
  nid_number?: string | null
  phone?: string | null
  blood_group?: string | null
  present_address?: string | null
  permanent_address?: string | null
  photo_path?: string | null
}

export function listTeacherProfiles(
  params: { page?: number; perPage?: number; userId?: number; schoolId?: number; districtId?: number } = {},
  token?: string,
): Promise<PaginatedResponse<TeacherProfile>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    user_id: params.userId,
    school_id: params.schoolId,
    district_id: params.districtId,
  }

  return apiFetch<PaginatedResponse<TeacherProfile>>(`/teacher-profiles${buildQuery(query)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function createTeacherProfile(payload: TeacherProfilePayload, token: string): Promise<{ data: TeacherProfile }> {
  return createGeo<TeacherProfile, TeacherProfilePayload>('teacher-profiles', payload, token)
}

export function updateTeacherProfile(
  id: number,
  payload: Partial<Omit<TeacherProfilePayload, 'user_id'>>,
  token: string,
): Promise<{ data: TeacherProfile }> {
  return updateGeo<TeacherProfile, Partial<Omit<TeacherProfilePayload, 'user_id'>>>('teacher-profiles', id, payload, token)
}

// ---------- Subject-teacher assignments ----------

export interface SubjectTeacherAssignment {
  id: number
  section_id: number
  subject_id: number
  teacher_id: number
  school_id: number
  district_id: number
  section?: Section | null
  subject?: Subject | null
  teacher?: { id: number; name: string } | null
}

export interface SubjectTeacherAssignmentPayload {
  section_id: number
  subject_id: number
  teacher_id: number
}

export function listSubjectTeacherAssignments(
  params: {
    page?: number
    perPage?: number
    sectionId?: number
    subjectId?: number
    teacherId?: number
    schoolId?: number
    classId?: number
  } = {},
): Promise<PaginatedResponse<SubjectTeacherAssignment>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    section_id: params.sectionId,
    subject_id: params.subjectId,
    teacher_id: params.teacherId,
    school_id: params.schoolId,
    class_id: params.classId,
  }

  return apiFetch<PaginatedResponse<SubjectTeacherAssignment>>(`/subject-teacher-assignments${buildQuery(query)}`)
}

export function createSubjectTeacherAssignment(
  payload: SubjectTeacherAssignmentPayload,
  token: string,
): Promise<{ data: SubjectTeacherAssignment }> {
  return createGeo<SubjectTeacherAssignment, SubjectTeacherAssignmentPayload>(
    'subject-teacher-assignments',
    payload,
    token,
  )
}

export function updateSubjectTeacherAssignment(
  id: number,
  payload: Pick<SubjectTeacherAssignmentPayload, 'teacher_id'>,
  token: string,
): Promise<{ data: SubjectTeacherAssignment }> {
  return updateGeo<SubjectTeacherAssignment, Pick<SubjectTeacherAssignmentPayload, 'teacher_id'>>(
    'subject-teacher-assignments',
    id,
    payload,
    token,
  )
}

export function deleteSubjectTeacherAssignment(id: number, token: string): Promise<void> {
  return deleteGeo('subject-teacher-assignments', id, token)
}

// ---------- Student users ----------

export interface StudentUser {
  id: number
  name: string
  bn_name: string | null
  email: string | null
  phone: string | null
  school?: School | null
  district?: District | null
  class?: SchoolClass | null
}

export interface StudentUserPayload {
  name: string
  bn_name?: string | null
  email?: string | null
  phone?: string | null
  password?: string
  school_id?: number | null
  district_id?: number | null
}

export function listStudentUsers(
  params: {
    page?: number
    perPage?: number
    search?: string
    schoolId?: number
    districtId?: number
    classId?: number
  } = {},
  token?: string,
): Promise<PaginatedResponse<StudentUser>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
    school_id: params.schoolId,
    district_id: params.districtId,
    class_id: params.classId,
  }

  return apiFetch<PaginatedResponse<StudentUser>>(`/student-users${buildQuery(query)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function getStudentUser(id: number, token: string): Promise<{ data: StudentUser }> {
  return apiFetch<{ data: StudentUser }>(`/student-users/${id}`, {
    headers: authHeaders(token),
  })
}

export function createStudentUser(payload: StudentUserPayload, token: string): Promise<{ data: StudentUser }> {
  return createGeo<StudentUser, StudentUserPayload>('student-users', payload, token)
}

export function updateStudentUser(
  id: number,
  payload: Partial<StudentUserPayload>,
  token: string,
): Promise<{ data: StudentUser }> {
  return updateGeo<StudentUser, Partial<StudentUserPayload>>('student-users', id, payload, token)
}

export function deleteStudentUser(id: number, token: string): Promise<void> {
  return deleteGeo('student-users', id, token)
}

// ---------- Guardians (contact/profile records — distinct from guardian-user logins) ----------

export type GuardianRelation = 'father' | 'mother' | 'guardian' | 'other'

export interface Guardian {
  id: number
  user_id: number | null
  name: string
  bn_name: string | null
  phone: string
  email: string | null
  nid_number: string | null
  occupation: string | null
  present_address: string | null
  // Only present on a guardian returned nested inside a StudentProfile's
  // `guardians` list — the pivot's own columns, flattened onto the record.
  relation?: GuardianRelation
  is_primary?: boolean
}

export interface GuardianPayload {
  name: string
  phone: string
  bn_name?: string | null
  email?: string | null
  nid_number?: string | null
  occupation?: string | null
  present_address?: string | null
}

export function listGuardians(
  params: { page?: number; perPage?: number } = {},
  token?: string,
): Promise<PaginatedResponse<Guardian>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
  }

  return apiFetch<PaginatedResponse<Guardian>>(`/guardians${buildQuery(query)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function createGuardian(payload: GuardianPayload, token: string): Promise<{ data: Guardian }> {
  return createGeo<Guardian, GuardianPayload>('guardians', payload, token)
}

export function updateGuardian(
  id: number,
  payload: Partial<GuardianPayload>,
  token: string,
): Promise<{ data: Guardian }> {
  return updateGeo<Guardian, Partial<GuardianPayload>>('guardians', id, payload, token)
}

// ---------- Student profiles ----------

export interface StudentProfile {
  id: number
  user_id: number
  school_id: number
  district_id: number
  class_id: number | null
  section_id: number | null
  // Denormalized from section_id — see StudentProfileController::
  // transferSection(), the only place section_id ever changes.
  shift_id: number | null
  class_version_id: number | null
  student_id: string | null
  roll_number: string | null
  admission_date: string | null
  date_of_birth: string | null
  gender: 'male' | 'female' | 'other' | null
  blood_group: string | null
  present_address: string | null
  permanent_address: string | null
  photo_path: string | null
  user?: { id: number; name: string; email: string | null; phone: string | null } | null
  school?: School | null
  district?: District | null
  class?: SchoolClass | null
  section?: Section | null
  guardians?: Guardian[]
}

export interface StudentProfilePayload {
  user_id: number
  class_id?: number | null
  student_id?: string | null
  roll_number?: string | null
  admission_date?: string | null
  date_of_birth?: string | null
  gender?: 'male' | 'female' | 'other' | null
  blood_group?: string | null
  present_address?: string | null
  permanent_address?: string | null
  photo_path?: string | null
}

export function listStudentProfiles(
  params: { page?: number; perPage?: number; userId?: number; schoolId?: number; districtId?: number; classId?: number } = {},
  token?: string,
): Promise<PaginatedResponse<StudentProfile>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    user_id: params.userId,
    school_id: params.schoolId,
    district_id: params.districtId,
    class_id: params.classId,
  }

  return apiFetch<PaginatedResponse<StudentProfile>>(`/student-profiles${buildQuery(query)}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function createStudentProfile(payload: StudentProfilePayload, token: string): Promise<{ data: StudentProfile }> {
  return createGeo<StudentProfile, StudentProfilePayload>('student-profiles', payload, token)
}

export function updateStudentProfile(
  id: number,
  payload: Partial<Omit<StudentProfilePayload, 'user_id'>>,
  token: string,
): Promise<{ data: StudentProfile }> {
  return updateGeo<StudentProfile, Partial<Omit<StudentProfilePayload, 'user_id'>>>('student-profiles', id, payload, token)
}

export function attachGuardianToStudent(
  studentProfileId: number,
  payload: { guardian_id: number; relation: GuardianRelation; is_primary?: boolean },
  token: string,
): Promise<{ data: StudentProfile }> {
  return apiFetch<{ data: StudentProfile }>(`/student-profiles/${studentProfileId}/guardians`, {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export function detachGuardianFromStudent(studentProfileId: number, guardianId: number, token: string): Promise<void> {
  return apiFetch<void>(`/student-profiles/${studentProfileId}/guardians/${guardianId}`, {
    method: 'DELETE',
    headers: authHeaders(token),
  })
}

export interface StudentDashboardExam {
  exam_id: number
  exam_name: string
  attempts_used: number
  max_attempts: number | null
  best_score: number | null
  total_possible_marks: number | null
  passed: boolean | null
}

export interface StudentDashboardChapter {
  chapter_id: number
  chapter_name: string
  video_total: number
  materials_total: number
  materials_accessed: number
}

export interface StudentDashboardSubject {
  subject_id: number
  subject_name: string
  chapters: StudentDashboardChapter[]
  exams: StudentDashboardExam[]
}

export interface StudentDashboard {
  student_profile_id: number
  class_version_id: number | null
  academic_year_id: number | null
  subjects: StudentDashboardSubject[]
}

// Backed by StudentDashboardBuilder — already scopes exams to this
// student's own class_version_id/academic_year_id and excludes practice
// exams (is_practice=false), so this is the one call that answers "which
// of my class's real exams have I not attempted yet" without a second
// round-trip per exam to check attempt status.
export function getStudentDashboard(studentProfileId: number, token: string): Promise<{ data: StudentDashboard }> {
  return apiFetch<{ data: StudentDashboard }>(`/student-profiles/${studentProfileId}/dashboard`, {
    headers: authHeaders(token),
  })
}

// ---------- Student leave applications ----------

export type StudentLeaveApplicationStatus = 'pending' | 'approved' | 'rejected'

export interface StudentLeaveApplication {
  id: number
  student_profile_id: number
  school_id: number
  district_id: number
  class_id: number
  section_id: number
  shift_id: number
  class_version_id: number
  academic_year_id: number | null
  from_date: string
  to_date: string
  reason: string
  status: StudentLeaveApplicationStatus
  teacher_comment: string | null
  reviewed_by_id: number | null
  reviewed_at: string | null
  created_at: string
  student_profile?: StudentProfile | null
  class?: SchoolClass | null
  section?: Section | null
  shift?: Shift | null
  class_version?: ClassVersion | null
  academic_year?: AcademicYear | null
  reviewed_by?: { id: number; name: string } | null
  // Set server-side from StudentLeaveApplicationPolicy::review() — true
  // only for the section's own class teacher (or a staff actor with the
  // .update permission), never derived client-side.
  can_review: boolean
}

// A student submits their own leave application — scoped server-side to
// their exact school/district/class/section/shift/class_version (see
// StudentLeaveApplicationController::store()); the student never picks
// these themselves.
export function createLeaveApplication(
  payload: { from_date: string; to_date: string; reason: string },
  token: string,
): Promise<{ data: StudentLeaveApplication }> {
  return apiFetch<{ data: StudentLeaveApplication }>('/student-leave-applications', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// This student's own leave applications, most recent first.
export function listMyLeaveApplications(token: string): Promise<{ data: StudentLeaveApplication[] }> {
  return apiFetch<{ data: StudentLeaveApplication[] }>('/student-leave-applications/mine', {
    headers: authHeaders(token),
  })
}

// Staff/teacher listing — any actor with student_leave_applications.view
// sees every application in their own school (TenantScope); each row's
// `can_review` says whether this actor may act on that specific one.
export function listLeaveApplications(
  params: { status?: StudentLeaveApplicationStatus; sectionId?: number } = {},
  token: string,
): Promise<{ data: StudentLeaveApplication[] }> {
  const query = buildQuery({ status: params.status, section_id: params.sectionId })

  return apiFetch<{ data: StudentLeaveApplication[] }>(`/student-leave-applications${query}`, {
    headers: authHeaders(token),
  })
}

export function getLeaveApplication(id: number, token: string): Promise<{ data: StudentLeaveApplication }> {
  return apiFetch<{ data: StudentLeaveApplication }>(`/student-leave-applications/${id}`, {
    headers: authHeaders(token),
  })
}

// Approve or reject, with an optional comment — only valid for a row
// whose `can_review` came back true.
export function reviewLeaveApplication(
  id: number,
  payload: { status: 'approved' | 'rejected'; teacher_comment?: string | null },
  token: string,
): Promise<{ data: StudentLeaveApplication }> {
  return apiFetch<{ data: StudentLeaveApplication }>(`/student-leave-applications/${id}/review`, {
    method: 'PATCH',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// ---------- Attendance ----------
//
// One row per student per calendar day — homeroom-style, not per-subject
// (see the backend migration's own comment). A date covered by an active
// Holiday is never recorded for at all; the report/calendar read that
// date's holiday name directly from the Holiday calendar instead.

export type AttendanceStatus = 'present' | 'absent' | 'leave'

export interface AttendanceSection {
  id: number
  name: string
  // IDs alongside the display labels — used to group this flat list into
  // cascading Academic Year -> Class -> Class Version -> Shift -> Section
  // filters (see the teacher portal's Attendance List/Monthly Report
  // pages) without a second endpoint.
  class_id: number | null
  class_name: string | null
  class_version_id: number | null
  class_version: string | null
  shift_id: number | null
  shift: string | null
  academic_year_id: number | null
  academic_year: string | null
  // Only the section's own class teacher gets true — every other teacher
  // holding a SubjectTeacherAssignment there is view-only (see
  // AttendancePolicy::recordSection()).
  can_record: boolean
}

export function listMyAttendanceSections(token: string): Promise<{ data: AttendanceSection[] }> {
  return apiFetch<{ data: AttendanceSection[] }>('/attendances/my-sections', {
    headers: authHeaders(token),
  })
}

export interface AttendanceRosterRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  status: AttendanceStatus | null
}

export interface AttendanceRoster {
  section_id: number
  date: string
  can_record: boolean
  holiday_name: string | null
  students: AttendanceRosterRow[]
}

export function getAttendanceRoster(
  params: { sectionId: number; date: string },
  token: string,
): Promise<{ data: AttendanceRoster }> {
  const query = buildQuery({ section_id: params.sectionId, date: params.date })

  return apiFetch<{ data: AttendanceRoster }>(`/attendances/roster${query}`, {
    headers: authHeaders(token),
  })
}

// Bulk-records/updates one day's attendance for a section — only the
// section's own class teacher (or a staff override) may call this; a
// holiday date is rejected outright (422).
export function recordAttendance(
  payload: { section_id: number; date: string; records: { student_profile_id: number; status: AttendanceStatus }[] },
  token: string,
): Promise<{ data: AttendanceRoster }> {
  return apiFetch<{ data: AttendanceRoster }>('/attendances/record', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export interface AttendanceReportDate {
  date: string
  day: number
  holiday_name: string | null
}

export interface AttendanceReportStudentRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  statuses: Record<string, AttendanceStatus>
}

export interface AttendanceReport {
  section: {
    id: number
    name: string
    school_name: string | null
    school_address: string | null
    class_name: string | null
    class_version: string | null
    shift: string | null
    academic_year: string | null
    class_teacher: string | null
  }
  year: number
  month: number
  dates: AttendanceReportDate[]
  students: AttendanceReportStudentRow[]
}

export function getAttendanceReport(
  params: { sectionId: number; year: number; month: number },
  token: string,
): Promise<{ data: AttendanceReport }> {
  const query = buildQuery({ section_id: params.sectionId, year: params.year, month: params.month })

  return apiFetch<{ data: AttendanceReport }>(`/attendances/report${query}`, {
    headers: authHeaders(token),
  })
}

export interface MyAttendanceDay {
  date: string
  status: AttendanceStatus | null
  holiday_name: string | null
}

export interface MyAttendance {
  year: number
  month: number
  days: MyAttendanceDay[]
  summary: Record<AttendanceStatus, number>
}

// This student's own monthly attendance — the source data for the
// student portal's calendar view.
export function getMyAttendance(
  params: { year: number; month: number },
  token: string,
): Promise<{ data: MyAttendance | null }> {
  const query = buildQuery({ year: params.year, month: params.month })

  return apiFetch<{ data: MyAttendance | null }>(`/attendances/mine${query}`, {
    headers: authHeaders(token),
  })
}

// ---------- Academic exam terms ----------

export interface AcademicExamTerm {
  id: number
  name: string
  bn_name: string | null
  sort_order: number
  is_annual: boolean
  is_active: boolean
}

export interface AcademicExamTermPayload {
  name: string
  bn_name?: string | null
  sort_order?: number | null
  is_annual?: boolean
  is_active?: boolean
}

export function listAcademicExamTerms(
  params: { page?: number; perPage?: number; activeOnly?: boolean } = {},
): Promise<PaginatedResponse<AcademicExamTerm>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    active_only: params.activeOnly ? 'true' : undefined,
  }

  return apiFetch<PaginatedResponse<AcademicExamTerm>>(`/academic-exam-terms${buildQuery(query)}`)
}

export function createAcademicExamTerm(payload: AcademicExamTermPayload, token: string): Promise<{ data: AcademicExamTerm }> {
  return createGeo<AcademicExamTerm, AcademicExamTermPayload>('academic-exam-terms', payload, token)
}

export function updateAcademicExamTerm(
  id: number,
  payload: Partial<AcademicExamTermPayload>,
  token: string,
): Promise<{ data: AcademicExamTerm }> {
  return updateGeo<AcademicExamTerm, Partial<AcademicExamTermPayload>>('academic-exam-terms', id, payload, token)
}

export function deleteAcademicExamTerm(id: number, token: string): Promise<void> {
  return deleteGeo('academic-exam-terms', id, token)
}

// ---------- Academic grade configures ----------

export interface AcademicGradeConfigure {
  id: number
  min_percent: string
  max_percent: string
  grade_name: string
  bn_grade_name: string | null
  grade_point: string
  sort_order: number
  is_active: boolean
}

export interface AcademicGradeConfigurePayload {
  min_percent: number
  max_percent: number
  grade_name: string
  bn_grade_name?: string | null
  grade_point: number
  sort_order?: number | null
  is_active?: boolean
}

export function listAcademicGradeConfigures(
  params: { page?: number; perPage?: number; activeOnly?: boolean } = {},
): Promise<PaginatedResponse<AcademicGradeConfigure>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    active_only: params.activeOnly ? 'true' : undefined,
  }

  return apiFetch<PaginatedResponse<AcademicGradeConfigure>>(`/academic-grade-configures${buildQuery(query)}`)
}

export function createAcademicGradeConfigure(
  payload: AcademicGradeConfigurePayload,
  token: string,
): Promise<{ data: AcademicGradeConfigure }> {
  return createGeo<AcademicGradeConfigure, AcademicGradeConfigurePayload>('academic-grade-configures', payload, token)
}

export function updateAcademicGradeConfigure(
  id: number,
  payload: Partial<AcademicGradeConfigurePayload>,
  token: string,
): Promise<{ data: AcademicGradeConfigure }> {
  return updateGeo<AcademicGradeConfigure, Partial<AcademicGradeConfigurePayload>>('academic-grade-configures', id, payload, token)
}

export function deleteAcademicGradeConfigure(id: number, token: string): Promise<void> {
  return deleteGeo('academic-grade-configures', id, token)
}

// ---------- Academic subject mark configures ----------

export interface AcademicSubjectMarkConfigure {
  id: number
  class_id: number
  subject_id: number
  cq_full_marks: number | null
  mcq_full_marks: number | null
  practical_full_marks: number | null
  class?: SchoolClass | null
  subject?: Subject | null
}

export interface AcademicSubjectMarkConfigurePayload {
  class_id: number
  subject_id: number
  cq_full_marks?: number | null
  mcq_full_marks?: number | null
  practical_full_marks?: number | null
}

export function listAcademicSubjectMarkConfigures(
  params: { page?: number; perPage?: number; classId?: number; subjectId?: number } = {},
): Promise<PaginatedResponse<AcademicSubjectMarkConfigure>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    class_id: params.classId,
    subject_id: params.subjectId,
  }

  return apiFetch<PaginatedResponse<AcademicSubjectMarkConfigure>>(`/academic-subject-mark-configures${buildQuery(query)}`)
}

export function createAcademicSubjectMarkConfigure(
  payload: AcademicSubjectMarkConfigurePayload,
  token: string,
): Promise<{ data: AcademicSubjectMarkConfigure }> {
  return createGeo<AcademicSubjectMarkConfigure, AcademicSubjectMarkConfigurePayload>('academic-subject-mark-configures', payload, token)
}

export function updateAcademicSubjectMarkConfigure(
  id: number,
  payload: Partial<AcademicSubjectMarkConfigurePayload>,
  token: string,
): Promise<{ data: AcademicSubjectMarkConfigure }> {
  return updateGeo<AcademicSubjectMarkConfigure, Partial<AcademicSubjectMarkConfigurePayload>>(
    'academic-subject-mark-configures',
    id,
    payload,
    token,
  )
}

export function deleteAcademicSubjectMarkConfigure(id: number, token: string): Promise<void> {
  return deleteGeo('academic-subject-mark-configures', id, token)
}

// ---------- Academic exam marks (teacher mark entry) ----------

export interface AcademicExamMarkAssignment {
  section_id: number
  section_name: string
  subject_id: number
  subject_name: string
  class_id: number | null
  class_name: string | null
  class_version_id: number | null
  class_version: string | null
  shift_id: number | null
  shift: string | null
  academic_year_id: number | null
  academic_year: string | null
}

export function listMyAcademicExamMarkAssignments(token: string): Promise<{ data: AcademicExamMarkAssignment[] }> {
  return apiFetch<{ data: AcademicExamMarkAssignment[] }>('/academic-exam-marks/my-assignments', {
    headers: authHeaders(token),
  })
}

export interface AcademicExamMarkRosterRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  cq_marks_obtained: number | null
  mcq_marks_obtained: number | null
  practical_marks_obtained: number | null
}

export interface AcademicExamMarkRoster {
  section_id: number
  subject_id: number
  academic_exam_term_id: number
  academic_year_id: number
  can_record: boolean
  cq_full_marks: number | null
  mcq_full_marks: number | null
  practical_full_marks: number | null
  students: AcademicExamMarkRosterRow[]
}

export function getAcademicExamMarkRoster(
  params: { sectionId: number; subjectId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarkRoster }> {
  const query = buildQuery({
    section_id: params.sectionId,
    subject_id: params.subjectId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamMarkRoster }>(`/academic-exam-marks/roster${query}`, {
    headers: authHeaders(token),
  })
}

// Bulk-records/updates marks for a (section, subject, exam term,
// academic year) — only a teacher who may touch that section/subject
// (or a staff override) may call this; a (class, subject) pair with no
// configured full marks is rejected outright (422).
export function recordAcademicExamMarks(
  payload: {
    section_id: number
    subject_id: number
    academic_exam_term_id: number
    academic_year_id: number
    records: {
      student_profile_id: number
      cq_marks_obtained: number | null
      mcq_marks_obtained: number | null
      practical_marks_obtained: number | null
    }[]
  },
  token: string,
): Promise<{ data: AcademicExamMarkRoster }> {
  return apiFetch<{ data: AcademicExamMarkRoster }>('/academic-exam-marks/record', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// ---------- Academic exam publications (publish gate) ----------

export interface AcademicExamPublicationSubjectRow {
  subject_id: number
  subject_name: string | null
  entered_count: number
}

export interface AcademicExamPublicationStatus {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  roster_size: number
  subjects: AcademicExamPublicationSubjectRow[]
  is_published: boolean
}

export function getAcademicExamPublicationStatus(
  params: { sectionId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamPublicationStatus }> {
  const query = buildQuery({
    section_id: params.sectionId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamPublicationStatus }>(`/academic-exam-publications/status${query}`, {
    headers: authHeaders(token),
  })
}

export interface AcademicExamPublicationResult {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  is_published: boolean
}

// Publishing an already-published (section, term, year) is a no-op on
// the backend (still 201), not an error.
export function publishAcademicExamResults(
  payload: { section_id: number; academic_exam_term_id: number; academic_year_id: number },
  token: string,
): Promise<{ data: AcademicExamPublicationResult }> {
  return apiFetch<{ data: AcademicExamPublicationResult }>('/academic-exam-publications', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// Unpublishing an already-unpublished (or never-published) (section,
// term, year) is a no-op on the backend, not an error.
export function unpublishAcademicExamResults(
  payload: { section_id: number; academic_exam_term_id: number; academic_year_id: number },
  token: string,
): Promise<{ data: AcademicExamPublicationResult }> {
  return apiFetch<{ data: AcademicExamPublicationResult }>('/academic-exam-publications', {
    method: 'DELETE',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// ---------- Academic exam marksheets (teacher/staff lookup) ----------

export interface AcademicExamMarksheetSubject {
  subject_id: number
  subject_name: string
  cq: number | null
  cq_full: number | null
  mcq: number | null
  mcq_full: number | null
  practical: number | null
  practical_full: number | null
  total_obtained: number
  total_full: number
  percent: number
  grade_name: string | null
  grade_point: number | null
  passed: boolean
}

export interface AcademicExamMarksheet {
  student_profile_id: number
  subjects: AcademicExamMarksheetSubject[]
  gpa: number
  result: 'pass' | 'fail'
  section_position: number | null
  section_total_students: number
  class_position: number | null
  class_total_students: number
}

export function getAcademicExamMarksheet(
  params: { studentProfileId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarksheet }> {
  const query = buildQuery({
    student_profile_id: params.studentProfileId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamMarksheet }>(`/academic-exam-marks/marksheet${query}`, {
    headers: authHeaders(token),
  })
}

export interface AcademicExamSectionStudent {
  student_profile_id: number
  name: string | null
  roll_number: string | null
}

export function getAcademicExamSectionStudents(
  params: { sectionId: number },
  token: string,
): Promise<{ data: { section_id: number; students: AcademicExamSectionStudent[] } }> {
  const query = buildQuery({ section_id: params.sectionId })

  return apiFetch<{ data: { section_id: number; students: AcademicExamSectionStudent[] } }>(
    `/academic-exam-marks/section-students${query}`,
    { headers: authHeaders(token) },
  )
}

// ---------- Academic exam results (student's own) ----------

export interface MyAcademicExamResultTerm {
  academic_exam_term_id: number
  term_name: string
  academic_year_id: number
}

export function listMyAcademicExamResultTerms(token: string): Promise<{ data: MyAcademicExamResultTerm[] }> {
  return apiFetch<{ data: MyAcademicExamResultTerm[] }>('/academic-exam-marks/mine/terms', {
    headers: authHeaders(token),
  })
}

export function getMyAcademicExamResult(
  params: { academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarksheet | null }> {
  const query = buildQuery({ academic_exam_term_id: params.academicExamTermId, academic_year_id: params.academicYearId })

  return apiFetch<{ data: AcademicExamMarksheet | null }>(`/academic-exam-marks/mine${query}`, {
    headers: authHeaders(token),
  })
}

// ---------- Student promotions ----------

export interface StudentPromotionPreviewRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  result: 'pass' | 'fail' | 'no_marks'
  gpa: number
  default_action: 'promote' | 'retain'
}

export interface StudentPromotionPreview {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  suggested_to_class_id: number | null
  suggested_to_class_name: string | null
  students: StudentPromotionPreviewRow[]
}

export function getStudentPromotionPreview(
  params: { sectionId: number; academicYearId: number },
  token: string,
): Promise<{ data: StudentPromotionPreview }> {
  const query = buildQuery({ section_id: params.sectionId, academic_year_id: params.academicYearId })

  return apiFetch<{ data: StudentPromotionPreview }>(`/student-promotions/preview${query}`, {
    headers: authHeaders(token),
  })
}

// Confirms a promotion batch — promoted students (per `records[].promote`)
// have their StudentProfile moved to `to_section_id` in place; retained
// students are left untouched. A student_class_promotions audit row is
// written for every record either way. `override_capacity` requires the
// acting user to hold the existing `sections.override_capacity` permission.
export function confirmStudentPromotions(
  payload: {
    section_id: number
    academic_year_id: number
    to_section_id: number
    override_capacity?: boolean
    records: { student_profile_id: number; promote: boolean }[]
  },
  token: string,
): Promise<{ data: { section_id: number; promoted: boolean } }> {
  return apiFetch<{ data: { section_id: number; promoted: boolean } }>('/student-promotions', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}
