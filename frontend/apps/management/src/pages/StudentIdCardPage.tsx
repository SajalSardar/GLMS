import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError, getStudentUser, listStudentProfiles, type StudentProfile, type StudentUser } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import IdCardLayout, { IdCardFace } from '../components/IdCardLayout'

const t = getCommonStrings('en')

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  return parts
    .slice(0, 2)
    .map((part) => part[0]!.toUpperCase())
    .join('')
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <p className="text-[11px] leading-tight text-gray-700">
      <span className="font-medium text-gray-500">{label}: </span>
      {value}
    </p>
  )
}

function CardFront({ student, profile }: { student: StudentUser; profile: StudentProfile }) {
  const school = profile.school ?? student.school
  const sectionLabel = [profile.class?.name, profile.section?.name].filter(Boolean).join(' • ')

  return (
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
  )
}

function CardBack({ student, profile }: { student: StudentUser; profile: StudentProfile }) {
  const school = profile.school ?? student.school
  const primaryGuardian = profile.guardians?.find((guardian) => guardian.is_primary) ?? profile.guardians?.[0] ?? null

  return (
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
  )
}

export default function StudentIdCardPage() {
  const { id } = useParams()
  const navigate = useNavigate()
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
    // Re-runs only if the route's :id changes — token is read once per mount, matching StudentDetailPage's load effect.
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

  if (!profile) {
    return (
      <div className="flex flex-col gap-3">
        <p className="rounded-md border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
          {t['management.idCard.studentProfileRequired']}
        </p>
        <button type="button" className="text-sm font-medium text-primary-600 underline" onClick={() => navigate(`/dashboard/students/${studentId}`)}>
          {t['management.studentDetail.back']}
        </button>
      </div>
    )
  }

  return (
    <IdCardLayout
      title={t['management.idCard.studentTitle']}
      backTo="/dashboard/students"
      front={<CardFront student={student} profile={profile} />}
      back={<CardBack student={student} profile={profile} />}
    />
  )
}
