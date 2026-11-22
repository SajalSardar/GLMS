import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  createBook,
  deleteBook,
  listAcademicYears,
  listBooks,
  listClassVersions,
  listSubjects,
  updateBook,
  type AcademicYear,
  type Book,
  type ClassVersion,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

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

const initialValues = {
  subject_id: '',
  class_version_id: '',
  academic_year_id: '',
  name: '',
  bn_name: '',
  order_index: '',
  description: '',
  is_active: 'true',
}

const requiredFields = ['subject_id', 'class_version_id', 'academic_year_id', 'name']

function toCreatePayload(values: Record<string, string>) {
  return {
    subject_id: Number(values.subject_id),
    class_version_id: Number(values.class_version_id),
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
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])

  useEffect(() => {
    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    listClassVersions({ perPage: 500 })
      .then((result) => setClassVersions(result.data))
      .catch(() => setClassVersions([]))

    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  const subjectOptions = subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))
  const classVersionOptions = classVersions.map((version) => ({ value: String(version.id), label: version.name }))
  const academicYearOptions = academicYears.map((year) => ({ value: String(year.id), label: year.name }))

  const columns: GeoColumn<Book>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.subject?.name ?? row.subject_id },
    {
      key: 'class_version',
      label: t['management.fields.classVersion'],
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
          onClick={() => navigate(`/dashboard/system-setup/books/${row.id}/chapters`)}
        >
          {t['management.books.manageChapters']}
        </button>
      ),
    },
  ]

  return (
    <GeoCrudTable<Book>
      title={t['management.books.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        subject_id: String(row.subject_id),
        class_version_id: String(row.class_version_id),
        academic_year_id: row.academic_year_id ? String(row.academic_year_id) : '',
        name: row.name,
        bn_name: row.bn_name ?? '',
        order_index: row.order_index !== null ? String(row.order_index) : '',
        description: row.description ?? '',
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors, editingId) => (
        <>
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
            <GeoField label={t['management.fields.classVersion']}>
              <input
                className={`${inputClass} cursor-not-allowed opacity-60`}
                value={
                  classVersions.find((version) => String(version.id) === values.class_version_id)?.name ??
                  values.class_version_id
                }
                disabled
              />
            </GeoField>
          ) : (
            <GeoField label={t['management.fields.classVersion']} required error={fieldErrors.class_version_id}>
              <SearchableSelect
                value={values.class_version_id}
                onChange={(next) => onChange('class_version_id', next)}
                placeholder={t['management.fields.classVersion']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={classVersionOptions}
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
        </>
      )}
      fetchPage={(_page, filters) =>
        listBooks({
          subjectId: filters.parentId,
          classVersionId: filters.secondaryParentId,
          academicYearId: filters.tertiaryParentId,
        }).then((result) => toSinglePage(result.data.filter((book) => matchesSearch(book.name, book.bn_name, filters.search))))
      }
      createRow={(values, token) => createBook(toCreatePayload(values), token)}
      updateRow={(id, values, token) => updateBook(id, toUpdatePayload(values), token)}
      deleteRow={(id, token) => deleteBook(id, token)}
      parentFilter={{
        label: t['management.fields.subject'],
        allLabel: t['management.fields.allSubjects'],
        options: subjects.map((subject) => ({ id: subject.id, label: subject.name })),
      }}
      secondaryFilter={{
        label: t['management.fields.classVersion'],
        allLabel: t['management.fields.allClassVersions'],
        options: classVersions.map((version) => ({ id: version.id, label: version.name })),
      }}
      tertiaryFilter={{
        label: t['management.books.academicYear'],
        allLabel: t['management.fields.allAcademicYears'],
        options: academicYears.map((year) => ({ id: year.id, label: year.name })),
      }}
    />
  )
}
