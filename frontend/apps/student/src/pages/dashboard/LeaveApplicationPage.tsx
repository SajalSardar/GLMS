import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, createLeaveApplication, listMyLeaveApplications, type StudentLeaveApplication, type StudentLeaveApplicationStatus } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { useLocale } from '../../lib/locale'
import Pagination from './components/Pagination'

const PAGE_SIZE = 10

const STATUS_BADGE: Record<StudentLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

function InfoAlert({ message, tone = 'info' }: { message: string; tone?: 'info' | 'success' }) {
  const toneClasses =
    tone === 'success'
      ? 'border-success-200 bg-success-50 text-success-700'
      : 'border-primary-200 bg-primary-50 text-primary-700'

  return (
    <div className={`mt-6 flex items-center gap-3 rounded-lg border px-4 py-3 text-sm font-medium ${toneClasses}`}>
      <svg
        width={20}
        height={20}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="flex-shrink-0"
      >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v5" />
        <circle cx="12" cy="8" r="0.1" fill="currentColor" stroke="currentColor" strokeWidth={2.4} />
      </svg>
      <p>{message}</p>
    </div>
  )
}

// Dashboard-only page (no public website equivalent, same as Class
// Materials/Class Exams/Class Routine) — a student submits their own
// leave application here (scoped server-side to their exact profile by
// StudentLeaveApplicationController::store()) and sees their own history
// below, including the reviewing teacher's status/comment once decided.
export default function LeaveApplicationPage() {
  const { t } = useLocale()
  const { token } = useAuth()

  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState(false)

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<StudentLeaveApplication[]>([])
  const [page, setPage] = useState(1)

  function loadApplications() {
    if (!token) return

    setLoading(true)

    listMyLeaveApplications(token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  // `loadApplications` isn't in the dependency array on purpose — it's a
  // plain inline function (new identity every render), not a dependency
  // itself; token is the only thing this effect actually needs to react to.
  useEffect(() => {
    loadApplications()
  }, [token])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return

    setSubmitting(true)
    setFormError(null)
    setSubmitSuccess(false)

    try {
      await createLeaveApplication({ from_date: fromDate, to_date: toDate, reason }, token)
      setFromDate('')
      setToDate('')
      setReason('')
      setSubmitSuccess(true)
      loadApplications()
    } catch (caught) {
      // A translated fallback is shown instead of the raw backend
      // message (same reasoning as ExamsPage.tsx's messageFromApiError)
      // — the API's validation text is English-only. The one exception
      // is the specific "profile incomplete" case, which gets its own
      // dedicated, already-translated copy.
      if (caught instanceof ApiError && caught.errors?.student_profile) {
        setFormError(t['student.leaveApplication.noSection'])
      } else {
        setFormError(t['student.leaveApplication.submitError'])
      }
    } finally {
      setSubmitting(false)
    }
  }

  const statusLabel: Record<StudentLeaveApplicationStatus, string> = {
    pending: t['teacher.leaveApplications.statusPending'],
    approved: t['teacher.leaveApplications.statusApproved'],
    rejected: t['teacher.leaveApplications.statusRejected'],
  }

  const totalPages = Math.max(1, Math.ceil(applications.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleApplications = applications.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.leaveApplication.title']}</h1>

      <div className="mt-4 max-w-xl rounded-md bg-white p-5 shadow-sm">
        <h2 className="text-sm font-semibold text-primary-800">{t['student.leaveApplication.formTitle']}</h2>

        <form className="mt-4 flex flex-col gap-4" onSubmit={handleSubmit}>
          <div className="flex flex-wrap gap-4">
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['student.leaveApplication.fromDate']}</span>
              <input
                type="date"
                required
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                value={fromDate}
                onChange={(event) => setFromDate(event.target.value)}
              />
            </label>
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['student.leaveApplication.toDate']}</span>
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
            <span className="font-medium text-gray-700">{t['student.leaveApplication.reason']}</span>
            <textarea
              required
              className="min-h-[90px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['student.leaveApplication.reasonPlaceholder']}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </label>

          {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
          {submitSuccess && (
            <p className="rounded-md bg-success-50 px-4 py-3 text-sm text-success-700">
              {t['student.leaveApplication.submitSuccess']}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="cursor-pointer self-start rounded-md bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#EB1F27] disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? t['student.leaveApplication.submitting'] : t['student.leaveApplication.submit']}
          </button>
        </form>
      </div>

      <h2 className="mt-10 text-lg font-semibold text-primary-800">{t['student.leaveApplication.historyTitle']}</h2>

      {loading && <p className="mt-3 text-sm text-primary-600">{t['student.leaveApplication.loading']}</p>}

      {!loading && applications.length === 0 && <InfoAlert message={t['student.leaveApplication.empty']} />}

      {!loading && applications.length > 0 && (
        <>
          <div className="mt-3 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[40rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.leaveApplication.colFrom']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.leaveApplication.colTo']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.leaveApplication.colReason']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.leaveApplication.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['student.leaveApplication.colComment']}</th>
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
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                        {statusLabel[application.status]}
                      </span>
                    </td>
                    <td className="max-w-[14rem] px-5 py-3.5 text-gray-500">{application.teacher_comment ?? '—'}</td>
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
            previousLabel={t['student.leaveApplication.previousPage']}
            nextLabel={t['student.leaveApplication.nextPage']}
          />
        </>
      )}
    </section>
  )
}
