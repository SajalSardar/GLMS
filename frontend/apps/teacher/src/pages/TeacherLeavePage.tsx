import { useEffect, useState, type FormEvent } from 'react'
import {
  ApiError,
  createTeacherLeaveApplication,
  listMyTeacherLeaveApplications,
  listTeacherColleagues,
  type TeacherColleague,
  type TeacherLeaveApplication,
  type TeacherLeaveApplicationStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import Pagination from '../components/Pagination'

const t = getCommonStrings('en')

const PAGE_SIZE = 10

const STATUS_BADGE: Record<TeacherLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

const STATUS_LABEL: Record<TeacherLeaveApplicationStatus, string> = {
  pending: t['teacher.leaveApplications.statusPending'],
  approved: t['teacher.leaveApplications.statusApproved'],
  rejected: t['teacher.leaveApplications.statusRejected'],
}

// A teacher submits their own leave request here (scoped server-side to
// their own school by TeacherLeaveApplicationController::store()),
// optionally naming a colleague at the same school as a substitute — if
// approved, that substitute gains the ability to record attendance for
// every section this teacher is the class teacher of, for the leave's
// exact date range (see AttendancePolicy::isApprovedDelegateFor()). No
// edit/cancel action: if the dates are wrong, school_admin rejects it and
// the teacher resubmits, same as the student-leave flow.
export default function TeacherLeavePage() {
  const { token } = useAuth()

  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [reason, setReason] = useState('')
  const [delegateId, setDelegateId] = useState('')
  const [colleagues, setColleagues] = useState<TeacherColleague[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState(false)

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])
  const [page, setPage] = useState(1)

  function loadApplications() {
    if (!token) return

    setLoading(true)

    listMyTeacherLeaveApplications(token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadApplications()
  }, [token])

  useEffect(() => {
    if (!token) return

    listTeacherColleagues(token)
      .then((result) => setColleagues(result.data))
      .catch(() => setColleagues([]))
  }, [token])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return

    setSubmitting(true)
    setFormError(null)
    setSubmitSuccess(false)

    try {
      await createTeacherLeaveApplication(
        {
          from_date: fromDate,
          to_date: toDate,
          reason,
          delegate_teacher_id: delegateId ? Number(delegateId) : null,
        },
        token,
      )
      setFromDate('')
      setToDate('')
      setReason('')
      setDelegateId('')
      setSubmitSuccess(true)
      loadApplications()
    } catch (caught) {
      setFormError(caught instanceof ApiError ? caught.message : t['teacher.teacherLeave.submitError'])
    } finally {
      setSubmitting(false)
    }
  }

  const totalPages = Math.max(1, Math.ceil(applications.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleApplications = applications.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.teacherLeave.title']}</h1>

      <div className="mt-4 max-w-xl rounded-md bg-white p-5 shadow-sm">
        <h2 className="text-sm font-semibold text-primary-800">{t['teacher.teacherLeave.formTitle']}</h2>

        <form className="mt-4 flex flex-col gap-4" onSubmit={handleSubmit}>
          <div className="flex flex-wrap gap-4">
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['teacher.teacherLeave.fromDate']}</span>
              <input
                type="date"
                required
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                value={fromDate}
                onChange={(event) => setFromDate(event.target.value)}
              />
            </label>
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['teacher.teacherLeave.toDate']}</span>
              <input
                type="date"
                required
                min={fromDate || undefined}
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                value={toDate}
                onChange={(event) => setToDate(event.target.value)}
              />
            </label>
          </div>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['teacher.teacherLeave.reason']}</span>
            <textarea
              required
              className="min-h-[90px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['teacher.teacherLeave.reasonPlaceholder']}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </label>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['teacher.teacherLeave.delegate']}</span>
            <select
              className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              value={delegateId}
              onChange={(event) => setDelegateId(event.target.value)}
            >
              <option value="">{t['teacher.teacherLeave.delegateNone']}</option>
              {colleagues.map((colleague) => (
                <option key={colleague.id} value={colleague.id}>
                  {colleague.name}
                </option>
              ))}
            </select>
            <span className="text-xs text-gray-400">{t['teacher.teacherLeave.delegateHint']}</span>
          </label>

          {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
          {submitSuccess && (
            <p className="rounded-md bg-success-50 px-4 py-3 text-sm text-success-700">
              {t['teacher.teacherLeave.submitSuccess']}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="cursor-pointer self-start rounded-md bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? t['teacher.teacherLeave.submitting'] : t['teacher.teacherLeave.submit']}
          </button>
        </form>
      </div>

      <h2 className="mt-10 text-lg font-semibold text-primary-800">{t['teacher.teacherLeave.historyTitle']}</h2>

      {loading && <p className="mt-3 text-sm text-primary-600">{t['teacher.teacherLeave.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="mt-3 text-sm text-gray-500">{t['teacher.teacherLeave.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <>
          <div className="mt-3 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[48rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colFrom']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colTo']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colReason']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colSubstitute']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colComment']}</th>
                </tr>
              </thead>
              <tbody>
                {visibleApplications.map((application, index) => (
                  <tr key={application.id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {(currentPage - 1) * PAGE_SIZE + index + 1}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                    <td className="max-w-[18rem] px-5 py-3.5 text-gray-700">{application.reason}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {application.delegate?.name ?? t['teacher.teacherLeave.noSubstitute']}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                        {STATUS_LABEL[application.status]}
                      </span>
                    </td>
                    <td className="max-w-[14rem] px-5 py-3.5 text-gray-500">{application.admin_comment ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={(targetPage) => setPage(targetPage)}
            visibleCount={visibleApplications.length}
            total={applications.length}
            showingLabel={t['management.table.showing']}
            ofLabel={t['management.table.of']}
            resultsLabel={t['management.table.results']}
            previousLabel={t['teacher.teacherLeave.previousPage']}
            nextLabel={t['teacher.teacherLeave.nextPage']}
          />
        </>
      )}
    </section>
  )
}
