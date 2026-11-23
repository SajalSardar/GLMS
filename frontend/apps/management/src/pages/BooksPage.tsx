import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ApiError,
  createBook,
  deleteBook,
  deleteBookPdf,
  getBookPdfLinks,
  listAcademicYears,
  listBooks,
  listClasses,
  listClassVersions,
  listSubjects,
  listVersions,
  updateBook,
  uploadBookPdf,
  type AcademicYear,
  type Book,
  type ClassVersion,
  type SchoolClass,
  type Subject,
  type Version,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { buttonNeutral, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function matchesSearch(name: string, bnName: string | null, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return name.toLowerCase().includes(needle) || (bnName ?? '').toLowerCase().includes(needle)
}

function ActiveBadge({ isActive }: { isActive: boolean }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isActive ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
      }`}
    >
      {isActive ? t['management.fields.yes'] : t['management.fields.no']}
    </span>
  )
}

// A book's "Class Version" dropdown used to list every class_versions row
// nationally, labeled only "Bangla"/"English" — indistinguishable between
// classes. This cascade fixes that: pick a Class first (from the national
// list), then the Version dropdown only ever shows that one class's
// class_versions rows, so "Bangla"/"English" alone is unambiguous again.
// Owns its own class-scoped fetch (keyed on `classId`) since this
// component only ever sees `values`/`onChange` through GeoCrudTable's
// renderFields callback, not as page-level state it could otherwise
// effect on directly.
function ClassVersionCascadeFields({
  classId,
  classVersionId,
  classes,
  onChange,
  fieldErrors,
}: {
  classId: string
  classVersionId: string
  classes: SchoolClass[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])

  useEffect(() => {
    if (!classId) {
      setClassVersions([])
      return
    }

    let active = true

    listClassVersions({ classId: Number(classId), perPage: 500 })
      .then((result) => {
        if (active) setClassVersions(result.data)
      })
      .catch(() => {
        if (active) setClassVersions([])
      })

    return () => {
      active = false
    }
  }, [classId])

  const classOptions = classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))
  const versionOptions = classVersions.map((version) => ({ value: String(version.id), label: version.name }))

  return (
    <>
      <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
        <SearchableSelect
          value={classId}
          onChange={(next) => {
            onChange('class_id', next)
            onChange('class_version_id', '')
          }}
          placeholder={t['management.fields.class']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={classOptions}
        />
      </GeoField>

      <GeoField label={t['management.fields.version']} required error={fieldErrors.class_version_id}>
        <SearchableSelect
          value={classVersionId}
          onChange={(next) => onChange('class_version_id', next)}
          placeholder={t['management.fields.version']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={versionOptions}
          className={!classId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

const initialValues = {
  class_id: '',
  class_version_id: '',
  subject_id: '',
  academic_year_id: '',
  name: '',
  bn_name: '',
  order_index: '',
  description: '',
  is_active: 'true',
}

const requiredFields = ['class_id', 'class_version_id', 'subject_id', 'academic_year_id', 'name']

function toCreatePayload(values: Record<string, string>) {
  return {
    class_version_id: Number(values.class_version_id),
    subject_id: Number(values.subject_id),
    academic_year_id: Number(values.academic_year_id),
    name: values.name,
    bn_name: values.bn_name || null,
    order_index: values.order_index ? Number(values.order_index) : null,
    description: values.description || null,
    is_active: values.is_active === 'true',
  }
}

function toUpdatePayload(values: Record<string, string>) {
  return {
    name: values.name,
    bn_name: values.bn_name || null,
    order_index: values.order_index ? Number(values.order_index) : null,
    description: values.description || null,
    is_active: values.is_active === 'true',
  }
}

export default function BooksPage() {
  const navigate = useNavigate()
  const { token } = useAuth()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [versions, setVersions] = useState<Version[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])

  const [pdfFile, setPdfFile] = useState<File | null>(null)
  const [pdfBusy, setPdfBusy] = useState(false)
  const [pdfError, setPdfError] = useState<string | null>(null)

  // Separate from the edit-form's own pdfError above — this one surfaces
  // failures from the row-level "View PDF" action, which renders outside
  // GeoCrudTable's modal (and outside its own internal toast), so it needs
  // its own toast viewport to ever reach the user.
  const { toast, showToast } = useToast()

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    // The national Bangla/English catalog — used only for the top-of-page
    // filter, which is deliberately a simple 2-option toggle rather than
    // the per-class class_versions list the create form's cascade uses.
    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))

    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  const subjectOptions = subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))
  const academicYearOptions = academicYears.map((year) => ({ value: String(year.id), label: year.name }))

  async function openBookPdf(book: Book) {
    if (!book.has_available_pdf) return

    // Opened synchronously, before the async link-mint call, so it isn't
    // blocked as an unsolicited popup — the same pattern the student
    // app's own book viewer already uses.
    const tab = window.open('', '_blank')

    try {
      const links = await getBookPdfLinks(book.id, token ?? undefined)
      if (tab) {
        tab.location.href = links.preview_url
      } else {
        window.open(links.preview_url, '_blank')
      }
    } catch (error) {
      if (tab) tab.close()
      showToast({
        type: 'error',
        message: error instanceof ApiError ? error.message : t['management.books.viewPdfFailed'],
      })
    }
  }

  const columns: GeoColumn<Book>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => row.class_version?.class?.name ?? '—',
    },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.subject?.name ?? row.subject_id },
    {
      key: 'version',
      label: t['management.fields.version'],
      render: (row) => row.class_version?.name ?? row.class_version_id,
    },
    {
      key: 'academic_year',
      label: t['management.books.academicYear'],
      render: (row) => row.academic_year?.name ?? row.academic_year_id ?? '—',
    },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => <ActiveBadge isActive={row.is_active} />,
    },
    {
      key: 'chapters',
      label: t['management.chapters.title'],
      render: (row) => (
        <button
          type="button"
          className="cursor-pointer font-medium text-primary-600 hover:underline"
          onClick={() => navigate(`/dashboard/class-materials/books/${row.id}/chapters`)}
        >
          {t['management.books.manageChapters']}
        </button>
      ),
    },
  ]

  return (
    <>
      <GeoCrudTable<Book>
        title={t['management.books.title']}
        columns={columns}
        initialValues={initialValues}
        requiredFields={requiredFields}
        valuesFromRow={(row) => ({
          class_id: row.class_version?.class_id != null ? String(row.class_version.class_id) : '',
          class_version_id: String(row.class_version_id),
          // Edit-mode display only (class_id/class_version_id are immutable
          // after creation, same as subject_id/academic_year_id below) —
          // read straight off the row rather than re-fetched, since the
          // cascade's own class-scoped list isn't loaded in edit mode.
          class_name: row.class_version?.class?.name ?? '',
          version_name: row.class_version?.name ?? '',
          subject_id: String(row.subject_id),
          academic_year_id: row.academic_year_id ? String(row.academic_year_id) : '',
          name: row.name,
          bn_name: row.bn_name ?? '',
          order_index: row.order_index !== null ? String(row.order_index) : '',
          description: row.description ?? '',
          is_active: String(row.is_active),
          pdf_original_filename: row.pdf_original_filename ?? '',
          has_available_pdf: row.has_available_pdf ? 'true' : '',
        })}
        renderFields={(values, onChange, fieldErrors, editingId) => (
          <>
            {editingId ? (
              <>
                <GeoField label={t['management.fields.class']}>
                  <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.class_name} disabled />
                </GeoField>
                <GeoField label={t['management.fields.version']}>
                  <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.version_name} disabled />
                </GeoField>
              </>
            ) : (
              <ClassVersionCascadeFields
                classId={values.class_id}
                classVersionId={values.class_version_id}
                classes={classes}
                onChange={onChange}
                fieldErrors={fieldErrors}
              />
            )}
  
            {editingId ? (
              <GeoField label={t['management.fields.subject']}>
                <input
                  className={`${inputClass} cursor-not-allowed opacity-60`}
                  value={subjects.find((subject) => String(subject.id) === values.subject_id)?.name ?? values.subject_id}
                  disabled
                />
              </GeoField>
            ) : (
              <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
                <SearchableSelect
                  value={values.subject_id}
                  onChange={(next) => onChange('subject_id', next)}
                  placeholder={t['management.fields.subject']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={subjectOptions}
                />
              </GeoField>
            )}
  
            {editingId ? (
              <GeoField label={t['management.books.academicYear']}>
                <input
                  className={`${inputClass} cursor-not-allowed opacity-60`}
                  value={
                    academicYears.find((year) => String(year.id) === values.academic_year_id)?.name ??
                    values.academic_year_id
                  }
                  disabled
                />
              </GeoField>
            ) : (
              <GeoField label={t['management.books.academicYear']} required error={fieldErrors.academic_year_id}>
                <SearchableSelect
                  value={values.academic_year_id}
                  onChange={(next) => onChange('academic_year_id', next)}
                  placeholder={t['management.books.academicYear']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={academicYearOptions}
                />
              </GeoField>
            )}
  
            <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
              <input
                className={inputClass}
                value={values.name}
                onChange={(event) => onChange('name', event.target.value)}
              />
            </GeoField>
  
            <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
              <input
                className={inputClass}
                value={values.bn_name}
                onChange={(event) => onChange('bn_name', event.target.value)}
              />
            </GeoField>
  
            <GeoField label={t['management.fields.order']} error={fieldErrors.order_index}>
              <input
                type="number"
                min={0}
                className={inputClass}
                value={values.order_index}
                onChange={(event) => onChange('order_index', event.target.value)}
              />
            </GeoField>
  
            <GeoField label={t['management.fields.description']} error={fieldErrors.description}>
              <textarea
                className={`${inputClass} h-auto py-2`}
                rows={3}
                value={values.description}
                onChange={(event) => onChange('description', event.target.value)}
              />
            </GeoField>
  
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={values.is_active === 'true'}
                onChange={(event) => onChange('is_active', String(event.target.checked))}
              />
              {t['management.fields.isActive']}
            </label>
  
            {/* Uploading requires an existing book row (POST /books/{id}/pdf) — create-only has nothing to attach a PDF to yet. */}
            {editingId && (
              <div className="col-span-1 flex flex-col gap-3 rounded-md border border-gray-100 p-4 sm:col-span-2">
                <span className="text-sm font-medium text-gray-700">{t['management.books.pdf']}</span>
  
                {values.pdf_original_filename ? (
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-sm text-gray-600">{values.pdf_original_filename}</span>
                    <button
                      type="button"
                      className={buttonNeutral}
                      disabled={pdfBusy}
                      onClick={async () => {
                        if (!token) return
                        setPdfBusy(true)
                        setPdfError(null)
                        try {
                          await deleteBookPdf(editingId, token)
                          onChange('pdf_original_filename', '')
                          onChange('has_available_pdf', '')
                        } catch (error) {
                          setPdfError(error instanceof ApiError ? error.message : String(error))
                        } finally {
                          setPdfBusy(false)
                        }
                      }}
                    >
                      {t['management.books.removePdf']}
                    </button>
                  </div>
                ) : (
                  <p className="text-sm text-gray-500">{t['management.books.noPdfYet']}</p>
                )}
  
                <div className="flex flex-wrap items-center gap-3">
                  <input
                    type="file"
                    accept="application/pdf"
                    onChange={(event) => setPdfFile(event.target.files?.[0] ?? null)}
                    className="text-sm text-gray-600"
                  />
                  <button
                    type="button"
                    className={buttonNeutral}
                    disabled={!pdfFile || pdfBusy}
                    onClick={async () => {
                      if (!token || !pdfFile) return
                      setPdfBusy(true)
                      setPdfError(null)
                      try {
                        const formData = new FormData()
                        formData.append('pdf', pdfFile)
                        const result = await uploadBookPdf(editingId, formData, token)
                        onChange('pdf_original_filename', result.data.pdf_original_filename ?? '')
                        onChange('has_available_pdf', result.data.has_available_pdf ? 'true' : '')
                        setPdfFile(null)
                      } catch (error) {
                        setPdfError(error instanceof ApiError ? error.message : String(error))
                      } finally {
                        setPdfBusy(false)
                      }
                    }}
                  >
                    {pdfBusy ? t['management.table.saving'] : t['management.books.uploadPdf']}
                  </button>
                </div>
  
                {pdfError && <p className="text-xs text-danger-500">{pdfError}</p>}
              </div>
            )}
          </>
        )}
        fetchPage={(_page, filters) =>
          listBooks({
            classId: filters.parentId,
            subjectId: filters.secondaryParentId,
            versionId: filters.tertiaryParentId,
            academicYearId: filters.quaternaryParentId,
          }).then((result) => toSinglePage(result.data.filter((book) => matchesSearch(book.name, book.bn_name, filters.search))))
        }
        createRow={(values, token) => createBook(toCreatePayload(values), token)}
        updateRow={(id, values, token) => updateBook(id, toUpdatePayload(values), token)}
        deleteRow={(id, token) => deleteBook(id, token)}
        createPermission="books.create"
        updatePermission="books.update"
        deletePermission="books.delete"
        rowActions={(row) => (
          <button
            type="button"
            disabled={!row.has_available_pdf}
            className={`leading-none transition-colors duration-150 ${
              row.has_available_pdf
                ? 'cursor-pointer text-gray-500 hover:text-primary-500'
                : 'cursor-not-allowed text-gray-300'
            }`}
            onClick={() => openBookPdf(row)}
            aria-label={t['management.books.viewPdf']}
            title={row.has_available_pdf ? t['management.books.viewPdf'] : t['management.books.noPdf']}
          >
            <span className="material-symbols-outlined !text-md">picture_as_pdf</span>
          </button>
        )}
        parentFilter={{
          label: t['management.fields.class'],
          allLabel: t['management.fields.allClasses'],
          options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClass.name })),
        }}
        secondaryFilter={{
          label: t['management.fields.subject'],
          allLabel: t['management.fields.allSubjects'],
          options: subjects.map((subject) => ({ id: subject.id, label: subject.name })),
        }}
        tertiaryFilter={{
          label: t['management.fields.version'],
          allLabel: t['management.fields.allVersions'],
          options: versions.map((version) => ({ id: version.id, label: version.name })),
        }}
        quaternaryFilter={{
          label: t['management.books.academicYear'],
          allLabel: t['management.fields.allAcademicYears'],
          options: academicYears.map((year) => ({ id: year.id, label: year.name })),
        }}
      />
      <ToastViewport toast={toast} />
    </>
  )
}
