import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError, type PaginatedResponse } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from './SearchableSelect'
import { buttonNeutral, buttonPrimary, inputClass } from './styles'
import { useToast, ToastViewport } from './Toast'

const t = getCommonStrings('en')

export interface GeoColumn<T> {
  key: string
  label: string
  render: (row: T) => ReactNode
}

export interface GeoFilters {
  search: string
  parentId?: number
  secondaryParentId?: number
  tertiaryParentId?: number
  quaternaryParentId?: number
}

export interface GeoParentFilter {
  label: string
  allLabel: string
  options: Array<{ id: number; label: string }>
}

// Adapts an endpoint that returns a flat, unpaginated array (Books,
// Chapters, Chapter Videos — all scoped by a parent filter already, so
// the full result set is small) into the single-page shape GeoCrudTable's
// `fetchPage` expects.
export function toSinglePage<T>(items: T[]): PaginatedResponse<T> {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: items.length || 1, total: items.length },
  }
}

export function GeoField({
  label,
  required,
  error,
  children,
  className,
}: {
  label: string
  required?: boolean
  error?: string
  children: ReactNode
  className?: string
}) {
  return (
    <label className={`flex flex-col gap-1.5 text-sm${className ? ` ${className}` : ''}`}>
      <span className="font-medium text-gray-700">
        {label}
        {required && <span className="text-danger-500"> *</span>}
      </span>
      {children}
      {error && (
        <span className="text-xs text-danger-500" role="alert">
          {error}
        </span>
      )}
    </label>
  )
}

export interface GeoCrudTableProps<T extends { id: number }> {
  title: string
  columns: GeoColumn<T>[]
  // The six props below drive the built-in add/edit modal. They're optional
  // because a page that fully replaces add/edit with its own page (via
  // `onAdd`/`onEdit`, below) never opens that modal, so it never needs them.
  initialValues?: Record<string, string>
  requiredFields?: string[]
  valuesFromRow?: (row: T) => Record<string, string>
  renderFields?: (
    values: Record<string, string>,
    onChange: (key: string, value: string) => void,
    fieldErrors: Record<string, string>,
    editingId: number | null,
  ) => ReactNode
  fetchPage: (page: number, filters: GeoFilters) => Promise<PaginatedResponse<T>>
  createRow?: (values: Record<string, string>, token: string) => Promise<unknown>
  updateRow?: (id: number, values: Record<string, string>, token: string) => Promise<unknown>
  deleteRow: (id: number, token: string) => Promise<void>
  // When provided, the corresponding action is hidden unless
  // useAuth().can(permission) is true — the Add button for
  // createPermission, the row's edit (pencil) icon for updatePermission,
  // the row's delete (trash) icon for deletePermission. Omitted (the
  // default), the action is always shown, exactly as before this prop
  // existed — this keeps every existing page's behavior unchanged. This
  // is a UX improvement only: every write endpoint already enforces
  // permissions server-side via policies regardless of what this hides.
  createPermission?: string
  updatePermission?: string
  deletePermission?: string
  parentFilter?: GeoParentFilter
  secondaryFilter?: GeoParentFilter
  tertiaryFilter?: GeoParentFilter
  quaternaryFilter?: GeoParentFilter
  renderDetails?: (row: T) => ReactNode
  onView?: (row: T) => void
  // Override the "Add new" button / row's edit (pencil) button to navigate
  // elsewhere (e.g. a dedicated page) instead of opening the built-in modal.
  onAdd?: () => void
  onEdit?: (row: T) => void
  // Extra per-row buttons rendered in the Actions cell, before the
  // view/edit/delete icons this component always provides — for an action
  // specific to one page's data (e.g. Books' "open PDF") that doesn't
  // belong in this shared component as a built-in.
  rowActions?: (row: T) => ReactNode
}

const REQUIRED_MESSAGE = 'This field is required.'

const pageButtonBase =
  'flex h-[31px] w-[31px] cursor-pointer items-center justify-center rounded-md border border-gray-100 text-sm transition-colors duration-150'
const pageButtonInactive = 'text-gray-600 hover:border-primary-500 hover:bg-primary-500 hover:text-white'
const pageButtonActive = 'border-primary-500 bg-primary-500 text-white'

// A tenant-scoped actor's filter (e.g. a school_admin's school, or a
// district_officer's district) resolves to exactly one option once the
// options finish loading — auto-select it instead of leaving the select on
// "All <things>", since there's nothing else to pick.
function useSoleOptionAutoSelect(filter: GeoParentFilter | undefined, setValue: (value: string) => void): void {
  const soleId = filter && filter.options.length === 1 ? filter.options[0].id : undefined

  useEffect(() => {
    if (soleId !== undefined) setValue(String(soleId))
  }, [soleId, setValue])
}

function getPageNumbers(current: number, last: number): Array<number | 'ellipsis'> {
  if (last <= 7) {
    return Array.from({ length: last }, (_, index) => index + 1)
  }

  const pages = new Set<number>([1, last, current, current - 1, current + 1, current - 2, current + 2])
  const sorted = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b)

  const result: Array<number | 'ellipsis'> = []
  let previous = 0
  for (const page of sorted) {
    if (page - previous > 1) result.push('ellipsis')
    result.push(page)
    previous = page
  }
  return result
}

export default function GeoCrudTable<T extends { id: number }>({
  title,
  columns,
  initialValues,
  requiredFields,
  valuesFromRow,
  renderFields,
  fetchPage,
  createRow,
  updateRow,
  deleteRow,
  parentFilter,
  secondaryFilter,
  tertiaryFilter,
  quaternaryFilter,
  renderDetails,
  onView,
  onAdd,
  onEdit,
  rowActions,
  createPermission,
  updatePermission,
  deletePermission,
}: GeoCrudTableProps<T>) {
  const { token, can } = useAuth()

  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const [rows, setRows] = useState<T[]>([])
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [listError, setListError] = useState<string | null>(null)

  const [search, setSearch] = useState('')
  const [parentId, setParentId] = useState('')
  const [secondaryParentId, setSecondaryParentId] = useState('')
  const [tertiaryParentId, setTertiaryParentId] = useState('')
  const [quaternaryParentId, setQuaternaryParentId] = useState('')

  useSoleOptionAutoSelect(parentFilter, setParentId)
  useSoleOptionAutoSelect(secondaryFilter, setSecondaryParentId)
  useSoleOptionAutoSelect(tertiaryFilter, setTertiaryParentId)
  useSoleOptionAutoSelect(quaternaryFilter, setQuaternaryParentId)

  const [formOpen, setFormOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [values, setValues] = useState<Record<string, string>>(initialValues ?? {})
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const [viewingRow, setViewingRow] = useState<T | null>(null)

  const { toast, showToast } = useToast()

  const load = useCallback(
    (targetPage: number) => {
      setLoading(true)
      setListError(null)

      fetchPage(targetPage, {
        search,
        parentId: parentId ? Number(parentId) : undefined,
        secondaryParentId: secondaryParentId ? Number(secondaryParentId) : undefined,
        tertiaryParentId: tertiaryParentId ? Number(tertiaryParentId) : undefined,
        quaternaryParentId: quaternaryParentId ? Number(quaternaryParentId) : undefined,
      })
        .then((result) => {
          setRows(result.data)
          setLastPage(result.meta.last_page)
          setTotal(result.meta.total)
          setPage(result.meta.current_page)
          setPerPage(result.meta.per_page)
        })
        .catch((error: unknown) => {
          setListError(error instanceof ApiError ? error.message : String(error))
        })
        .finally(() => setLoading(false))
    },
    [fetchPage, search, parentId, secondaryParentId, tertiaryParentId, quaternaryParentId],
  )

  // `load` (and the `fetchPage` prop it wraps) gets a new identity on every
  // render of the page component that owns this table, since each page
  // passes an inline arrow function. A ref keeps this effect running
  // exactly once on mount instead of re-fetching on every parent render.
  const loadRef = useRef(load)
  useEffect(() => {
    loadRef.current = load
  })

  useEffect(() => {
    loadRef.current(1)
  }, [])

  function applyFilters(event: FormEvent) {
    event.preventDefault()
    load(1)
  }

  function goToPage(targetPage: number) {
    load(targetPage)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function openCreateForm() {
    setEditingId(null)
    setValues(initialValues ?? {})
    setFormError(null)
    setFieldErrors({})
    setFormOpen(true)
  }

  function openEditForm(row: T) {
    setEditingId(row.id)
    setValues(valuesFromRow ? valuesFromRow(row) : {})
    setFormError(null)
    setFieldErrors({})
    setFormOpen(true)
  }

  function closeForm() {
    setFormOpen(false)
    setFormError(null)
    setFieldErrors({})
  }

  function openDeleteConfirm(id: number) {
    setDeletingId(id)
    setDeleteError(null)
  }

  function closeDeleteConfirm() {
    setDeletingId(null)
    setDeleteError(null)
  }

  useEffect(() => {
    if (!formOpen && deletingId === null && viewingRow === null) return

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      // The create/edit form is deliberately excluded here and from the
      // backdrop's onClick below — it only closes via its own Cancel/×
      // button, so an accidental Escape or outside click never discards
      // form progress.
      if (deletingId !== null) closeDeleteConfirm()
      if (viewingRow !== null) setViewingRow(null)
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [formOpen, deletingId, viewingRow])

  async function handleSubmit() {
    if (!token) return

    const missing: Record<string, string> = {}
    for (const key of requiredFields ?? []) {
      if (!values[key]?.trim()) {
        missing[key] = REQUIRED_MESSAGE
      }
    }

    if (Object.keys(missing).length > 0) {
      setFieldErrors(missing)
      setFormError(null)
      return
    }

    setSubmitting(true)
    setFormError(null)
    setFieldErrors({})

    try {
      if (editingId) {
        await updateRow?.(editingId, values, token)
      } else {
        await createRow?.(values, token)
      }

      setFormOpen(false)
      showToast({
        type: 'success',
        message: editingId ? t['management.table.updatedSuccess'] : t['management.table.createdSuccess'],
      })
      load(editingId ? page : 1)
    } catch (error) {
      if (error instanceof ApiError && error.errors) {
        const mapped: Record<string, string> = {}
        for (const [key, messages] of Object.entries(error.errors)) {
          mapped[key] = messages[0]
        }
        setFieldErrors(mapped)
      } else {
        const message = error instanceof ApiError ? error.message : String(error)
        setFormError(message)
        showToast({ type: 'error', message })
      }
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete() {
    if (!token || deletingId === null) return

    setDeleting(true)
    setDeleteError(null)

    try {
      await deleteRow(deletingId, token)
      setDeletingId(null)
      load(rows.length === 1 && page > 1 ? page - 1 : page)
      showToast({ type: 'success', message: t['management.table.deletedSuccess'] })
    } catch (error) {
      const message = error instanceof ApiError ? error.message : String(error)
      setDeleteError(message)
      showToast({ type: 'error', message })
    } finally {
      setDeleting(false)
    }
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-gray-800">{title}</h2>
        {(!createPermission || can(createPermission)) && (
          <button
            type="button"
            className={`${buttonPrimary} inline-flex items-center gap-1.5`}
            onClick={() => (onAdd ? onAdd() : openCreateForm())}
          >
            <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
            {t['management.table.addNew']}
          </button>
        )}
      </div>

      <form className="flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm" onSubmit={applyFilters}>
        <input
          type="search"
          placeholder={t['management.table.searchPlaceholder']}
          className={`${inputClass} min-w-[220px] flex-1`}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
        {parentFilter && (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={parentId}
              onChange={setParentId}
              placeholder={parentFilter.allLabel}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: parentFilter.allLabel },
                ...parentFilter.options.map((option) => ({ value: String(option.id), label: option.label })),
              ]}
            />
          </div>
        )}
        {secondaryFilter && (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={secondaryParentId}
              onChange={setSecondaryParentId}
              placeholder={secondaryFilter.allLabel}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: secondaryFilter.allLabel },
                ...secondaryFilter.options.map((option) => ({ value: String(option.id), label: option.label })),
              ]}
            />
          </div>
        )}
        {tertiaryFilter && (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={tertiaryParentId}
              onChange={setTertiaryParentId}
              placeholder={tertiaryFilter.allLabel}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: tertiaryFilter.allLabel },
                ...tertiaryFilter.options.map((option) => ({ value: String(option.id), label: option.label })),
              ]}
            />
          </div>
        )}
        {quaternaryFilter && (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={quaternaryParentId}
              onChange={setQuaternaryParentId}
              placeholder={quaternaryFilter.allLabel}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: quaternaryFilter.allLabel },
                ...quaternaryFilter.options.map((option) => ({ value: String(option.id), label: option.label })),
              ]}
            />
          </div>
        )}
        <button type="submit" className={buttonNeutral}>
          {t['management.table.filter']}
        </button>
      </form>

      {listError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{listError}</p>}

      <div className="overflow-x-auto rounded-md bg-white shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
            <tr>
              <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
              {columns.map((column) => (
                <th key={column.key} className="px-5 py-3 text-left whitespace-nowrap">
                  {column.label}
                </th>
              ))}
              <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.actions']}</th>
            </tr>
          </thead>
          <tbody className={loading ? 'opacity-60' : undefined}>
            {loading && rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 2} className="px-5 py-6 text-center text-gray-500">
                  {t['management.table.loading']}
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 2} className="px-5 py-6 text-center text-gray-500">
                  {t['management.table.empty']}
                </td>
              </tr>
            ) : (
              rows.map((row, index) => (
                <tr key={row.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                    {total - ((page - 1) * perPage + index)}
                  </td>
                  {columns.map((column) => (
                    <td key={column.key} className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {column.render(row)}
                    </td>
                  ))}
                  <td className="px-5 py-3.5">
                    <div className="flex items-center gap-[9px]">
                      {rowActions?.(row)}
                      {(onView || renderDetails) && (
                        <button
                          type="button"
                          className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                          onClick={() => (onView ? onView(row) : setViewingRow(row))}
                          aria-label={t['management.table.view']}
                        >
                          <span className="material-symbols-outlined !text-md">visibility</span>
                        </button>
                      )}
                      {(!updatePermission || can(updatePermission)) && (
                        <button
                          type="button"
                          className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                          onClick={() => (onEdit ? onEdit(row) : openEditForm(row))}
                          aria-label={t['management.table.edit']}
                        >
                          <span className="material-symbols-outlined !text-md">edit</span>
                        </button>
                      )}
                      {(!deletePermission || can(deletePermission)) && (
                        <button
                          type="button"
                          className="cursor-pointer leading-none text-danger-500 transition-colors duration-150 hover:text-danger-600"
                          onClick={() => openDeleteConfirm(row.id)}
                          aria-label={t['management.table.delete']}
                        >
                          <span className="material-symbols-outlined !text-md">delete</span>
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
        <p className="text-sm text-gray-500">
          {t['management.table.showing']} {rows.length} {t['management.table.of']} {total}{' '}
          {t['management.table.results']}
        </p>
        <ol className="flex items-center gap-[3px]">
          <li>
            <button
              type="button"
              className={`${pageButtonBase} ${pageButtonInactive} disabled:pointer-events-none disabled:opacity-40`}
              onClick={() => goToPage(page - 1)}
              disabled={page <= 1 || loading}
              aria-label={t['management.table.prev']}
            >
              <span className="material-symbols-outlined !text-[18px]">chevron_left</span>
            </button>
          </li>
          {getPageNumbers(page, lastPage).map((entry, index) =>
            entry === 'ellipsis' ? (
              <li key={`ellipsis-${index}`} className="px-1 text-sm text-gray-400">
                …
              </li>
            ) : (
              <li key={entry}>
                <button
                  type="button"
                  className={`${pageButtonBase} ${entry === page ? pageButtonActive : pageButtonInactive}`}
                  onClick={() => goToPage(entry)}
                  disabled={loading}
                  aria-current={entry === page ? 'page' : undefined}
                >
                  {entry}
                </button>
              </li>
            ),
          )}
          <li>
            <button
              type="button"
              className={`${pageButtonBase} ${pageButtonInactive} disabled:pointer-events-none disabled:opacity-40`}
              onClick={() => goToPage(page + 1)}
              disabled={page >= lastPage || loading}
              aria-label={t['management.table.next']}
            >
              <span className="material-symbols-outlined !text-[18px]">chevron_right</span>
            </button>
          </li>
        </ol>
      </div>

      {formOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5">
          <div
            className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-md bg-white shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-label={editingId ? t['management.table.edit'] : t['management.table.addNew']}
          >
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
              <h3 className="text-base font-semibold text-gray-800">
                {editingId ? t['management.table.edit'] : t['management.table.addNew']}
              </h3>
              <button
                type="button"
                className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
                onClick={closeForm}
                aria-label={t['management.table.cancel']}
              >
                ×
              </button>
            </div>

            <div className="flex flex-col gap-4 overflow-y-auto p-5">
              {renderFields?.(
                values,
                (key, value) => setValues((prev) => ({ ...prev, [key]: value })),
                fieldErrors,
                editingId,
              )}

              {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
            </div>

            <div className="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
              <button type="button" className={buttonNeutral} onClick={closeForm} disabled={submitting}>
                {t['management.table.cancel']}
              </button>
              <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
                {submitting ? t['management.table.saving'] : t['management.table.save']}
              </button>
            </div>
          </div>
        </div>
      )}

      {deletingId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={closeDeleteConfirm}>
          <div
            className="w-full max-w-sm overflow-hidden rounded-md bg-white shadow-2xl"
            onClick={(event) => event.stopPropagation()}
            role="alertdialog"
            aria-modal="true"
            aria-label={t['management.table.deleteTitle']}
          >
            <div className="flex flex-col items-center gap-3 px-6 pt-6 pb-4 text-center">
              <span className="flex h-12 w-12 items-center justify-center rounded-full bg-danger-50 text-danger-500">
                <span className="material-symbols-outlined !text-2xl">delete</span>
              </span>
              <h3 className="text-base font-semibold text-gray-800">{t['management.table.deleteTitle']}</h3>
              <p className="text-sm text-gray-500">{t['management.table.confirmDelete']}</p>

              {deleteError && (
                <p className="w-full rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{deleteError}</p>
              )}
            </div>

            <div className="flex justify-center gap-3 border-t border-gray-100 px-5 py-4">
              <button type="button" className={buttonNeutral} onClick={closeDeleteConfirm} disabled={deleting}>
                {t['management.table.confirmNo']}
              </button>
              <button
                type="button"
                className="cursor-pointer rounded-md bg-danger-500 px-4 py-2 text-sm font-medium text-white transition-colors duration-150 hover:bg-danger-400 disabled:cursor-not-allowed disabled:opacity-60"
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? t['management.table.deleting'] : t['management.table.confirmYes']}
              </button>
            </div>
          </div>
        </div>
      )}

      {renderDetails && viewingRow !== null && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5"
          onClick={() => setViewingRow(null)}
        >
          <div
            className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-md flex-col overflow-hidden rounded-md bg-white shadow-2xl"
            onClick={(event) => event.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-label={t['management.table.view']}
          >
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
              <h3 className="text-base font-semibold text-gray-800">{t['management.table.view']}</h3>
              <button
                type="button"
                className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
                onClick={() => setViewingRow(null)}
                aria-label={t['management.table.close']}
              >
                ×
              </button>
            </div>

            <div className="flex flex-col gap-3 overflow-y-auto p-5">{renderDetails(viewingRow)}</div>

            <div className="flex justify-end border-t border-gray-100 px-5 py-4">
              <button type="button" className={buttonNeutral} onClick={() => setViewingRow(null)}>
                {t['management.table.close']}
              </button>
            </div>
          </div>
        </div>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
