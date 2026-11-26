import type { ReactNode } from 'react'
import type { StudentProfile, StudentUser } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Computed once at module load, not per render — see StudentDetailPage's
// TODAY_ISO for why (the react-purity lint rule).
const TODAY_ISO = new Date().toISOString().slice(0, 10)

const genderLabels: Record<'male' | 'female' | 'other', string> = {
  male: t['management.gender.male'],
  female: t['management.gender.female'],
  other: t['management.gender.other'],
}

const relationLabels: Record<string, string> = {
  father: t['management.relation.father'],
  mother: t['management.relation.mother'],
  guardian: t['management.relation.guardian'],
  other: t['management.relation.other'],
}

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

export function StudentIdCardView({ student, profile }: { student: StudentUser; profile: StudentProfile | null }) {
  if (!profile) {
    return (
      <p className="rounded-md border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
        {t['management.idCard.studentProfileRequired']}
      </p>
    )
  }

  const school = profile.school ?? student.school
  const sectionLabel = [profile.class?.name, profile.section?.name].filter(Boolean).join(' • ')
  const primaryGuardian = profile.guardians?.find((guardian) => guardian.is_primary) ?? profile.guardians?.[0] ?? null

  return (
    <>
      <IdCardFace labelHidden={t['management.idCard.studentTitle']}>
        <div className="flex items-center gap-1.5 border-b border-gray-100 pb-1.5">
          {school?.logo_url && <img src={school.logo_url} alt="" className="h-6 w-6 object-contain" />}
          <div className="min-w-0 flex-1">
            <p className="truncate text-[11px] font-semibold text-gray-800">{school?.name ?? '—'}</p>
            <p className="text-[9px] font-medium tracking-wide text-primary-600 uppercase">
              {t['management.idCard.studentTitle']}
            </p>
          </div>
        </div>

        <div className="mt-2 flex flex-1 items-center gap-3">
          {profile.photo_path ? (
            <img src={profile.photo_path} alt="" className="h-16 w-14 rounded-sm object-cover" />
          ) : (
            <span className="flex h-16 w-14 items-center justify-center rounded-sm bg-primary-50 text-sm font-semibold text-primary-600">
              {initials(student.name)}
            </span>
          )}

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-gray-900">{student.name}</p>
            {student.bn_name && <p className="truncate text-[11px] text-gray-500">{student.bn_name}</p>}

            <div className="mt-1 flex flex-col gap-0.5">
              <Line label={t['management.fields.studentId']} value={profile.student_id ?? '—'} />
              <Line label={t['management.fields.class']} value={sectionLabel || '—'} />
              <Line label={t['management.fields.bloodGroup']} value={profile.blood_group ?? '—'} />
              <Line label={t['management.fields.admissionDate']} value={profile.admission_date ?? '—'} />
            </div>
          </div>
        </div>
      </IdCardFace>

      <IdCardFace>
        <div className="flex flex-1 flex-col gap-1.5 text-[11px] text-gray-700">
          <Line label={t['management.fields.presentAddress']} value={profile.present_address ?? '—'} />
          <Line
            label={t['management.idCard.emergencyContact']}
            value={primaryGuardian ? `${primaryGuardian.name} — ${primaryGuardian.phone}` : '—'}
          />
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

export function StudentAdmissionFormView({ student, profile }: { student: StudentUser; profile: StudentProfile | null }) {
  const school = profile?.school ?? student.school
  const sectionLabel = [profile?.class?.name, profile?.section?.name].filter(Boolean).join(' • ')

  return (
    <div className="flex w-full max-w-2xl flex-col">
      <div className="flex items-start justify-between gap-6 border-b border-gray-200 pb-4">
        <div className="flex items-center gap-3">
          {school?.logo_url && <img src={school.logo_url} alt="" className="h-14 w-14 object-contain" />}
          <div>
            <p className="text-base font-semibold text-gray-900">{school?.name ?? '—'}</p>
            <p className="text-lg font-bold text-primary-700">{t['management.idCard.studentFormTitle']}</p>
            <p className="text-[11px] text-gray-500">
              {t['management.idCard.generatedOn']}: {TODAY_ISO}
            </p>
          </div>
        </div>

        {profile?.photo_path && <img src={profile.photo_path} alt="" className="h-24 w-20 rounded-sm object-cover" />}
      </div>

      <FormSection title={t['management.idCard.accountSection']}>
        <Field label={t['management.fields.name']} value={student.name} />
        <Field label={t['management.fields.bnName']} value={student.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={student.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={student.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={profile?.district?.name ?? student.district?.name ?? '—'} />
      </FormSection>

      <FormSection title={t['management.idCard.profileSection']}>
        <Field label={t['management.fields.studentId']} value={profile?.student_id ?? '—'} />
        <Field label={t['management.fields.rollNumber']} value={profile?.roll_number ?? '—'} />
        <Field label={t['management.fields.class']} value={sectionLabel || '—'} />
        <Field label={t['management.fields.admissionDate']} value={profile?.admission_date ?? '—'} />
        <Field label={t['management.fields.dateOfBirth']} value={profile?.date_of_birth ?? '—'} />
        <Field label={t['management.fields.gender']} value={profile?.gender ? genderLabels[profile.gender] : '—'} />
        <Field label={t['management.fields.bloodGroup']} value={profile?.blood_group ?? '—'} />
        <Field label={t['management.fields.presentAddress']} value={profile?.present_address ?? '—'} full />
        <Field label={t['management.fields.permanentAddress']} value={profile?.permanent_address ?? '—'} full />
      </FormSection>

      <FormSection title={t['management.studentDetail.guardiansSection']}>
        {profile?.guardians && profile.guardians.length > 0 ? (
          profile.guardians.map((guardian) => (
            <Field
              key={guardian.id}
              full
              label={`${relationLabels[guardian.relation ?? 'other']}${guardian.is_primary ? ` (${t['management.studentDetail.primaryBadge']})` : ''}`}
              value={`${guardian.name} — ${guardian.phone}`}
            />
          ))
        ) : (
          <Field full label={t['management.studentDetail.guardiansSection']} value="—" />
        )}
      </FormSection>

      <div className="mt-10 flex justify-between px-4 text-center text-xs text-gray-600">
        <div>
          <p className="mb-8">&nbsp;</p>
          <p className="border-t border-gray-400 pt-1">{t['management.idCard.signatureStudent']}</p>
        </div>
        <div>
          <p className="mb-8">&nbsp;</p>
          <p className="border-t border-gray-400 pt-1">{t['management.idCard.signatureAuthority']}</p>
        </div>
      </div>
    </div>
  )
}
