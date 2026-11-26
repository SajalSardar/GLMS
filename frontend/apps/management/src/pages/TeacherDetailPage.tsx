import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  ApiError,
  createTeacherProfile,
  createTeacherUser,
  getTeacherUser,
  listSchools,
  listTeacherProfiles,
  updateTeacherProfile,
  updateTeacherUser,
  type School,
  type TeacherProfile,
  type TeacherUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import DatePickerField from '../components/DatePickerField'
import { GeoField } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

const CURRENT_YEAR = new Date().getFullYear()

const REQUIRED_MESSAGE = 'This field is required.'

const emptyValues: Record<string, string> = {
  name: '',
  bn_name: '',
  email: '',
  phone: '',
  password: '',
  school_id: '',
  employee_id: '',
  designation: '',
  subject_specialization: '',
  qualification: '',
  joining_date: '',
  nid_number: '',
  profile_phone: '',
  blood_group: '',
  present_address: '',
  permanent_address: '',
  photo_path: '',
}

function valuesFromTeacherAndProfile(teacher: TeacherUser, profile: TeacherProfile | null): Record<string, string> {
  return {
    name: teacher.name,
    bn_name: teacher.bn_name ?? '',
    email: teacher.email ?? '',
    phone: teacher.phone ?? '',
    password: '',
    school_id: teacher.school ? String(teacher.school.id) : '',
    employee_id: profile?.employee_id ?? '',
    designation: profile?.designation ?? '',
    subject_specialization: profile?.subject_specialization ?? '',
    qualification: profile?.qualification ?? '',
    joining_date: profile?.joining_date ?? '',
    nid_number: profile?.nid_number ?? '',
    profile_phone: profile?.phone ?? '',
    blood_group: profile?.blood_group ?? '',
    present_address: profile?.present_address ?? '',
    permanent_address: profile?.permanent_address ?? '',
    photo_path: profile?.photo_path ?? '',
  }
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  return parts
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('')
}

// Shared shell for both the read-only view and the edit form: an icon'd
// heading over a two-column field grid, so the two modes read as the same
// page rather than a view screen bolted to an unrelated form.
function SectionCard({ icon, title, children }: { icon: string; title: string; children: ReactNode }) {
  return (
    <div className="rounded-md bg-white p-5 shadow-sm">
      <div className="mb-4 flex items-center gap-2">
        <span className="material-symbols-outlined !text-[20px] text-primary-500">{icon}</span>
        <h3 className="text-base font-semibold text-gray-800">{title}</h3>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  )
}

function Field({ label, value, className }: { label: string; value: ReactNode; className?: string }) {
  return (
    <div className={`flex flex-col gap-1${className ? ` ${className}` : ''}`}>
      <span className="text-xs text-gray-500">{label}</span>
      <span className="text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function MetaItem({ icon, children }: { icon: string; children: ReactNode }) {
  return (
    <span className="inline-flex items-center justify-center gap-1.5 sm:justify-start">
      <span className="material-symbols-outlined !text-[16px] text-gray-400">{icon}</span>
      {children}
    </span>
  )
}

function TeacherHeader({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile | null }) {
  return (
    <div className="flex flex-col items-center gap-4 rounded-md bg-white p-5 shadow-sm sm:flex-row sm:gap-6">
      {profile?.photo_path ? (
        <img src={profile.photo_path} alt="" className="h-20 w-20 rounded-full object-cover" />
      ) : (
        <span className="flex h-20 w-20 items-center justify-center rounded-full bg-primary-50 text-xl font-semibold text-primary-600">
          {initials(teacher.name)}
        </span>
      )}

      <div className="flex-1 text-center sm:text-left">
        <h2 className="text-lg font-semibold text-gray-800">{teacher.name}</h2>
        {profile?.designation && <p className="text-sm text-gray-500">{profile.designation}</p>}

        <div className="mt-3 flex flex-col items-center gap-1.5 text-sm text-gray-600 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
          <MetaItem icon="mail">{teacher.email ?? '—'}</MetaItem>
          {teacher.phone && <MetaItem icon="call">{teacher.phone}</MetaItem>}
          {teacher.school?.name && (
            <MetaItem icon="school">
              {teacher.school.name}
              {teacher.district?.name ? ` • ${teacher.district.name}` : ''}
            </MetaItem>
          )}
        </div>
      </div>
    </div>
  )
}

function TeacherView({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile | null }) {
  return (
    <div className="flex flex-col gap-5">
      <TeacherHeader teacher={teacher} profile={profile} />

      <SectionCard icon="badge" title={t['management.teacherDetail.accountSection']}>
        <Field label={t['management.fields.name']} value={teacher.name} />
        <Field label={t['management.fields.bnName']} value={teacher.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={teacher.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={teacher.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={teacher.school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={teacher.district?.name ?? '—'} />
      </SectionCard>

      <SectionCard icon="assignment_ind" title={t['management.teacherDetail.profileSection']}>
        {profile ? (
          <>
            <Field label={t['management.fields.employeeId']} value={profile.employee_id ?? '—'} />
            <Field label={t['management.fields.designation']} value={profile.designation ?? '—'} />
            <Field
              label={t['management.fields.subjectSpecialization']}
              value={profile.subject_specialization ?? '—'}
            />
            <Field label={t['management.fields.qualification']} value={profile.qualification ?? '—'} />
            <Field label={t['management.fields.joiningDate']} value={profile.joining_date ?? '—'} />
            <Field label={t['management.fields.nidNumber']} value={profile.nid_number ?? '—'} />
            <Field label={t['management.fields.phone']} value={profile.phone ?? '—'} />
            <Field label={t['management.fields.bloodGroup']} value={profile.blood_group ?? '—'} />
            <Field
              label={t['management.fields.presentAddress']}
              value={profile.present_address ?? '—'}
              className="sm:col-span-2"
            />
            <Field
              label={t['management.fields.permanentAddress']}
              value={profile.permanent_address ?? '—'}
              className="sm:col-span-2"
            />
          </>
        ) : (
          <div className="col-span-1 flex flex-col items-center gap-2 rounded-md border border-dashed border-gray-200 py-8 text-center sm:col-span-2">
            <span className="material-symbols-outlined !text-3xl text-gray-300">assignment_ind</span>
            <p className="text-sm text-gray-500">{t['management.teacherDetail.noProfileYet']}</p>
          </div>
        )}
      </SectionCard>
    </div>
  )
}

function TeacherForm({
  values,
  onChange,
  fieldErrors,
  isCreate,
  schoolOptions,
}: {
  values: Record<string, string>
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
  isCreate: boolean
  schoolOptions: Array<{ value: string; label: string }>
}) {
  return (
    <div className="flex flex-col gap-5">
      <SectionCard icon="badge" title={t['management.teacherDetail.accountSection']}>
        <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
          <input className={inputClass} value={values.name} onChange={(event) => onChange('name', event.target.value)} />
        </GeoField>

        <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
          <input
            className={inputClass}
            value={values.bn_name}
            onChange={(event) => onChange('bn_name', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.email']} required error={fieldErrors.email}>
          <input
            type="email"
            className={inputClass}
            value={values.email}
            onChange={(event) => onChange('email', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.phone']} error={fieldErrors.phone}>
          <input
            type="tel"
            autoComplete="off"
            className={inputClass}
            value={values.phone}
            onChange={(event) => onChange('phone', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.password']} required={isCreate} error={fieldErrors.password}>
          <input
            type="password"
            autoComplete="new-password"
            className={inputClass}
            value={values.password}
            onChange={(event) => onChange('password', event.target.value)}
            placeholder={isCreate ? undefined : t['management.fields.passwordHintEdit']}
          />
        </GeoField>

        <GeoField label={t['management.fields.school']} error={fieldErrors.school_id}>
          {isCreate ? (
            <SearchableSelect
              value={values.school_id}
              onChange={(next) => onChange('school_id', next)}
              placeholder={t['management.fields.school']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={schoolOptions}
            />
          ) : (
            <p className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {schoolOptions.find((option) => option.value === values.school_id)?.label ?? '—'}
            </p>
          )}
        </GeoField>
      </SectionCard>

      <SectionCard icon="assignment_ind" title={t['management.teacherDetail.profileSection']}>
        <GeoField label={t['management.fields.employeeId']} error={fieldErrors.employee_id}>
          <input
            className={inputClass}
            value={values.employee_id}
            onChange={(event) => onChange('employee_id', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.designation']} error={fieldErrors.designation}>
          <input
            className={inputClass}
            value={values.designation}
            onChange={(event) => onChange('designation', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.subjectSpecialization']} error={fieldErrors.subject_specialization}>
          <input
            className={inputClass}
            value={values.subject_specialization}
            onChange={(event) => onChange('subject_specialization', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.qualification']} error={fieldErrors.qualification}>
          <input
            className={inputClass}
            value={values.qualification}
            onChange={(event) => onChange('qualification', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.joiningDate']} error={fieldErrors.joining_date}>
          <DatePickerField
            value={values.joining_date}
            onChange={(next) => onChange('joining_date', next)}
            minYear={CURRENT_YEAR - 50}
            maxYear={CURRENT_YEAR + 1}
            placeholder={t['management.fields.selectDate']}
            todayLabel={t['management.table.today']}
            clearLabel={t['management.table.clear']}
          />
        </GeoField>

        <GeoField label={t['management.fields.nidNumber']} error={fieldErrors.nid_number}>
          <input
            className={inputClass}
            value={values.nid_number}
            onChange={(event) => onChange('nid_number', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.phone']} error={fieldErrors.profile_phone}>
          <input
            type="tel"
            autoComplete="off"
            className={inputClass}
            value={values.profile_phone}
            onChange={(event) => onChange('profile_phone', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.bloodGroup']} error={fieldErrors.blood_group}>
          <input
            className={inputClass}
            value={values.blood_group}
            onChange={(event) => onChange('blood_group', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.presentAddress']} error={fieldErrors.present_address} className="sm:col-span-2">
          <textarea
            className={inputClass}
            rows={2}
            value={values.present_address}
            onChange={(event) => onChange('present_address', event.target.value)}
          />
        </GeoField>

        <GeoField
          label={t['management.fields.permanentAddress']}
          error={fieldErrors.permanent_address}
          className="sm:col-span-2"
        >
          <textarea
            className={inputClass}
            rows={2}
            value={values.permanent_address}
            onChange={(event) => onChange('permanent_address', event.target.value)}
          />
        </GeoField>
      </SectionCard>
    </div>
  )
}

export default function TeacherDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { token, can } = useAuth()

  const isCreate = id === undefined
  const teacherId = isCreate ? null : Number(id)

  const [teacher, setTeacher] = useState<TeacherUser | null>(null)
  const [profile, setProfile] = useState<TeacherProfile | null>(null)
  const [schools, setSchools] = useState<School[]>([])
  const [loading, setLoading] = useState(!isCreate)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [mode, setMode] = useState<'view' | 'edit'>(isCreate || searchParams.get('edit') === '1' ? 'edit' : 'view')
  const [values, setValues] = useState<Record<string, string>>(emptyValues)
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const { toast, showToast } = useToast()

  // Tracks the most recently requested id so a slower, superseded response
  // (e.g. teacher 1's request resolving after teacher 2's, once the user
  // has navigated on) can't overwrite the newer data it raced against —
  // :id changes re-render this same component instance rather than
  // remounting it, so without this guard the two in-flight requests race.
  const latestRequestedIdRef = useRef<number | null>(null)

  const load = useCallback(
    (targetId: number) => {
      if (!token) return
      latestRequestedIdRef.current = targetId
      setLoading(true)
      setLoadError(null)

      Promise.all([getTeacherUser(targetId, token), listTeacherProfiles({ userId: targetId, perPage: 1 }, token)])
        .then(([teacherResult, profilesResult]) => {
          if (latestRequestedIdRef.current !== targetId) return

          const loadedProfile = profilesResult.data[0] ?? null
          setTeacher(teacherResult.data)
          setProfile(loadedProfile)
          setValues(valuesFromTeacherAndProfile(teacherResult.data, loadedProfile))
        })
        .catch((caught: unknown) => {
          if (latestRequestedIdRef.current !== targetId) return

          setLoadError(caught instanceof ApiError ? caught.message : String(caught))
        })
        .finally(() => {
          if (latestRequestedIdRef.current === targetId) setLoading(false)
        })
    },
    [token],
  )

  // Same ref-indirection as GeoCrudTable/SchoolDetailPage: keeps the mount
  // effect below from re-running on every render just because `load` (which
  // closes over `token`) gets a new identity.
  const loadRef = useRef(load)
  useEffect(() => {
    loadRef.current = load
  })

  useEffect(() => {
    if (teacherId !== null) loadRef.current(teacherId)
  }, [teacherId])

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  const schoolOptions = schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))

  function startEdit() {
    setFormError(null)
    setFieldErrors({})
    setMode('edit')
  }

  function cancelEdit() {
    if (isCreate) {
      navigate('/dashboard/teachers')
      return
    }

    if (teacher) setValues(valuesFromTeacherAndProfile(teacher, profile))
    setFormError(null)
    setFieldErrors({})
    setMode('view')
  }

  async function handleSave() {
    if (!token) return

    const missing: Record<string, string> = {}
    if (!values.name.trim()) missing.name = REQUIRED_MESSAGE
    if (!values.email.trim()) missing.email = REQUIRED_MESSAGE
    if (isCreate && !values.password.trim()) missing.password = REQUIRED_MESSAGE

    if (Object.keys(missing).length > 0) {
      setFieldErrors(missing)
      setFormError(null)
      return
    }

    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})

    try {
      const teacherPayload = {
        name: values.name,
        bn_name: values.bn_name || null,
        email: values.email,
        phone: values.phone || null,
        school_id: values.school_id ? Number(values.school_id) : null,
        ...(values.password.trim() ? { password: values.password } : {}),
      }

      const profilePayload = {
        employee_id: values.employee_id || null,
        designation: values.designation || null,
        subject_specialization: values.subject_specialization || null,
        qualification: values.qualification || null,
        joining_date: values.joining_date || null,
        nid_number: values.nid_number || null,
        phone: values.profile_phone || null,
        blood_group: values.blood_group || null,
        present_address: values.present_address || null,
        permanent_address: values.permanent_address || null,
        photo_path: values.photo_path || null,
      }
      const hasProfileData = Object.values(profilePayload).some((value) => value !== null)

      let savedTeacherId: number

      // Two separate requests, each validated against the same field name
      // ("phone") on two different tables — kept as two try/catches instead
      // of one so a failure can be attributed to the right section and,
      // for the profile's `phone`, remapped to `profile_phone` rather than
      // colliding with the account phone field's error slot.
      try {
        if (isCreate) {
          const created = await createTeacherUser(teacherPayload, token)
          savedTeacherId = created.data.id
        } else {
          savedTeacherId = teacherId as number
          await updateTeacherUser(savedTeacherId, teacherPayload, token)
        }
      } catch (error) {
        if (error instanceof ApiError && error.errors) {
          const mapped: Record<string, string> = {}
          for (const [key, messages] of Object.entries(error.errors)) {
            mapped[key] = messages[0]
          }
          setFieldErrors(mapped)
          showToast({ type: 'error', message: error.message })
          return
        }
        throw error
      }

      try {
        if (profile) {
          await updateTeacherProfile(profile.id, profilePayload, token)
        } else if (hasProfileData) {
          await createTeacherProfile({ ...profilePayload, user_id: savedTeacherId }, token)
        }
      } catch (error) {
        if (error instanceof ApiError && error.errors) {
          const mapped: Record<string, string> = {}
          for (const [key, messages] of Object.entries(error.errors)) {
            mapped[key === 'phone' ? 'profile_phone' : key] = messages[0]
          }
          setFieldErrors(mapped)
          showToast({ type: 'error', message: error.message })
          return
        }
        throw error
      }

      showToast({
        type: 'success',
        message: isCreate ? t['management.table.createdSuccess'] : t['management.table.updatedSuccess'],
      })

      if (isCreate) {
        navigate(`/dashboard/teachers/${savedTeacherId}`)
      } else {
        load(savedTeacherId)
        setMode('view')
      }
    } catch (error) {
      const message = error instanceof ApiError ? error.message : String(error)
      setFormError(message)
      showToast({ type: 'error', message })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <button
          type="button"
          className={`${buttonNeutral} inline-flex items-center gap-1.5`}
          onClick={() => navigate('/dashboard/teachers')}
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
          {t['management.teacherDetail.back']}
        </button>

        {!isCreate && mode === 'view' && !loading && teacher && (
          <div className="flex flex-wrap items-center gap-3">
            {can('teacher_profiles.print') && (
              <>
                <button
                  type="button"
                  className={`${buttonNeutral} inline-flex items-center gap-1.5`}
                  onClick={() => navigate(`/dashboard/teachers/${teacher.id}/admission-form`)}
                >
                  <span className="material-symbols-outlined !text-[18px] leading-none">description</span>
                  {t['management.idCard.printFormButton']}
                </button>
                <button
                  type="button"
                  className={`${buttonNeutral} inline-flex items-center gap-1.5`}
                  onClick={() => navigate(`/dashboard/teachers/${teacher.id}/id-card`)}
                >
                  <span className="material-symbols-outlined !text-[18px] leading-none">badge</span>
                  {t['management.idCard.printIdCardButton']}
                </button>
              </>
            )}
            <button type="button" className={`${buttonPrimary} inline-flex items-center gap-1.5`} onClick={startEdit}>
              <span className="material-symbols-outlined !text-[18px] leading-none">edit</span>
              {t['management.teacherDetail.edit']}
            </button>
          </div>
        )}
      </div>

      {mode === 'edit' && (
        <h1 className="text-xl font-semibold text-gray-800">
          {isCreate ? t['management.teacherDetail.addTitle'] : teacher?.name}
        </h1>
      )}

      {loading && <p className="text-sm text-gray-500">{t['management.teacherDetail.loading']}</p>}

      {loadError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{loadError}</p>}

      {!isCreate && !loading && !loadError && !teacher && (
        <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">
          {t['management.teacherDetail.notFound']}
        </p>
      )}

      {mode === 'view' && teacher && <TeacherView teacher={teacher} profile={profile} />}

      {mode === 'edit' && (isCreate || teacher) && (
        <div className="flex flex-col gap-4">
          <TeacherForm
            values={values}
            onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))}
            fieldErrors={fieldErrors}
            isCreate={isCreate}
            schoolOptions={schoolOptions}
          />

          {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}

          <div className="flex justify-end gap-3">
            <button type="button" className={buttonNeutral} onClick={cancelEdit} disabled={submitting}>
              {t['management.table.cancel']}
            </button>
            <button type="button" className={buttonPrimary} onClick={handleSave} disabled={submitting}>
              {submitting ? t['management.table.saving'] : t['management.table.save']}
            </button>
          </div>
        </div>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
