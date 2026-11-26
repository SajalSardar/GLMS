import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { ApiError, getTeacherUser, listTeacherProfiles, type TeacherProfile, type TeacherUser } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import PrintPageLayout from '../components/PrintPageLayout'

const t = getCommonStrings('en')

// Computed once at module load, not per render — see StudentDetailPage's
// TODAY_ISO for why (the react-purity lint rule).
const TODAY_ISO = new Date().toISOString().slice(0, 10)

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

function FormBody({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile | null }) {
  return (
    <div className="flex flex-col">
      <div className="flex items-start justify-between gap-6 border-b border-gray-200 pb-4">
        <div className="flex items-center gap-3">
          {teacher.school?.logo_url && <img src={teacher.school.logo_url} alt="" className="h-14 w-14 object-contain" />}
          <div>
            <p className="text-base font-semibold text-gray-900">{teacher.school?.name ?? '—'}</p>
            <p className="text-lg font-bold text-primary-700">{t['management.idCard.teacherFormTitle']}</p>
            <p className="text-[11px] text-gray-500">
              {t['management.idCard.generatedOn']}: {TODAY_ISO}
            </p>
          </div>
        </div>

        {profile?.photo_path && <img src={profile.photo_path} alt="" className="h-24 w-20 rounded-sm object-cover" />}
      </div>

      <Section title={t['management.idCard.accountSection']}>
        <Field label={t['management.fields.name']} value={teacher.name} />
        <Field label={t['management.fields.bnName']} value={teacher.bn_name ?? '—'} />
        <Field label={t['management.fields.email']} value={teacher.email ?? '—'} />
        <Field label={t['management.fields.phone']} value={teacher.phone ?? '—'} />
        <Field label={t['management.fields.school']} value={teacher.school?.name ?? '—'} />
        <Field label={t['management.fields.district']} value={teacher.district?.name ?? '—'} />
      </Section>

      <Section title={t['management.idCard.profileSection']}>
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
      </Section>

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

export default function TeacherAdmissionFormPage() {
  const { id } = useParams()
  const { token, can } = useAuth()
  const teacherId = Number(id)

  const [teacher, setTeacher] = useState<TeacherUser | null>(null)
  const [profile, setProfile] = useState<TeacherProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const latestRequestedIdRef = useRef<number | null>(null)

  useEffect(() => {
    if (!token) return
    latestRequestedIdRef.current = teacherId
    setLoading(true)
    setLoadError(null)

    Promise.all([getTeacherUser(teacherId, token), listTeacherProfiles({ userId: teacherId, perPage: 1 }, token)])
      .then(([teacherResult, profilesResult]) => {
        if (latestRequestedIdRef.current !== teacherId) return
        setTeacher(teacherResult.data)
        setProfile(profilesResult.data[0] ?? null)
      })
      .catch((caught: unknown) => {
        if (latestRequestedIdRef.current !== teacherId) return
        setLoadError(caught instanceof ApiError ? caught.message : String(caught))
      })
      .finally(() => {
        if (latestRequestedIdRef.current === teacherId) setLoading(false)
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teacherId, token])

  if (!can('teacher_profiles.print')) {
    return <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">{t['management.table.accessDenied']}</p>
  }

  if (loading) {
    return <p className="text-sm text-gray-500">{t['management.teacherDetail.loading']}</p>
  }

  if (loadError || !teacher) {
    return (
      <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">
        {loadError ?? t['management.teacherDetail.notFound']}
      </p>
    )
  }

  return (
    <PrintPageLayout title={t['management.idCard.teacherFormTitle']} backTo={`/dashboard/teachers/${teacherId}`}>
      <FormBody teacher={teacher} profile={profile} />
    </PrintPageLayout>
  )
}
