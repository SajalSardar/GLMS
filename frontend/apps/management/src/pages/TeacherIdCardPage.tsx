import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError, getTeacherUser, listTeacherProfiles, type TeacherProfile, type TeacherUser } from '@ttla/api-client'
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

function CardFront({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile }) {
  const school = teacher.school

  return (
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
  )
}

function CardBack({ teacher, profile }: { teacher: TeacherUser; profile: TeacherProfile }) {
  const school = teacher.school

  return (
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
  )
}

export default function TeacherIdCardPage() {
  const { id } = useParams()
  const navigate = useNavigate()
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
    // Re-runs only if the route's :id changes — token is read once per mount, matching TeacherDetailPage's load effect.
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

  if (!profile) {
    return (
      <div className="flex flex-col gap-3">
        <p className="rounded-md border border-dashed border-gray-200 px-4 py-3 text-sm text-gray-500">
          {t['management.idCard.teacherProfileRequired']}
        </p>
        <button type="button" className="text-sm font-medium text-primary-600 underline" onClick={() => navigate(`/dashboard/teachers/${teacherId}`)}>
          {t['management.teacherDetail.back']}
        </button>
      </div>
    )
  }

  return (
    <IdCardLayout
      title={t['management.idCard.teacherTitle']}
      backTo="/dashboard/teachers"
      front={<CardFront teacher={teacher} profile={profile} />}
      back={<CardBack teacher={teacher} profile={profile} />}
    />
  )
}
