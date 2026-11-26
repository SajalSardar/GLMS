import type { ReactNode } from 'react'
import type { TeacherProfile, TeacherUser } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Computed once at module load, not per render — see StudentDetailPage's
// TODAY_ISO for why (the react-purity lint rule).
const TODAY_ISO = new Date().toISOString().slice(0, 10)

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  return parts
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('')
}

// Real CR80 card dimensions (the standard ID card size) so the printed
// page can be laminated/cut to size rather than just looking card-shaped
// on screen.
function IdCardFace({ children, labelHidden }: { children: ReactNode; labelHidden?: string }) {
  return (
    <div
      className="flex h-[2.125in] w-[3.375in] flex-col overflow-hidden rounded-[10px] border border-dashed border-gray-300 bg-white p-3 shadow-sm print:shadow-none"
      aria-label={labelHidden}
    >
      {children}
    </div>
  )
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <p className="text-[11px] leading-tight text-gray-700">
      <span className="font-medium text-gray-500">{label}: </span>
      {value}
    </p>
  )
}

export function TeacherIdCardView({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile | null }) {
  if (!profile) {
    return (
      <p className="rounded-md border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
        {t['management.idCard.teacherProfileRequired']}
      </p>
    )
  }

  const school = teacher.school

  return (
    <>
      <IdCardFace labelHidden={t['management.idCard.teacherTitle']}>
        <div className="flex items-center gap-1.5 border-b border-gray-100 pb-1.5">
          {school?.logo_url && <img src={school.logo_url} alt="" className="h-6 w-6 object-contain" />}
          <div className="min-w-0 flex-1">
            <p className="truncate text-[11px] font-semibold text-gray-800">{school?.name ?? '—'}</p>
            <p className="text-[9px] font-medium tracking-wide text-primary-600 uppercase">
              {t['management.idCard.teacherTitle']}
            </p>
          </div>
        </div>

        <div className="mt-2 flex flex-1 items-center gap-3">
          {profile.photo_path ? (
            <img src={profile.photo_path} alt="" className="h-16 w-14 rounded-sm object-cover" />
          ) : (
            <span className="flex h-16 w-14 items-center justify-center rounded-sm bg-primary-50 text-sm font-semibold text-primary-600">
              {initials(teacher.name)}
            </span>
          )}

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-gray-900">{teacher.name}</p>
            {teacher.bn_name && <p className="truncate text-[11px] text-gray-500">{teacher.bn_name}</p>}

            <div className="mt-1 flex flex-col gap-0.5">
              <Line label={t['management.fields.employeeId']} value={profile.employee_id ?? '—'} />
              <Line label={t['management.fields.designation']} value={profile.designation ?? '—'} />
              <Line label={t['management.fields.bloodGroup']} value={profile.blood_group ?? '—'} />
              <Line label={t['management.fields.joiningDate']} value={profile.joining_date ?? '—'} />
            </div>
          </div>
        </div>
      </IdCardFace>

      <IdCardFace>
        <div className="flex flex-1 flex-col gap-1.5 text-[11px] text-gray-700">
          <Line label={t['management.fields.presentAddress']} value={profile.present_address ?? '—'} />
          <Line label={t['management.fields.phone']} value={profile.phone ?? teacher.phone ?? '—'} />
        </div>

        <div className="border-t border-gray-100 pt-1.5 text-center text-[9px] text-gray-500">
          <p>{t['management.idCard.ifFound']}</p>
          <p className="font-medium text-gray-700">{school?.name ?? '—'}</p>
          {school?.phone && <p>{school.phone}</p>}
        </div>
      </IdCardFace>
    </>
  )
}

function FormSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="w-full border-t border-gray-100 py-4 first:border-t-0 first:pt-0">
      <h3 className="mb-3 text-sm font-semibold tracking-wide text-primary-700 uppercase">{title}</h3>
      <div className="grid grid-cols-2 gap-x-6 gap-y-3">{children}</div>
    </div>
  )
}

function Field({ label, value, full }: { label: string; value: string; full?: boolean }) {
  return (
    <div className={full ? 'col-span-2' : undefined}>
      <p className="text-[11px] text-gray-500">{label}</p>
      <p className="text-sm font-medium text-gray-800">{value}</p>
    </div>
  )
}

export function TeacherAdmissionFormView({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile | null }) {
  const school = teacher.school

  return (
    <div className="flex w-full max-w-2xl flex-col">
      <div className="flex items-start justify-between gap-6 border-b border-gray-200 pb-4">
        <div className="flex items-center gap-3">
          {school?.logo_url && <img src={school.logo_url} alt="" className="h-14 w-14 object-contain" />}
          <div>
            <p className="text-base font-semibold text-gray-900">{school?.name ?? '—'}</p>
            <p className="text-lg font-bold text-primary-700">{t['management.idCard.teacherFormTitle']}</p>
            <p className="text-[11px] text-gray-500">
              {t['management.idCard.generatedOn']}: {TODAY_ISO}
            </p>
          </div>
        </div>

        {profile?.photo_path && <img src={profile.photo_path} alt="" className="h-24 w-20 rounded-sm object-cover" />}
      </div>

      <FormSection title={t['management.idCard.accountSection']}>
        <Field label={t['management.fields.name']} value={teacher.name} />
        <Field label={t['management.fields.bnName']} value={teacher.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={teacher.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={teacher.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={teacher.district?.name ?? '—'} />
      </FormSection>

      <FormSection title={t['management.idCard.profileSection']}>
        <Field label={t['management.fields.employeeId']} value={profile?.employee_id ?? '—'} />
        <Field label={t['management.fields.designation']} value={profile?.designation ?? '—'} />
        <Field label={t['management.fields.subjectSpecialization']} value={profile?.subject_specialization ?? '—'} />
        <Field label={t['management.fields.qualification']} value={profile?.qualification ?? '—'} />
        <Field label={t['management.fields.joiningDate']} value={profile?.joining_date ?? '—'} />
        <Field label={t['management.fields.nidNumber']} value={profile?.nid_number ?? '—'} />
        <Field label={t['management.fields.bloodGroup']} value={profile?.blood_group ?? '—'} />
        <Field label={t['management.fields.phone']} value={profile?.phone ?? '—'} />
        <Field label={t['management.fields.presentAddress']} value={profile?.present_address ?? '—'} full />
        <Field label={t['management.fields.permanentAddress']} value={profile?.permanent_address ?? '—'} full />
      </FormSection>

      <div className="mt-10 flex justify-between px-4 text-center text-xs text-gray-600">
        <div>
          <p className="mb-8">&nbsp;</p>
          <p className="border-t border-gray-400 pt-1">{t['management.idCard.signatureTeacher']}</p>
        </div>
        <div>
          <p className="mb-8">&nbsp;</p>
          <p className="border-t border-gray-400 pt-1">{t['management.idCard.signatureAuthority']}</p>
        </div>
      </div>
    </div>
  )
}
