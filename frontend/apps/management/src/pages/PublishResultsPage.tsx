import { useEffect, useState } from 'react'
import {
  ApiError,
  getAcademicExamPublicationStatus,
  listAcademicExamTerms,
  listAcademicYears,
  listSchools,
  listSections,
  publishAcademicExamResults,
  type AcademicExamPublicationStatus,
  type AcademicExamTerm,
  type AcademicYear,
  type School,
  type Section,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function sectionLabel(section: Section): string {
  return [section.shift?.class_version?.class?.name, section.shift?.class_version?.name, section.shift?.name, section.name]
    .filter(Boolean)
    .join(' - ')
}

// Report/action-only — recording marks itself stays in the teacher
// portal (see AcademicExamMarkPolicy); this page only reads entered-vs-
// roster counts and fires the publish action, same split as
// AttendanceReportPage's own relationship to Take Attendance.
export default function PublishResultsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)

  const [years, setYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [loading, setLoading] = useState(false)
  const [status, setStatus] = useState<AcademicExamPublicationStatus | null>(null)
  const [publishing, setPublishing] = useState(false)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listAcademicExamTerms()
      .then((result) => setTerms(result.data))
      .catch(() => setTerms([]))

    listAcademicYears({ perPage: 200 })
      .then((result) => {
        setYears(result.data)
        setAcademicYearId((previous) => previous ?? result.data.find((year) => year.is_current)?.id ?? null)
      })
      .catch(() => setYears([]))
  }, [])

  useEffect(() => {
    if (!schoolId) {
      setSections([])
      return
    }

    let active = true

    listSections({ schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setSections(result.data)
        setSectionId(null)
      })
      .catch(() => {
        if (active) setSections([])
      })

    return () => {
      active = false
    }
  }, [schoolId])

  useEffect(() => {
    if (!token || sectionId === null || academicExamTermId === null || academicYearId === null) {
      setStatus(null)
      return
    }

    let active = true
    setLoading(true)

    getAcademicExamPublicationStatus({ sectionId, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (active) setStatus(result.data)
      })
      .catch(() => {
        if (active) setStatus(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, academicExamTermId, academicYearId])

  async function handlePublish() {
    if (!token || sectionId === null || academicExamTermId === null || academicYearId === null) return

    setPublishing(true)

    try {
      await publishAcademicExamResults({ section_id: sectionId, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId }, token)
      showToast({ type: 'success', message: t['management.publishResults.publishSuccess'] })
      setStatus((previous) => (previous ? { ...previous, is_published: true } : previous))
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.publishResults.publishError'] })
    } finally {
      setPublishing(false)
    }
  }

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.publishResults.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.attendance.selectSchool']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((school) => ({ value: String(school.id), label: school.bn_name ?? school.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.publishResults.selectSection']}</span>
          <select
            className={`${inputClass} min-w-[12rem]`}
            value={sectionId ?? ''}
            onChange={(event) => setSectionId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {sectionLabel(section)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.publishResults.selectTerm']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={academicExamTermId ?? ''}
            onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {terms.map((term) => (
              <option key={term.id} value={term.id}>
                {term.bn_name ?? term.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectAcademicYear']}</span>
          <select
            className={`${inputClass} min-w-[9rem]`}
            value={academicYearId ?? ''}
            onChange={(event) => setAcademicYearId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['management.publishResults.loading']}</p>}

      {!loading && status && (
        <>
          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[28rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.publishResults.colSubject']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.publishResults.colEntered']}</th>
                </tr>
              </thead>
              <tbody>
                {status.subjects.map((row) => (
                  <tr key={row.subject_id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.subject_name ?? '—'}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-600">
                      {row.entered_count} / {status.roster_size}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4">
            {status.is_published ? (
              <span className="rounded-full bg-success-50 px-3 py-1.5 text-sm font-medium text-success-600">
                {t['management.publishResults.alreadyPublished']}
              </span>
            ) : (
              <button type="button" onClick={handlePublish} disabled={publishing} className={buttonPrimary}>
                {publishing ? t['management.publishResults.publishing'] : t['management.publishResults.publish']}
              </button>
            )}
          </div>
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
