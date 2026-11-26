import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { ApiError, getStudentUser, listStudentProfiles, type StudentProfile, type StudentUser } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import PrintPageLayout from '../components/PrintPageLayout'

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

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="border-t border-gray-100 py-4 first:border-t-0 first:pt-0">
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

function FormBody({ student, profile }: { student: StudentUser; profile: StudentProfile | null }) {
  const sectionLabel = [profile?.class?.name, profile?.section?.name].filter(Boolean).join(' • ')

  return (
    <div className="flex flex-col">
      <div className="flex items-start justify-between gap-6 border-b border-gray-200 pb-4">
        <div className="flex items-center gap-3">
          {(profile?.school?.logo_url ?? student.school?.logo_url) && (
            <img
              src={profile?.school?.logo_url ?? student.school?.logo_url ?? undefined}
              alt=""
              className="h-14 w-14 object-contain"
            />
          )}
          <div>
            <p className="text-base font-semibold text-gray-900">
              {profile?.school?.name ?? student.school?.name ?? '—'}
            </p>
            <p className="text-lg font-bold text-primary-700">{t['management.idCard.studentFormTitle']}</p>
            <p className="text-[11px] text-gray-500">
              {t['management.idCard.generatedOn']}: {TODAY_ISO}
            </p>
          </div>
        </div>

        {profile?.photo_path && <img src={profile.photo_path} alt="" className="h-24 w-20 rounded-sm object-cover" />}
      </div>

      <Section title={t['management.idCard.accountSection']}>
        <Field label={t['management.fields.name']} value={student.name} />
        <Field label={t['management.fields.bnName']} value={student.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={student.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={student.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={profile?.school?.name ?? student.school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={profile?.district?.name ?? student.district?.name ?? '—'} />
      </Section>

      <Section title={t['management.idCard.profileSection']}>
        <Field label={t['management.fields.studentId']} value={profile?.student_id ?? '—'} />
        <Field label={t['management.fields.rollNumber']} value={profile?.roll_number ?? '—'} />
        <Field label={t['management.fields.class']} value={sectionLabel || '—'} />
        <Field label={t['management.fields.admissionDate']} value={profile?.admission_date ?? '—'} />
        <Field label={t['management.fields.dateOfBirth']} value={profile?.date_of_birth ?? '—'} />
        <Field label={t['management.fields.gender']} value={profile?.gender ? genderLabels[profile.gender] : '—'} />
        <Field label={t['management.fields.bloodGroup']} value={profile?.blood_group ?? '—'} />
        <Field label={t['management.fields.presentAddress']} value={profile?.present_address ?? '—'} full />
        <Field label={t['management.fields.permanentAddress']} value={profile?.permanent_address ?? '—'} full />
      </Section>

      <Section title={t['management.studentDetail.guardiansSection']}>
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
      </Section>

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

export default function StudentAdmissionFormPage() {
  const { id } = useParams()
  const { token, can } = useAuth()
  const studentId = Number(id)

  const [student, setStudent] = useState<StudentUser | null>(null)
  const [profile, setProfile] = useState<StudentProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const latestRequestedIdRef = useRef<number | null>(null)

  useEffect(() => {
    if (!token) return
    latestRequestedIdRef.current = studentId
    setLoading(true)
    setLoadError(null)

    Promise.all([getStudentUser(studentId, token), listStudentProfiles({ userId: studentId, perPage: 1 }, token)])
      .then(([studentResult, profilesResult]) => {
        if (latestRequestedIdRef.current !== studentId) return
        setStudent(studentResult.data)
        setProfile(profilesResult.data[0] ?? null)
      })
      .catch((caught: unknown) => {
        if (latestRequestedIdRef.current !== studentId) return
        setLoadError(caught instanceof ApiError ? caught.message : String(caught))
      })
      .finally(() => {
        if (latestRequestedIdRef.current === studentId) setLoading(false)
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [studentId, token])

  if (!can('student_profiles.print')) {
    return <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">{t['management.table.accessDenied']}</p>
  }

  if (loading) {
    return <p className="text-sm text-gray-500">{t['management.studentDetail.loading']}</p>
  }

  if (loadError || !student) {
    return (
      <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">
        {loadError ?? t['management.studentDetail.notFound']}
      </p>
    )
  }

  return (
    <PrintPageLayout title={t['management.idCard.studentFormTitle']} backTo={`/dashboard/students/${studentId}`}>
      <FormBody student={student} profile={profile} />
    </PrintPageLayout>
  )
}
