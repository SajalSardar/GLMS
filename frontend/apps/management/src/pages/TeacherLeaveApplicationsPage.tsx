import { useEffect, useState } from 'react'
import {
  ApiError,
  listTeacherLeaveApplications,
  reviewTeacherLeaveApplication,
  type TeacherLeaveApplication,
  type TeacherLeaveApplicationStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { buttonNeutral, buttonPrimary } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

const STATUS_BADGE: Record<TeacherLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

const STATUS_LABEL: Record<TeacherLeaveApplicationStatus, string> = {
  pending: t['management.teacherLeaveApplications.statusPending'],
  approved: t['management.teacherLeaveApplications.statusApproved'],
  rejected: t['management.teacherLeaveApplications.statusRejected'],
}

function ReviewModal({
  application,
  onClose,
  onReviewed,
}: {
  application: TeacherLeaveApplication
  onClose: () => void
  onReviewed: (updated: TeacherLeaveApplication) => void
}) {
  const { token } = useAuth()
  const [decision, setDecision] = useState<'approved' | 'rejected' | null>(null)
  const [comment, setComment] = useState(application.admin_comment ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (!decision || !token) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await reviewTeacherLeaveApplication(
        application.id,
        { status: decision, admin_comment: comment.trim() || null },
        token,
      )
      onReviewed(result.data)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : String(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="w-full max-w-md overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t['management.teacherLeaveApplications.reviewTitle']}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">{t['management.teacherLeaveApplications.reviewTitle']}</h3>
          <button
            type="button"
            className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
            onClick={onClose}
            aria-label={t['management.table.cancel']}
          >
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 p-5">
          <div className="text-sm text-gray-700">
            <p className="font-medium text-gray-900">{application.teacher?.name}</p>
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
              {t['management.teacherLeaveApplications.approve']}
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
              {t['management.teacherLeaveApplications.reject']}
            </button>
          </div>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['management.teacherLeaveApplications.commentLabel']}</span>
            <textarea
              className="min-h-[80px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['management.teacherLeaveApplications.commentPlaceholder']}
              value={comment}
              onChange={(event) => setComment(event.target.value)}
            />
          </label>

          {error && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}
        </div>

        <div className="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
          <button type="button" className={buttonNeutral} onClick={onClose} disabled={submitting}>
            {t['management.table.cancel']}
          </button>
          <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting || !decision}>
            {submitting ? t['management.teacherLeaveApplications.submitting'] : t['management.teacherLeaveApplications.submit']}
          </button>
        </div>
      </div>
    </div>
  )
}

// school_admin reviews their own school's teacher leave requests here
// (TenantScope already narrows the list; national_admin could technically
// call the same backend endpoints but gets no review UI — see the
// national_admin-only read-only "Teachers on Leave" page instead).
export default function TeacherLeaveApplicationsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])
  const [statusFilter, setStatusFilter] = useState<TeacherLeaveApplicationStatus | ''>('')
  const [reviewing, setReviewing] = useState<TeacherLeaveApplication | null>(null)

  function load() {
    if (!token) return

    setLoading(true)

    listTeacherLeaveApplications({ status: statusFilter || undefined }, token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
  }, [token, statusFilter])

  function handleReviewed(updated: TeacherLeaveApplication) {
    setApplications((previous) => previous.map((application) => (application.id === updated.id ? updated : application)))
    setReviewing(null)
    showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
  }

  return (
    <section className="space-y-5">
      <h2 className="text-lg font-semibold text-gray-800">{t['management.teacherLeaveApplications.title']}</h2>

      <div className="flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={statusFilter}
          onChange={(event) => setStatusFilter(event.target.value as TeacherLeaveApplicationStatus | '')}
        >
          <option value="">{t['management.teacherLeaveApplications.filterAllStatus']}</option>
          <option value="pending">{t['management.teacherLeaveApplications.statusPending']}</option>
          <option value="approved">{t['management.teacherLeaveApplications.statusApproved']}</option>
          <option value="rejected">{t['management.teacherLeaveApplications.statusRejected']}</option>
        </select>
      </div>

      {loading && <p className="text-sm text-gray-500">{t['management.teacherLeaveApplications.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="text-sm text-gray-500">{t['management.teacherLeaveApplications.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <div className="overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[64rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colFrom']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTo']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colReason']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colSubstitute']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colStatus']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.actions']}</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((application, index) => (
                <tr key={application.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{application.teacher?.name}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                  <td className="max-w-[16rem] px-5 py-3.5 text-gray-700">{application.reason}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.delegate?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                      {STATUS_LABEL[application.status]}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {application.can_review && (
                      <button
                        type="button"
                        className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                        onClick={() => setReviewing(application)}
                        aria-label={t['management.teacherLeaveApplications.reviewTitle']}
                      >
                        <span className="material-symbols-outlined !text-md">rate_review</span>
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {reviewing && (
        <ReviewModal application={reviewing} onClose={() => setReviewing(null)} onReviewed={handleReviewed} />
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
