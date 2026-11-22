import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { createChapter, deleteChapter, getBook, listChapters, updateChapter, type Book, type Chapter } from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import { buttonNeutral, inputClass } from '../components/styles'

const t = getCommonStrings('en')

function matchesSearch(name: string, bnName: string | null, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return name.toLowerCase().includes(needle) || (bnName ?? '').toLowerCase().includes(needle)
}

const initialValues = { name: '', bn_name: '', order_index: '', description: '' }
const requiredFields = ['name']

export default function ChaptersPage() {
  const { bookId } = useParams()
  const navigate = useNavigate()
  const id = Number(bookId)
  const [book, setBook] = useState<Book | null>(null)

  useEffect(() => {
    getBook(id)
      .then((result) => setBook(result.data))
      .catch(() => setBook(null))
  }, [id])

  const columns: GeoColumn<Chapter>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    { key: 'bn_name', label: t['management.fields.bnName'], render: (row) => row.bn_name ?? '—' },
    {
      key: 'chapter_number',
      label: t['management.chapters.chapterNumber'],
      render: (row) => row.chapter_number ?? '—',
    },
    { key: 'order_index', label: t['management.fields.order'], render: (row) => row.order_index ?? '—' },
  ]

  return (
    <section className="space-y-4">
      <div className="flex items-center gap-3">
        <button
          type="button"
          className={`${buttonNeutral} inline-flex items-center gap-1.5`}
          onClick={() => navigate('/dashboard/class-materials/books')}
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
          {t['management.chapters.backToBooks']}
        </button>
        <p className="text-sm text-gray-500">
          {t['management.chapters.forBook']}: <span className="font-medium text-gray-800">{book?.name ?? '—'}</span>
        </p>
      </div>

      <GeoCrudTable<Chapter>
        title={t['management.chapters.title']}
        columns={columns}
        initialValues={initialValues}
        requiredFields={requiredFields}
        valuesFromRow={(row) => ({
          name: row.name,
          bn_name: row.bn_name ?? '',
          order_index: row.order_index !== null ? String(row.order_index) : '',
          description: row.description ?? '',
        })}
        renderFields={(values, onChange, fieldErrors) => (
          <>
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
          </>
        )}
        fetchPage={(_page, filters) =>
          listChapters({ bookId: id }).then((result) =>
            toSinglePage(result.data.filter((chapter) => matchesSearch(chapter.name, chapter.bn_name, filters.search))),
          )
        }
        createRow={(values, token) =>
          createChapter(
            {
              book_id: id,
              name: values.name,
              bn_name: values.bn_name || null,
              order_index: values.order_index ? Number(values.order_index) : null,
              description: values.description || null,
            },
            token,
          )
        }
        updateRow={(chapterId, values, token) =>
          updateChapter(
            chapterId,
            {
              name: values.name,
              bn_name: values.bn_name || null,
              order_index: values.order_index ? Number(values.order_index) : null,
              description: values.description || null,
            },
            token,
          )
        }
        deleteRow={(chapterId, token) => deleteChapter(chapterId, token)}
      />
    </section>
  )
}
