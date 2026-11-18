import { useEffect, useState } from 'react'

export interface ToastState {
  type: 'success' | 'error'
  message: string
}

// Shared by every page that needs a transient success/error notification —
// GeoCrudTable's own list actions, and pages like Teacher/Student detail
// that save outside GeoCrudTable's built-in modal.
export function useToast() {
  const [toast, setToast] = useState<ToastState | null>(null)

  useEffect(() => {
    if (!toast) return

    const timer = window.setTimeout(() => setToast(null), 3000)
    return () => window.clearTimeout(timer)
  }, [toast])

  return { toast, showToast: setToast }
}

export function ToastViewport({ toast }: { toast: ToastState | null }) {
  if (!toast) return null

  const isSuccess = toast.type === 'success'

  return (
    <div
      role="status"
      className={`fixed top-4 right-4 z-[60] flex items-center gap-2 rounded-md border px-4 py-3 text-sm font-medium shadow-lg ${
        isSuccess ? 'border-success-100 bg-success-50 text-success-600' : 'border-danger-100 bg-danger-50 text-danger-600'
      }`}
    >
      <span className="material-symbols-outlined !text-[20px] leading-none">{isSuccess ? 'check_circle' : 'error'}</span>
      {toast.message}
    </div>
  )
}
