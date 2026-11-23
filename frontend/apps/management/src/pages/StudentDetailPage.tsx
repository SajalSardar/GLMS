import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  ApiError,
  attachGuardianToStudent,
  createGuardian,
  createStudentProfile,
  createStudentUser,
  detachGuardianFromStudent,
  getStudentUser,
  listClassSchools,
  listClassVersions,
  listSchools,
  listSections,
  listShifts,
  listStudentProfiles,
  updateGuardian,
  updateStudentProfile,
  updateStudentUser,
  type ClassSchool,
  type ClassVersion,
  type Guardian,
  type GuardianRelation,
  type School,
  type Section,
  type Shift,
  type StudentProfile,
  type StudentUser,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import DatePickerField from '../components/DatePickerField'
import { GeoField } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { schoolLabel } from '../components/schoolOptions'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport, type ToastState } from '../components/Toast'

const t = getCommonStrings('en')

const REQUIRED_MESSAGE = 'This field is required.'

// Computed once at module load, not per render — calling `new Date()`
// directly inside a component body trips this project's react-purity
// lint rule, and neither value needs to change mid-session.
const TODAY_ISO = new Date().toISOString().slice(0, 10)
const CURRENT_YEAR = new Date().getFullYear()

const relationLabels: Record<GuardianRelation, string> = {
  father: t['management.relation.father'],
  mother: t['management.relation.mother'],
  guardian: t['management.relation.guardian'],
  other: t['management.relation.other'],
}

// A guardian can only be added on the Add Student page (not when editing
// an existing one — see StudentDetailPage), and only with one of these
// three relations; `relationLabels` keeps the fourth ("guardian") only to
// label rows attached before this restriction existed.
const CREATABLE_RELATIONS: Array<Exclude<GuardianRelation, 'guardian'>> = ['father', 'mother', 'other']

interface GuardianDraft {
  id: number
  name: string
  bn_name: string
  phone: string
  email: string
  relation: Exclude<GuardianRelation, 'guardian'>
  is_primary: boolean
}

const genderLabels: Record<'male' | 'female' | 'other', string> = {
  male: t['management.gender.male'],
  female: t['management.gender.female'],
  other: t['management.gender.other'],
}

const emptyValues: Record<string, string> = {
  name: '',
  bn_name: '',
  email: '',
  phone: '',
  password: '',
  school_id: '',
  class_id: '',
  // Create-only — a first section assignment, not a move. See
  // StudentForm's class_version_id/shift_id/section_id GeoFields and
  // handleSave's profilePayload for why only section_id is ever sent.
  class_version_id: '',
  shift_id: '',
  section_id: '',
  student_id: '',
  roll_number: '',
  admission_date: '',
  date_of_birth: '',
  gender: '',
  blood_group: '',
  present_address: '',
  permanent_address: '',
}

function valuesFromStudentAndProfile(student: StudentUser, profile: StudentProfile | null): Record<string, string> {
  return {
    name: student.name,
    bn_name: student.bn_name ?? '',
    email: student.email ?? '',
    phone: student.phone ?? '',
    password: '',
    school_id: student.school ? String(student.school.id) : '',
    class_id: profile?.class_id != null ? String(profile.class_id) : '',
    student_id: profile?.student_id ?? '',
    roll_number: profile?.roll_number ?? '',
    admission_date: profile?.admission_date ?? '',
    date_of_birth: profile?.date_of_birth ?? '',
    gender: profile?.gender ?? '',
    blood_group: profile?.blood_group ?? '',
    present_address: profile?.present_address ?? '',
    permanent_address: profile?.permanent_address ?? '',
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

// Shared shell for the read-only view, the edit form, and the guardians
// manager: an icon'd heading over a two-column grid, so every section of
// the page reads as one system.
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

function TabButton({
  active,
  onClick,
  icon,
  label,
}: {
  active: boolean
  onClick: () => void
  icon: string
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 border-b-2 px-1 pb-2.5 text-sm font-medium transition-colors duration-150 ${
        active ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'
      }`}
    >
      <span className="material-symbols-outlined !text-[18px] leading-none">{icon}</span>
      {label}
    </button>
  )
}

function StudentHeader({ student, profile }: { student: StudentUser; profile: StudentProfile | null }) {
  return (
    <div className="flex flex-col items-center gap-4 rounded-md bg-white p-5 shadow-sm sm:flex-row sm:gap-6">
      {profile?.photo_path ? (
        <img src={profile.photo_path} alt="" className="h-20 w-20 rounded-full object-cover" />
      ) : (
        <span className="flex h-20 w-20 items-center justify-center rounded-full bg-primary-50 text-xl font-semibold text-primary-600">
          {initials(student.name)}
        </span>
      )}

      <div className="flex-1 text-center sm:text-left">
        <h2 className="text-lg font-semibold text-gray-800">{student.name}</h2>
        {profile?.class?.name && <p className="text-sm text-gray-500">{profile.class.name}</p>}

        <div className="mt-3 flex flex-col items-center gap-1.5 text-sm text-gray-600 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
          {student.email && <MetaItem icon="mail">{student.email}</MetaItem>}
          {student.phone && <MetaItem icon="call">{student.phone}</MetaItem>}
          {student.school?.name && (
            <MetaItem icon="school">
              {student.school.name}
              {student.district?.name ? ` • ${student.district.name}` : ''}
            </MetaItem>
          )}
        </div>
      </div>
    </div>
  )
}

function StudentView({ student, profile }: { student: StudentUser; profile: StudentProfile | null }) {
  return (
    <>
      <StudentHeader student={student} profile={profile} />

      <SectionCard icon="badge" title={t['management.studentDetail.accountSection']}>
        <Field label={t['management.fields.name']} value={student.name} />
        <Field label={t['management.fields.bnName']} value={student.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={student.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={student.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={student.school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={student.district?.name ?? '—'} />
      </SectionCard>

      <SectionCard icon="school" title={t['management.studentDetail.profileSection']}>
        {profile ? (
          <>
            <Field label={t['management.fields.class']} value={profile.class?.name ?? '—'} />
            <Field label={t['management.fields.section']} value={profile.section?.name ?? '—'} />
            <Field label={t['management.fields.studentId']} value={profile.student_id ?? '—'} />
            <Field label={t['management.fields.rollNumber']} value={profile.roll_number ?? '—'} />
            <Field label={t['management.fields.admissionDate']} value={profile.admission_date ?? '—'} />
            <Field label={t['management.fields.dateOfBirth']} value={profile.date_of_birth ?? '—'} />
            <Field label={t['management.fields.gender']} value={profile.gender ? genderLabels[profile.gender] : '—'} />
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
            <span className="material-symbols-outlined !text-3xl text-gray-300">school</span>
            <p className="text-sm text-gray-500">{t['management.studentDetail.noProfileYet']}</p>
          </div>
        )}
      </SectionCard>
    </>
  )
}

function StudentForm({
  values,
  onChange,
  fieldErrors,
  isCreate,
  schoolOptions,
  classOptions,
  classVersions,
  shifts,
  sections,
  guardianDrafts,
  onGuardianDraftsChange,
  guardianDraftErrors,
}: {
  values: Record<string, string>
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
  isCreate: boolean
  schoolOptions: Array<{ value: string; label: string }>
  classOptions: Array<{ value: string; label: string }>
  // Create-only cascade (Class -> Class Version -> Shift -> Section),
  // already scoped to the selected school — filtered here by whichever
  // of class_id/class_version_id/shift_id is currently selected, the
  // same way SchoolDetailPage's AddHierarchyModal filters its own copy
  // of this chain.
  classVersions: ClassVersion[]
  shifts: Shift[]
  sections: Section[]
  guardianDrafts?: GuardianDraft[]
  onGuardianDraftsChange?: (drafts: GuardianDraft[]) => void
  guardianDraftErrors?: Record<number, string>
}) {
  const versionOptions = classVersions
    .filter((version) => String(version.class_id) === values.class_id)
    .map((version) => ({ value: String(version.id), label: version.name }))

  const shiftOptions = shifts
    .filter((shift) => String(shift.class_version_id) === values.class_version_id)
    .map((shift) => ({ value: String(shift.id), label: shift.name }))

  const sectionOptions = sections
    .filter((section) => String(section.shift_id) === values.shift_id)
    .map((section) => ({ value: String(section.id), label: section.name }))
  return (
    <>
      <SectionCard icon="badge" title={t['management.studentDetail.accountSection']}>
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

        <GeoField label={t['management.fields.email']} error={fieldErrors.email}>
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
              onChange={(next) => {
                onChange('school_id', next)
                onChange('class_id', '')
              }}
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

      <SectionCard icon="school" title={t['management.studentDetail.profileSection']}>
        <GeoField label={t['management.fields.class']} error={fieldErrors.class_id}>
          <SearchableSelect
            value={values.class_id}
            onChange={(next) => {
              onChange('class_id', next)
              onChange('class_version_id', '')
              onChange('shift_id', '')
              onChange('section_id', '')
            }}
            placeholder={t['management.fields.class']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={classOptions}
            className={!values.school_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
          />
        </GeoField>

        {isCreate && (
          <>
            <GeoField label={t['management.fields.classVersion']} error={fieldErrors.class_version_id}>
              <SearchableSelect
                value={values.class_version_id}
                onChange={(next) => {
                  onChange('class_version_id', next)
                  onChange('shift_id', '')
                  onChange('section_id', '')
                }}
                placeholder={t['management.fields.classVersion']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={versionOptions}
                className={!values.class_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
              />
            </GeoField>

            <GeoField label={t['management.fields.shift']} error={fieldErrors.shift_id}>
              <SearchableSelect
                value={values.shift_id}
                onChange={(next) => {
                  onChange('shift_id', next)
                  onChange('section_id', '')
                }}
                placeholder={t['management.fields.shift']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={shiftOptions}
                className={!values.class_version_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
              />
            </GeoField>

            <GeoField label={t['management.fields.section']} error={fieldErrors.section_id}>
              <SearchableSelect
                value={values.section_id}
                onChange={(next) => onChange('section_id', next)}
                placeholder={t['management.fields.section']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={sectionOptions}
                className={!values.shift_id ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
              />
            </GeoField>
          </>
        )}

        <GeoField label={t['management.fields.studentId']} error={fieldErrors.student_id}>
          {isCreate ? (
            <p className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-500">
              {t['management.fields.studentIdAutoHint']}
            </p>
          ) : (
            <input
              className={inputClass}
              value={values.student_id}
              onChange={(event) => onChange('student_id', event.target.value)}
            />
          )}
        </GeoField>

        <GeoField label={t['management.fields.rollNumber']} error={fieldErrors.roll_number}>
          <input
            className={inputClass}
            value={values.roll_number}
            onChange={(event) => onChange('roll_number', event.target.value)}
          />
        </GeoField>

        <GeoField label={t['management.fields.admissionDate']} error={fieldErrors.admission_date}>
          <DatePickerField
            value={values.admission_date}
            onChange={(next) => onChange('admission_date', next)}
            maxDate={TODAY_ISO}
            minYear={CURRENT_YEAR - 20}
            maxYear={CURRENT_YEAR + 1}
            placeholder={t['management.fields.selectDate']}
            todayLabel={t['management.table.today']}
            clearLabel={t['management.table.clear']}
          />
        </GeoField>

        <GeoField label={t['management.fields.dateOfBirth']} error={fieldErrors.date_of_birth}>
          <DatePickerField
            value={values.date_of_birth}
            onChange={(next) => onChange('date_of_birth', next)}
            // Matches StoreStudentProfileRequest/UpdateStudentProfileRequest's
            // 'before:today' rule — the picker shouldn't offer a date the
            // backend will reject anyway.
            maxDate={TODAY_ISO}
            minYear={CURRENT_YEAR - 100}
            maxYear={CURRENT_YEAR}
            placeholder={t['management.fields.selectDate']}
            todayLabel={t['management.table.today']}
            clearLabel={t['management.table.clear']}
          />
        </GeoField>

        <GeoField label={t['management.fields.gender']} error={fieldErrors.gender}>
          <select className={inputClass} value={values.gender} onChange={(event) => onChange('gender', event.target.value)}>
            <option value="">—</option>
            {(Object.keys(genderLabels) as Array<keyof typeof genderLabels>).map((gender) => (
              <option key={gender} value={gender}>
                {genderLabels[gender]}
              </option>
            ))}
          </select>
        </GeoField>

        <GeoField label={t['management.fields.bloodGroup']} error={fieldErrors.blood_group}>
          <input
            className={inputClass}
            value={values.blood_group}
            onChange={(event) => onChange('blood_group', event.target.value)}
          />
        </GeoField>

        <GeoField
          label={t['management.fields.presentAddress']}
          error={fieldErrors.present_address}
          className="sm:col-span-2"
        >
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

      {isCreate && guardianDrafts && onGuardianDraftsChange && (
        <SectionCard icon="group" title={t['management.studentDetail.guardiansSection']}>
          <p className="col-span-1 text-sm text-gray-500 sm:col-span-2">
            {t['management.studentDetail.guardianAtCreateHint']}
          </p>

          <GuardianDraftsEditor
            drafts={guardianDrafts}
            onChange={onGuardianDraftsChange}
            errors={guardianDraftErrors ?? {}}
          />
        </SectionCard>
      )}
    </>
  )
}

function GuardianDraftsEditor({
  drafts,
  onChange,
  errors,
  startIndex = 0,
}: {
  drafts: GuardianDraft[]
  onChange: (drafts: GuardianDraft[]) => void
  errors: Record<number, string>
  // Card numbering continues on from any guardians already attached
  // (e.g. editing a student that already has one), rather than always
  // restarting at 1 — pass the existing guardian count here.
  startIndex?: number
}) {
  const nextIdRef = useRef(1)

  function addDraft() {
    const id = nextIdRef.current++
    onChange([
      ...drafts,
      { id, name: '', bn_name: '', phone: '', email: '', relation: 'father', is_primary: startIndex === 0 && drafts.length === 0 },
    ])
  }

  function updateDraft(id: number, patch: Partial<GuardianDraft>) {
    onChange(drafts.map((draft) => (draft.id === id ? { ...draft, ...patch } : draft)))
  }

  function makePrimary(id: number) {
    onChange(drafts.map((draft) => ({ ...draft, is_primary: draft.id === id })))
  }

  function removeDraft(id: number) {
    onChange(drafts.filter((draft) => draft.id !== id))
  }

  return (
    <>
      {drafts.map((draft, index) => (
        <div
          key={draft.id}
          className="col-span-1 flex flex-col gap-3 rounded-md border border-gray-100 p-4 sm:col-span-2"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-700">
              {t['management.studentDetail.guardiansSection']} {startIndex + index + 1}
            </span>
            <button
              type="button"
              className="cursor-pointer leading-none text-gray-400 transition-colors duration-150 hover:text-danger-500"
              onClick={() => removeDraft(draft.id)}
              aria-label={t['management.studentDetail.detach']}
            >
              <span className="material-symbols-outlined !text-md">close</span>
            </button>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <input
              className={inputClass}
              placeholder={t['management.fields.name']}
              value={draft.name}
              onChange={(event) => updateDraft(draft.id, { name: event.target.value })}
            />
            <input
              className={inputClass}
              placeholder={t['management.fields.bnName']}
              value={draft.bn_name}
              onChange={(event) => updateDraft(draft.id, { bn_name: event.target.value })}
            />
            <input
              type="tel"
              autoComplete="off"
              className={inputClass}
              placeholder={t['management.fields.phone']}
              value={draft.phone}
              onChange={(event) => updateDraft(draft.id, { phone: event.target.value })}
            />
            <input
              type="email"
              className={inputClass}
              placeholder={t['management.fields.email']}
              value={draft.email}
              onChange={(event) => updateDraft(draft.id, { email: event.target.value })}
            />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <select
              className={inputClass}
              value={draft.relation}
              onChange={(event) => updateDraft(draft.id, { relation: event.target.value as GuardianDraft['relation'] })}
            >
              {CREATABLE_RELATIONS.map((option) => (
                <option key={option} value={option}>
                  {relationLabels[option]}
                </option>
              ))}
            </select>
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="radio"
                name="primary-guardian"
                checked={draft.is_primary}
                onChange={() => makePrimary(draft.id)}
              />
              {t['management.fields.isPrimary']}
            </label>
          </div>

          {errors[draft.id] && <p className="text-xs text-danger-500">{errors[draft.id]}</p>}
        </div>
      ))}

      <button
        type="button"
        className={`${buttonNeutral} col-span-1 inline-flex w-fit items-center gap-1.5 sm:col-span-2`}
        onClick={addDraft}
      >
        <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
        {t['management.studentDetail.addGuardian']}
      </button>
    </>
  )
}

function GuardianRow({
  guardian,
  onRequestDetach,
}: {
  guardian: Guardian
  onRequestDetach?: (guardianId: number) => void
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-md border border-gray-100 px-4 py-3 sm:col-span-2">
      <div>
        <p className="text-sm font-medium text-gray-800">
          {guardian.name}
          {guardian.bn_name && <span className="ml-1 text-gray-500">({guardian.bn_name})</span>}
          {guardian.is_primary && (
            <span className="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-600">
              {t['management.studentDetail.primaryBadge']}
            </span>
          )}
        </p>
        <p className="text-xs text-gray-500">
          {relationLabels[guardian.relation ?? 'other']} • {guardian.phone}
          {guardian.email ? ` • ${guardian.email}` : ''}
        </p>
      </div>

      {onRequestDetach && (
        <button
          type="button"
          className="cursor-pointer leading-none text-gray-400 transition-colors duration-150 hover:text-danger-500"
          onClick={() => onRequestDetach(guardian.id)}
          aria-label={t['management.studentDetail.detach']}
        >
          <span className="material-symbols-outlined !text-md">close</span>
        </button>
      )}
    </div>
  )
}

// In edit mode, an already-attached guardian is shown as an editable
// card (not a read-only row) — its own contact details update via
// updateGuardian(), while relation/primary are pivot columns updated by
// re-attaching the same guardian_id (the backend's syncWithoutDetaching
// upserts the pivot row rather than erroring on the duplicate).
// Controlled — the parent (GuardiansList) owns every attached guardian's
// pending edits so one combined button can update all of them (plus any
// newly-added ones) in a single action, instead of one Save per card.
function EditableGuardianCard({
  guardian,
  edit,
  onChange,
  onRequestDetach,
  error,
}: {
  guardian: Guardian
  edit: GuardianDraft
  onChange: (patch: Partial<GuardianDraft>) => void
  onRequestDetach: () => void
  error?: string
}) {
  return (
    <div className="col-span-1 flex flex-col gap-3 rounded-md border border-gray-100 p-4 sm:col-span-2">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-gray-700">
          {guardian.name}
          {Boolean(guardian.is_primary) && (
            <span className="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-600">
              {t['management.studentDetail.primaryBadge']}
            </span>
          )}
        </span>
        <button
          type="button"
          className="cursor-pointer leading-none text-gray-400 transition-colors duration-150 hover:text-danger-500"
          onClick={onRequestDetach}
          aria-label={t['management.studentDetail.detach']}
        >
          <span className="material-symbols-outlined !text-md">close</span>
        </button>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <input
          className={inputClass}
          placeholder={t['management.fields.name']}
          value={edit.name}
          onChange={(event) => onChange({ name: event.target.value })}
        />
        <input
          className={inputClass}
          placeholder={t['management.fields.bnName']}
          value={edit.bn_name}
          onChange={(event) => onChange({ bn_name: event.target.value })}
        />
        <input
          type="tel"
          autoComplete="off"
          className={inputClass}
          placeholder={t['management.fields.phone']}
          value={edit.phone}
          onChange={(event) => onChange({ phone: event.target.value })}
        />
        <input
          type="email"
          className={inputClass}
          placeholder={t['management.fields.email']}
          value={edit.email}
          onChange={(event) => onChange({ email: event.target.value })}
        />
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <select
          className={inputClass}
          value={edit.relation}
          onChange={(event) => onChange({ relation: event.target.value as GuardianDraft['relation'] })}
        >
          {CREATABLE_RELATIONS.map((option) => (
            <option key={option} value={option}>
              {relationLabels[option]}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={edit.is_primary}
            onChange={(event) => onChange({ is_primary: event.target.checked })}
          />
          {t['management.fields.isPrimary']}
        </label>
      </div>

      {error && <p className="text-xs text-danger-500">{error}</p>}
    </div>
  )
}

// Guardians are only ever created on the Add Student page in isCreate
// mode — the one exception is the "add new guardian" editor rendered
// here too, reusing the exact same GuardianDraftsEditor. Every pending
// change on this tab — edits to already-attached guardians and any new
// ones being added — is collected and submitted together by a single
// combined button, rather than one Save per guardian.
function GuardiansList({
  studentProfileId,
  guardians,
  editable,
  token,
  onChanged,
  showToast,
}: {
  studentProfileId: number
  guardians: Guardian[]
  editable: boolean
  token: string
  onChanged: () => void
  showToast: (toast: ToastState) => void
}) {
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [detachingId, setDetachingId] = useState<number | null>(null)
  const [guardianEdits, setGuardianEdits] = useState<Record<number, GuardianDraft>>({})
  const [guardianEditErrors, setGuardianEditErrors] = useState<Record<number, string>>({})
  const [newGuardianDrafts, setNewGuardianDrafts] = useState<GuardianDraft[]>([])
  const [newGuardianDraftErrors, setNewGuardianDraftErrors] = useState<Record<number, string>>({})

  // Seeds (and keeps up to date) one edit slot per attached guardian,
  // without clobbering in-progress typing for a guardian that was
  // already being edited when `guardians` last changed.
  const guardianIdsKey = guardians.map((guardian) => guardian.id).join(',')
  useEffect(() => {
    setGuardianEdits((prev) => {
      const next: Record<number, GuardianDraft> = {}
      for (const guardian of guardians) {
        next[guardian.id] = prev[guardian.id] ?? {
          id: guardian.id,
          name: guardian.name,
          bn_name: guardian.bn_name ?? '',
          phone: guardian.phone,
          email: guardian.email ?? '',
          relation: guardian.relation === 'guardian' ? 'other' : (guardian.relation ?? 'other'),
          is_primary: Boolean(guardian.is_primary),
        }
      }
      return next
    })
    // guardianIdsKey is a stable string derived from `guardians` — safe
    // to depend on instead of the array, which gets a new reference
    // every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [guardianIdsKey])

  async function handleDetach(guardianId: number) {
    setSubmitting(true)
    setError(null)

    try {
      await detachGuardianFromStudent(studentProfileId, guardianId, token)
      setDetachingId(null)
      onChanged()
      showToast({ type: 'success', message: t['management.table.deletedSuccess'] })
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : String(caught)
      setError(message)
      showToast({ type: 'error', message })
    } finally {
      setSubmitting(false)
    }
  }

  async function handleUpdateAll() {
    const editErrors: Record<number, string> = {}
    for (const guardian of guardians) {
      const edit = guardianEdits[guardian.id]
      if (edit && (!edit.name.trim() || !edit.phone.trim())) {
        editErrors[guardian.id] = t['management.studentDetail.guardianNameAndPhoneRequired']
      }
    }

    const draftErrors: Record<number, string> = {}
    for (const draft of newGuardianDrafts) {
      if (Boolean(draft.name.trim()) !== Boolean(draft.phone.trim())) {
        draftErrors[draft.id] = t['management.studentDetail.guardianNameAndPhoneRequired']
      }
    }

    if (Object.keys(editErrors).length > 0 || Object.keys(draftErrors).length > 0) {
      setGuardianEditErrors(editErrors)
      setNewGuardianDraftErrors(draftErrors)
      return
    }

    setSubmitting(true)
    setError(null)
    setGuardianEditErrors({})
    setNewGuardianDraftErrors({})

    try {
      for (const guardian of guardians) {
        const edit = guardianEdits[guardian.id]
        if (!edit) continue
        await updateGuardian(
          guardian.id,
          { name: edit.name.trim(), bn_name: edit.bn_name || null, phone: edit.phone.trim(), email: edit.email || null },
          token,
        )
        await attachGuardianToStudent(studentProfileId, { guardian_id: guardian.id, relation: edit.relation, is_primary: edit.is_primary }, token)
      }

      const submittableDrafts = newGuardianDrafts.filter((draft) => draft.name.trim() && draft.phone.trim())
      for (const draft of submittableDrafts) {
        const createdGuardian = await createGuardian(
          { name: draft.name.trim(), bn_name: draft.bn_name || null, phone: draft.phone.trim(), email: draft.email || null },
          token,
        )
        await attachGuardianToStudent(
          studentProfileId,
          { guardian_id: createdGuardian.data.id, relation: draft.relation, is_primary: draft.is_primary },
          token,
        )
      }

      setNewGuardianDrafts([])
      onChanged()
      showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : String(caught)
      setError(message)
      showToast({ type: 'error', message })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <SectionCard icon="group" title={t['management.studentDetail.guardiansSection']}>
      {guardians.length === 0 && !editable && (
        <p className="col-span-1 text-sm text-gray-500 sm:col-span-2">{t['management.studentDetail.noGuardiansYet']}</p>
      )}

      {editable &&
        guardians.map((guardian) =>
          detachingId === guardian.id ? (
            <div
              key={guardian.id}
              className="flex items-center justify-between gap-3 rounded-md border border-danger-100 bg-danger-50 px-4 py-3 sm:col-span-2"
            >
              <span className="text-sm text-danger-600">{t['management.studentDetail.confirmDetach']}</span>
              <div className="flex gap-2">
                <button type="button" className={buttonNeutral} onClick={() => setDetachingId(null)} disabled={submitting}>
                  {t['management.table.cancel']}
                </button>
                <button
                  type="button"
                  className="cursor-pointer rounded-md bg-danger-500 px-3 py-1.5 text-xs font-medium text-white transition-colors duration-150 hover:bg-danger-400"
                  onClick={() => handleDetach(guardian.id)}
                  disabled={submitting}
                >
                  {t['management.studentDetail.detach']}
                </button>
              </div>
            </div>
          ) : (
            <EditableGuardianCard
              key={guardian.id}
              guardian={guardian}
              edit={
                guardianEdits[guardian.id] ?? {
                  id: guardian.id,
                  name: guardian.name,
                  bn_name: guardian.bn_name ?? '',
                  phone: guardian.phone,
                  email: guardian.email ?? '',
                  relation: guardian.relation === 'guardian' ? 'other' : (guardian.relation ?? 'other'),
                  is_primary: Boolean(guardian.is_primary),
                }
              }
              onChange={(patch) =>
                setGuardianEdits((prev) => ({ ...prev, [guardian.id]: { ...prev[guardian.id], ...patch } }))
              }
              onRequestDetach={() => setDetachingId(guardian.id)}
              error={guardianEditErrors[guardian.id]}
            />
          ),
        )}

      {!editable && guardians.map((guardian) => <GuardianRow key={guardian.id} guardian={guardian} />)}

      {editable && (
        <GuardianDraftsEditor
          drafts={newGuardianDrafts}
          onChange={setNewGuardianDrafts}
          errors={newGuardianDraftErrors}
          startIndex={guardians.length}
        />
      )}

      {error && (
        <p className="col-span-1 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600 sm:col-span-2">{error}</p>
      )}

      {editable && (
        <div className="col-span-1 flex justify-end sm:col-span-2">
          <button type="button" className={buttonPrimary} onClick={handleUpdateAll} disabled={submitting}>
            {submitting ? t['management.table.saving'] : t['management.table.save']}
          </button>
        </div>
      )}
    </SectionCard>
  )
}

export default function StudentDetailPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { token } = useAuth()

  const isCreate = id === undefined
  const studentId = isCreate ? null : Number(id)

  const [student, setStudent] = useState<StudentUser | null>(null)
  const [profile, setProfile] = useState<StudentProfile | null>(null)
  const [schools, setSchools] = useState<School[]>([])
  const [schoolClasses, setSchoolClasses] = useState<ClassSchool[]>([])
  // Create-only cascade data, scoped to the selected school — fetched
  // once per school_id change, same as schoolClasses above, then
  // filtered client-side as the cascade narrows (mirrors
  // SchoolDetailPage's AddHierarchyModal).
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [shifts, setShifts] = useState<Shift[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [loading, setLoading] = useState(!isCreate)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [mode, setMode] = useState<'view' | 'edit'>(isCreate || searchParams.get('edit') === '1' ? 'edit' : 'view')
  // Tabs only apply to an existing student (both viewing and editing) —
  // the Add Student page keeps everything on one page with one Save, so
  // the guardian drafts it creates are never submitted separately.
  const [activeTab, setActiveTab] = useState<'student' | 'guardian'>('student')
  const [values, setValues] = useState<Record<string, string>>(emptyValues)
  const [guardianDrafts, setGuardianDrafts] = useState<GuardianDraft[]>([])
  const [guardianDraftErrors, setGuardianDraftErrors] = useState<Record<number, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const { toast, showToast } = useToast()
  // When creating, the student (and its profile) may already have been
  // created by an earlier save attempt that then failed on the guardian
  // step — tracked here so retrying updates that same row instead of
  // creating a duplicate, and so a guardian draft already attached on a
  // previous attempt isn't submitted a second time.
  const [pendingStudentId, setPendingStudentId] = useState<number | null>(null)
  const [pendingProfileId, setPendingProfileId] = useState<number | null>(null)
  const [attachedDraftIds, setAttachedDraftIds] = useState<Set<number>>(new Set())

  // Tracks the most recently requested id so a slower, superseded response
  // (e.g. student 1's request resolving after student 2's, once the user
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

      Promise.all([getStudentUser(targetId, token), listStudentProfiles({ userId: targetId, perPage: 1 }, token)])
        .then(([studentResult, profilesResult]) => {
          if (latestRequestedIdRef.current !== targetId) return

          const loadedProfile = profilesResult.data[0] ?? null
          setStudent(studentResult.data)
          setProfile(loadedProfile)
          setValues(valuesFromStudentAndProfile(studentResult.data, loadedProfile))
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

  const loadRef = useRef(load)
  useEffect(() => {
    loadRef.current = load
  })

  useEffect(() => {
    if (studentId !== null) loadRef.current(studentId)
  }, [studentId])

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [])

  useEffect(() => {
    // No school selected: nothing to fetch. `classOptions` below already
    // derives an empty list whenever `values.school_id` is falsy, so
    // there's no need to reset `schoolClasses` here too.
    if (!values.school_id) {
      return
    }

    // Guards against a slower, earlier response landing after a faster,
    // later one if the school is switched quickly — without this, a
    // stale class list from the previously-selected school could
    // overwrite the correct one.
    let active = true

    listClassSchools({ schoolId: Number(values.school_id), perPage: 500 })
      .then((result) => {
        if (active) setSchoolClasses(result.data)
      })
      .catch(() => {
        if (active) setSchoolClasses([])
      })

    return () => {
      active = false
    }
  }, [values.school_id])

  useEffect(() => {
    // Only needed on the Add Student page — an existing profile's
    // section is changed via the dedicated transfer flow, not this form.
    if (!isCreate || !values.school_id) {
      return
    }

    let active = true
    const schoolId = Number(values.school_id)

    Promise.all([
      listClassVersions({ schoolId, perPage: 500 }),
      listShifts({ schoolId, perPage: 500 }),
      listSections({ schoolId, perPage: 500 }),
    ])
      .then(([versionsResult, shiftsResult, sectionsResult]) => {
        if (!active) return
        setClassVersions(versionsResult.data)
        setShifts(shiftsResult.data)
        setSections(sectionsResult.data)
      })
      .catch(() => {
        if (active) {
          setClassVersions([])
          setShifts([])
          setSections([])
        }
      })

    return () => {
      active = false
    }
  }, [isCreate, values.school_id])

  const schoolOptions = schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))
  // Derived rather than reset via the effect above: with no school
  // selected there's nothing to offer, regardless of whatever
  // `schoolClasses` still holds from a previous selection.
  const classOptions = values.school_id
    ? schoolClasses.map((classSchool) => ({
        value: String(classSchool.class_id),
        label: classSchool.class?.name ?? String(classSchool.class_id),
      }))
    : []

  function startEdit() {
    setFormError(null)
    setFieldErrors({})
    setMode('edit')
  }

  function cancelEdit() {
    if (isCreate) {
      navigate('/dashboard/students')
      return
    }

    if (student) setValues(valuesFromStudentAndProfile(student, profile))
    setFormError(null)
    setFieldErrors({})
    setMode('view')
  }

  async function handleSave() {
    if (!token) return

    const missing: Record<string, string> = {}
    if (!values.name.trim()) missing.name = REQUIRED_MESSAGE
    if (isCreate && !values.password.trim()) missing.password = REQUIRED_MESSAGE

    // Each guardian card is entirely optional, but a name and phone are
    // both needed to create that guardian — so a card left half-filled
    // is flagged rather than silently dropped or silently submitted.
    const draftErrors: Record<number, string> = {}
    if (isCreate) {
      for (const draft of guardianDrafts) {
        if (Boolean(draft.name.trim()) !== Boolean(draft.phone.trim())) {
          draftErrors[draft.id] = t['management.studentDetail.guardianNameAndPhoneRequired']
        }
      }
    }

    if (Object.keys(missing).length > 0 || Object.keys(draftErrors).length > 0) {
      setFieldErrors(missing)
      setGuardianDraftErrors(draftErrors)
      setFormError(null)
      return
    }

    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})
    setGuardianDraftErrors({})

    try {
      const studentPayload = {
        name: values.name,
        bn_name: values.bn_name || null,
        email: values.email || null,
        phone: values.phone || null,
        school_id: values.school_id ? Number(values.school_id) : null,
        ...(values.password.trim() ? { password: values.password } : {}),
      }

      const profilePayload = {
        class_id: values.class_id ? Number(values.class_id) : null,
        // Create-only: a first section assignment, not a move — the
        // server derives class_id/shift_id/class_version_id from it and
        // ignores the class_id above when this is set (see
        // StudentProfileController::store()). Sending it on an update is
        // a no-op (UpdateStudentProfileRequest doesn't accept it), but
        // kept out explicitly anyway for clarity.
        ...(isCreate ? { section_id: values.section_id ? Number(values.section_id) : null } : {}),
        // student_id is never sent on create — it's always server-
        // generated (StoreStudentProfileRequest rejects a client-
        // supplied value outright); only an edit of an already-created
        // profile can touch it, to correct a mistake.
        ...(isCreate ? {} : { student_id: values.student_id || null }),
        roll_number: values.roll_number || null,
        admission_date: values.admission_date || null,
        date_of_birth: values.date_of_birth || null,
        gender: (values.gender || null) as 'male' | 'female' | 'other' | null,
        blood_group: values.blood_group || null,
        present_address: values.present_address || null,
        permanent_address: values.permanent_address || null,
      }

      const submittableDrafts = isCreate
        ? guardianDrafts.filter((draft) => draft.name.trim() && draft.phone.trim() && !attachedDraftIds.has(draft.id))
        : []
      const hasGuardianData = submittableDrafts.length > 0
      const hasProfileData = hasGuardianData || Object.values(profilePayload).some((value) => value !== null)

      let savedStudentId: number

      try {
        if (isCreate) {
          if (pendingStudentId !== null) {
            savedStudentId = pendingStudentId
            await updateStudentUser(savedStudentId, studentPayload, token)
          } else {
            const created = await createStudentUser(studentPayload, token)
            savedStudentId = created.data.id
            setPendingStudentId(savedStudentId)
          }
        } else {
          savedStudentId = studentId as number
          await updateStudentUser(savedStudentId, studentPayload, token)
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

      let savedProfileId: number | null = profile?.id ?? pendingProfileId

      try {
        if (profile) {
          await updateStudentProfile(profile.id, profilePayload, token)
        } else if (savedProfileId !== null) {
          await updateStudentProfile(savedProfileId, profilePayload, token)
        } else if (isCreate || hasProfileData) {
          // Unconditional on isCreate (not just when some other profile
          // field was filled in): a profile is the only place
          // student_id lives, and without one the student has no login
          // credential at all (AuthController::studentLogin() resolves
          // purely by it) — so Add Student always creates one, even if
          // every other profile field was left blank.
          const createdProfile = await createStudentProfile({ ...profilePayload, user_id: savedStudentId }, token)
          savedProfileId = createdProfile.data.id
          setPendingProfileId(savedProfileId)
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

      // Guardians are created and attached one at a time so a failure
      // partway through (e.g. a duplicate phone number) leaves the ones
      // already attached alone — `attachedDraftIds` makes sure a retry
      // only resubmits the drafts that didn't make it through yet.
      if (savedProfileId !== null) {
        for (const draft of submittableDrafts) {
          try {
            const createdGuardian = await createGuardian(
              {
                name: draft.name.trim(),
                bn_name: draft.bn_name || null,
                phone: draft.phone.trim(),
                email: draft.email || null,
              },
              token,
            )
            await attachGuardianToStudent(
              savedProfileId,
              { guardian_id: createdGuardian.data.id, relation: draft.relation, is_primary: draft.is_primary },
              token,
            )
            setAttachedDraftIds((prev) => new Set(prev).add(draft.id))
          } catch (error) {
            if (error instanceof ApiError && error.errors) {
              const message = Object.values(error.errors)[0]?.[0] ?? t['management.studentDetail.guardianAttachFailed']
              setGuardianDraftErrors({ [draft.id]: message })
              showToast({ type: 'error', message })
              return
            }
            throw error
          }
        }
      }

      showToast({
        type: 'success',
        message: isCreate ? t['management.table.createdSuccess'] : t['management.table.updatedSuccess'],
      })

      if (isCreate) {
        navigate(`/dashboard/students/${savedStudentId}`)
      } else {
        load(savedStudentId)
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
          onClick={() => navigate('/dashboard/students')}
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
          {t['management.studentDetail.back']}
        </button>

        {!isCreate && mode === 'view' && !loading && student && (
          <button type="button" className={`${buttonPrimary} inline-flex items-center gap-1.5`} onClick={startEdit}>
            <span className="material-symbols-outlined !text-[18px] leading-none">edit</span>
            {t['management.teacherDetail.edit']}
          </button>
        )}
      </div>

      {mode === 'edit' && (
        <h1 className="text-xl font-semibold text-gray-800">
          {isCreate ? t['management.studentDetail.addTitle'] : student?.name}
        </h1>
      )}

      {!isCreate && !loading && !loadError && student && (
        <div className="flex gap-6 border-b border-gray-200">
          <TabButton
            active={activeTab === 'student'}
            onClick={() => setActiveTab('student')}
            icon="badge"
            label={t['management.studentDetail.studentInfoTab']}
          />
          <TabButton
            active={activeTab === 'guardian'}
            onClick={() => setActiveTab('guardian')}
            icon="group"
            label={t['management.studentDetail.guardianInfoTab']}
          />
        </div>
      )}

      {loading && <p className="text-sm text-gray-500">{t['management.studentDetail.loading']}</p>}

      {loadError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{loadError}</p>}

      {!isCreate && !loading && !loadError && !student && (
        <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">
          {t['management.studentDetail.notFound']}
        </p>
      )}

      {/* Existing student, viewing: each tab shows its own data, never both at once. */}
      {mode === 'view' && !isCreate && student && (
        <>
          {activeTab === 'student' && <StudentView student={student} profile={profile} />}

          {activeTab === 'guardian' &&
            (profile && token ? (
              <GuardiansList
                studentProfileId={profile.id}
                guardians={profile.guardians ?? []}
                editable={false}
                token={token}
                onChanged={() => load(student.id)}
                showToast={showToast}
              />
            ) : (
              <p className="text-sm text-gray-500">{t['management.studentDetail.noGuardiansYet']}</p>
            ))}
        </>
      )}

      {/* Add Student page: one page, one Save — guardians added here are
          submitted together with the student, so there's nothing to tab. */}
      {mode === 'edit' && isCreate && (
        <div className="flex flex-col gap-4">
          <StudentForm
            values={values}
            onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))}
            fieldErrors={fieldErrors}
            isCreate
            schoolOptions={schoolOptions}
            classOptions={classOptions}
            classVersions={classVersions}
            shifts={shifts}
            sections={sections}
            guardianDrafts={guardianDrafts}
            onGuardianDraftsChange={setGuardianDrafts}
            guardianDraftErrors={guardianDraftErrors}
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

      {/* Editing an existing student: each tab updates on its own — the
          Student info tab has its own Save/Cancel, while the Guardian
          info tab's only action (removing a guardian) is already
          immediate and needs no Save. */}
      {mode === 'edit' && !isCreate && student && (
        <>
          {activeTab === 'student' && (
            <div className="flex flex-col gap-4">
              <StudentForm
                values={values}
                onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))}
                fieldErrors={fieldErrors}
                isCreate={false}
                schoolOptions={schoolOptions}
                classOptions={classOptions}
                classVersions={[]}
                shifts={[]}
                sections={[]}
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

          {activeTab === 'guardian' &&
            (profile && token ? (
              <GuardiansList
                studentProfileId={profile.id}
                guardians={profile.guardians ?? []}
                editable
                token={token}
                onChanged={() => load(student.id)}
                showToast={showToast}
              />
            ) : (
              <p className="rounded-md border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
                {t['management.studentDetail.saveProfileFirst']}
              </p>
            ))}
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
