import { useEffect, useState } from 'react'
import {
  ApiError,
  listLeaveApplications,
  reviewLeaveApplication,
  type StudentLeaveApplication,
  type StudentLeaveApplicationStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import Pagination from '../components/Pagination'

const t = getCommonStrings('en')

const PAGE_SIZE = 10

const STATUS_BADGE: Record<StudentLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

const STATUS_LABEL: Record<StudentLeaveApplicationStatus, string> = {
  pending: t['teacher.leaveApplications.statusPending'],
  approved: t['teacher.leaveApplications.statusApproved'],
  rejected: t['teacher.leaveApplications.statusRejected'],
}

function DetailModal({ application, onClose }: { application: StudentLeaveApplication; onClose: () => void }) {
  const studentName = application.student_profile?.user?.name ?? ''
  const studentId = application.student_profile?.student_id
  const roll = application.student_profile?.roll_number
  const className = application.class ? (application.class.bn_name ?? application.class.name) : '—'
  const sectionName = application.section ? (application.section.bn_name ?? application.section.name) : '—'
  const shiftName = application.shift ? (application.shift.bn_name ?? application.shift.name) : '—'
  const versionName = application.class_version ? (application.class_version.bn_name ?? application.class_version.name) : '—'

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="w-full max-w-md overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t['teacher.leaveApplications.detailsTitle']}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">{t['teacher.leaveApplications.detailsTitle']}</h3>
          <button
            type="button"
            className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
            onClick={onClose}
            aria-label={t['management.table.close']}
          >
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 p-5 text-sm">
          <div>
            <p className="font-medium text-gray-900">{studentName}</p>
            {studentId && <p className="text-xs text-gray-400">{studentId}</p>}
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colRoll']}</p>
              <p className="text-gray-800">{roll ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colClass']}</p>
              <p className="text-gray-800">{className}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colSection']}</p>
              <p className="text-gray-800">{sectionName}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colShift']}</p>
              <p className="text-gray-800">{shiftName}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colVersion']}</p>
              <p className="text-gray-800">{versionName}</p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colFrom']}</p>
              <p className="text-gray-800">{application.from_date}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colTo']}</p>
              <p className="text-gray-800">{application.to_date}</p>
            </div>
          </div>

          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colReason']}</p>
            <p className="mt-1 text-gray-700">{application.reason}</p>
          </div>

          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colStatus']}</p>
            <span className={`mt-1 inline-block rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
              {STATUS_LABEL[application.status]}
            </span>
            {application.reviewed_by && (
              <p className="mt-1 text-xs text-gray-400">{t['teacher.leaveApplications.reviewedBy'].replace('{name}', application.reviewed_by.name)}</p>
            )}
          </div>

          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t['teacher.leaveApplications.colComment']}</p>
            <p className="mt-1 text-gray-700">{application.teacher_comment ?? '—'}</p>
          </div>
        </div>

        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
          <button
            type="button"
            className="cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
            onClick={onClose}
          >
            {t['management.table.close']}
          </button>
        </div>
      </div>
    </div>
  )
}

function ReviewModal({
  application,
  onClose,
  onReviewed,
}: {
  application: StudentLeaveApplication
  onClose: () => void
  onReviewed: (updated: StudentLeaveApplication) => void
}) {
  const { token } = useAuth()
  const [decision, setDecision] = useState<'approved' | 'rejected' | null>(null)
  const [comment, setComment] = useState(application.teacher_comment ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (!decision || !token) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await reviewLeaveApplication(application.id, { status: decision, teacher_comment: comment.trim() || null }, token)
      onReviewed(result.data)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : String(caught))
    } finally {
      setSubmitting(false)
    }
  }

  const studentName = application.student_profile?.user?.name ?? ''

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="w-full max-w-md overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t['teacher.leaveApplications.reviewTitle']}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">{t['teacher.leaveApplications.reviewTitle']}</h3>
          <button
            type="button"
            className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
            onClick={onClose}
            aria-label={t['teacher.leaveApplications.cancel']}
          >
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 p-5">
          <div className="text-sm text-gray-700">
            <p className="font-medium text-gray-900">{studentName}</p>
            <p className="mt-1 text-gray-500">{application.reason}</p>
            <p className="mt-1 text-xs text-gray-400">
              {application.from_date} — {application.to_date}
            </p>
          </div>

          <div className="flex gap-3">
            <button
              type="button"
              onClick={() => setDecision('approved')}
              className={`flex-1 cursor-pointer rounded-md border px-3 py-2 text-sm font-semibold transition-colors ${
                decision === 'approved'
                  ? 'border-success-500 bg-success-50 text-success-600'
                  : 'border-gray-200 text-gray-600 hover:border-success-300'
              }`}
            >
              {t['teacher.leaveApplications.approve']}
            </button>
            <button
              type="button"
              onClick={() => setDecision('rejected')}
              className={`flex-1 cursor-pointer rounded-md border px-3 py-2 text-sm font-semibold transition-colors ${
                decision === 'rejected'
                  ? 'border-danger-500 bg-danger-50 text-danger-600'
                  : 'border-gray-200 text-gray-600 hover:border-danger-300'
              }`}
            >
              {t['teacher.leaveApplications.reject']}
            </button>
          </div>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['teacher.leaveApplications.commentLabel']}</span>
            <textarea
              className="min-h-[80px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['teacher.leaveApplications.commentPlaceholder']}
              value={comment}
              onChange={(event) => setComment(event.target.value)}
            />
          </label>

          {error && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}
        </div>

        <div className="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
          <button
            type="button"
            className="cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50"
            onClick={onClose}
            disabled={submitting}
          >
            {t['teacher.leaveApplications.cancel']}
          </button>
          <button
            type="button"
            className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
            onClick={handleSubmit}
            disabled={submitting || !decision}
          >
            {submitting ? t['teacher.leaveApplications.submitting'] : t['teacher.leaveApplications.submit']}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function LeaveApplicationsPage() {
  const { token } = useAuth()

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<StudentLeaveApplication[]>([])
  const [statusFilter, setStatusFilter] = useState<StudentLeaveApplicationStatus | ''>('')
  const [page, setPage] = useState(1)
  const [reviewing, setReviewing] = useState<StudentLeaveApplication | null>(null)
  const [viewing, setViewing] = useState<StudentLeaveApplication | null>(null)

  function load() {
    if (!token) return

    setLoading(true)

    listLeaveApplications({ status: statusFilter || undefined }, token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  // `load` isn't in the dependency array on purpose — it's a plain inline
  // function (new identity every render), not a dependency itself; token
  // and statusFilter are the only two things this effect actually needs
  // to react to.
  useEffect(() => {
    load()
  }, [token, statusFilter])

  function handleReviewed(updated: StudentLeaveApplication) {
    setApplications((previous) => previous.map((application) => (application.id === updated.id ? updated : application)))
    setReviewing(null)
  }

  const totalPages = Math.max(1, Math.ceil(applications.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleApplications = applications.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.leaveApplications.title']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={statusFilter}
          onChange={(event) => {
            setStatusFilter(event.target.value as StudentLeaveApplicationStatus | '')
            setPage(1)
          }}
        >
          <option value="">{t['teacher.leaveApplications.filterAllStatus']}</option>
          <option value="pending">{t['teacher.leaveApplications.statusPending']}</option>
          <option value="approved">{t['teacher.leaveApplications.statusApproved']}</option>
          <option value="rejected">{t['teacher.leaveApplications.statusRejected']}</option>
        </select>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.leaveApplications.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.leaveApplications.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <>
          <div className="mt-6 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[72rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colClass']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colSection']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colShift']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colVersion']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colFrom']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colTo']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.leaveApplications.colAction']}</th>
                </tr>
              </thead>
              <tbody>
                {visibleApplications.map((application, index) => {
                  const studentName = application.student_profile?.user?.name ?? ''
                  const studentId = application.student_profile?.student_id
                  const roll = application.student_profile?.roll_number
                  const className = application.class ? (application.class.bn_name ?? application.class.name) : '—'
                  const sectionName = application.section ? (application.section.bn_name ?? application.section.name) : '—'
                  const shiftName = application.shift ? (application.shift.bn_name ?? application.shift.name) : '—'
                  const versionName = application.class_version ? (application.class_version.bn_name ?? application.class_version.name) : '—'

                  return (
                    <tr key={application.id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                        {(currentPage - 1) * PAGE_SIZE + index + 1}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className="block font-medium text-gray-900">{studentName}</span>
                        {studentId && <span className="block text-xs text-gray-400">{studentId}</span>}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{roll ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{className}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{sectionName}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{shiftName}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{versionName}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                          {STATUS_LABEL[application.status]}
                        </span>
                        {application.reviewed_by && (
                          <span className="mt-1 block text-xs text-gray-400">
                            {t['teacher.leaveApplications.reviewedBy'].replace('{name}', application.reviewed_by.name)}
                          </span>
                        )}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setViewing(application)}
                            aria-label={t['management.table.view']}
                            title={t['management.table.view']}
                            className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:border-primary-300 hover:text-primary-700"
                          >
                            <span className="material-symbols-outlined !text-[18px] leading-none">visibility</span>
                          </button>
                          {application.can_review && (
                            <button
                              type="button"
                              onClick={() => setReviewing(application)}
                              className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-400"
                            >
                              {t['teacher.leaveApplications.review']}
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
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
            previousLabel={t['teacher.leaveApplications.previousPage']}
            nextLabel={t['teacher.leaveApplications.nextPage']}
          />
        </>
      )}

      {reviewing && <ReviewModal application={reviewing} onClose={() => setReviewing(null)} onReviewed={handleReviewed} />}
      {viewing && <DetailModal application={viewing} onClose={() => setViewing(null)} />}
    </section>
  )
}
