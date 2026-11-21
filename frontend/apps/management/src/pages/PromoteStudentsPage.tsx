import { useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  confirmStudentPromotions,
  getStudentPromotionPreview,
  listAcademicYears,
  listClasses,
  listSchools,
  listSections,
  type AcademicYear,
  type School,
  type SchoolClass,
  type Section,
  type StudentPromotionPreview,
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

const RESULT_LABEL: Record<string, string> = {
  pass: t['management.promoteStudents.resultPass'],
  fail: t['management.promoteStudents.resultFail'],
  no_marks: t['management.promoteStudents.resultNoMarks'],
}

const RESULT_BADGE: Record<string, string> = {
  pass: 'bg-success-50 text-success-600',
  fail: 'bg-danger-50 text-danger-600',
  no_marks: 'bg-gray-100 text-gray-500',
}

// Bulk-per-section with a per-student override — one target class/section
// for the whole batch; the only per-student choice is promote vs. retain.
export default function PromoteStudentsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const [years, setYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [loadingPreview, setLoadingPreview] = useState(false)
  const [preview, setPreview] = useState<StudentPromotionPreview | null>(null)
  const [actions, setActions] = useState<Record<number, boolean>>({})

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [toClassId, setToClassId] = useState<number | null>(null)
  const [targetSections, setTargetSections] = useState<Section[]>([])
  const [toSectionId, setToSectionId] = useState<number | null>(null)
  const [overrideCapacity, setOverrideCapacity] = useState(false)

  const [confirming, setConfirming] = useState(false)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

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
    if (!token || sectionId === null || academicYearId === null) {
      setPreview(null)
      setActions({})
      return
    }

    let active = true
    setLoadingPreview(true)

    getStudentPromotionPreview({ sectionId, academicYearId }, token)
      .then((result) => {
        if (!active) return
        setPreview(result.data)
        setActions(
          Object.fromEntries(result.data.students.map((row) => [row.student_profile_id, row.default_action === 'promote'])),
        )
        setToClassId(result.data.suggested_to_class_id)
      })
      .catch(() => {
        if (active) setPreview(null)
      })
      .finally(() => {
        if (active) setLoadingPreview(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, academicYearId])

  useEffect(() => {
    if (!toClassId || !schoolId) {
      setTargetSections([])
      setToSectionId(null)
      return
    }

    let active = true

    listSections({ classId: toClassId, schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setTargetSections(result.data)
        setToSectionId(null)
      })
      .catch(() => {
        if (active) setTargetSections([])
      })

    return () => {
      active = false
    }
  }, [toClassId, schoolId])

  const promotingCount = useMemo(() => Object.values(actions).filter(Boolean).length, [actions])

  async function handleConfirm() {
    if (!token || sectionId === null || academicYearId === null || toSectionId === null) return

    setConfirming(true)

    try {
      await confirmStudentPromotions(
        {
          section_id: sectionId,
          academic_year_id: academicYearId,
          to_section_id: toSectionId,
          override_capacity: overrideCapacity,
          records: Object.entries(actions).map(([studentProfileId, promote]) => ({
            student_profile_id: Number(studentProfileId),
            promote,
          })),
        },
        token,
      )
      showToast({ type: 'success', message: t['management.promoteStudents.confirmSuccess'] })
      setPreview(null)
      setActions({})
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.promoteStudents.confirmError'] })
    } finally {
      setConfirming(false)
    }
  }

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.promoteStudents.title']}</h1>

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
          <span className="font-medium text-gray-700">{t['management.promoteStudents.selectSection']}</span>
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

      {loadingPreview && <p className="mt-6 text-sm text-gray-500">{t['management.promoteStudents.loading']}</p>}

      {!loadingPreview && !preview && sectionId !== null && academicYearId !== null && (
        <p className="mt-6 text-sm text-gray-500">{t['management.promoteStudents.pickSectionAndYear']}</p>
      )}

      {!loadingPreview && preview && (
        <>
          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[36rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colResult']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colGpa']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colAction']}</th>
                </tr>
              </thead>
              <tbody>
                {preview.students.map((row) => {
                  const promote = actions[row.student_profile_id] ?? false

                  return (
                    <tr key={row.student_profile_id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${RESULT_BADGE[row.result]}`}>
                          {RESULT_LABEL[row.result]}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-600">{row.gpa.toFixed(2)}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="flex gap-2">
                          <button
                            type="button"
                            onClick={() => setActions((previous) => ({ ...previous, [row.student_profile_id]: true }))}
                            className={`rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors ${
                              promote ? 'border-success-500 bg-success-50 text-success-600' : 'border-gray-200 text-gray-500 hover:border-primary-300'
                            }`}
                          >
                            {t['management.promoteStudents.actionPromote']}
                          </button>
                          <button
                            type="button"
                            onClick={() => setActions((previous) => ({ ...previous, [row.student_profile_id]: false }))}
                            className={`rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors ${
                              !promote ? 'border-danger-500 bg-danger-50 text-danger-600' : 'border-gray-200 text-gray-500 hover:border-primary-300'
                            }`}
                          >
                            {t['management.promoteStudents.actionRetain']}
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['management.promoteStudents.selectTargetClass']}</span>
              <select
                className={`${inputClass} min-w-[10rem]`}
                value={toClassId ?? ''}
                onChange={(event) => setToClassId(event.target.value ? Number(event.target.value) : null)}
              >
                <option value="">—</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.bn_name ?? schoolClass.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['management.promoteStudents.selectTargetSection']}</span>
              <select
                className={`${inputClass} min-w-[12rem]`}
                value={toSectionId ?? ''}
                disabled={toClassId === null}
                onChange={(event) => setToSectionId(event.target.value ? Number(event.target.value) : null)}
              >
                <option value="">—</option>
                {targetSections.map((section) => (
                  <option key={section.id} value={section.id}>
                    {sectionLabel(section)}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={overrideCapacity} onChange={(event) => setOverrideCapacity(event.target.checked)} />
              {t['management.promoteStudents.overrideCapacity']}
            </label>
          </div>

          {toSectionId === null && <p className="mt-3 text-sm text-gray-500">{t['management.promoteStudents.pickTarget']}</p>}

          <div className="mt-4">
            <button
              type="button"
              onClick={handleConfirm}
              disabled={confirming || toSectionId === null}
              className={buttonPrimary}
            >
              {confirming
                ? t['management.promoteStudents.confirming']
                : `${t['management.promoteStudents.confirm']} (${promotingCount})`}
            </button>
          </div>
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
