import { useEffect, useState } from 'react'
import { listTeacherLeaveApplications, type TeacherLeaveApplication } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Read-only, national_admin only — every teacher currently on approved
// leave (today within from_date/to_date), across every school. No
// approve/reject action: that stays exclusively a school_admin
// capability (see TeacherLeaveApplicationsPage).
export default function TeachersOnLeavePage() {
  const { token } = useAuth()
  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])

  useEffect(() => {
    if (!token) return

    setLoading(true)

    listTeacherLeaveApplications({ current: true }, token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }, [token])

  return (
    <section className="space-y-5">
      <h2 className="text-lg font-semibold text-gray-800">{t['management.teachersOnLeave.title']}</h2>

      {loading && <p className="text-sm text-gray-500">{t['management.teachersOnLeave.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="text-sm text-gray-500">{t['management.teachersOnLeave.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <div className="overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teachersOnLeave.colSchool']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colFrom']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTo']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colSubstitute']}</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((application, index) => (
                <tr key={application.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{application.teacher?.name}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.teacher?.school_name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.delegate?.name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
