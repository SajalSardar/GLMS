import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  createClassSchool,
  deleteClassSchool,
  getSchool,
  listClasses,
  listClassSchools,
  listClassVersions,
  listShifts,
  listSections,
  listSubjects,
  updateClassSchoolSubjects,
  type ClassSchool,
  type ClassVersion,
  type School,
  type SchoolClass,
  type Section,
  type Shift,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { SCHOOL_LEVELS, SCHOOL_TYPES } from '../components/schoolOptions'
import { buttonNeutral, buttonPrimary } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

interface HierarchyRow {
  key: string
  className: string
  versionName: string
  shiftName: string
  sectionName: string
}

function buildHierarchyRows(
  classSchools: ClassSchool[],
  versions: ClassVersion[],
  shifts: Shift[],
  sections: Section[],
): HierarchyRow[] {
  const rows: HierarchyRow[] = []

  const sortedClassSchools = [...classSchools].sort(
    (a, b) => (a.class?.numeric_level ?? 0) - (b.class?.numeric_level ?? 0) || (a.class?.name ?? '').localeCompare(b.class?.name ?? ''),
  )

  for (const classSchool of sortedClassSchools) {
    const className = classSchool.class?.name ?? '—'
    const classVersions = versions.filter((version) => version.class_id === classSchool.class_id)

    if (classVersions.length === 0) {
      rows.push({
        key: `c${classSchool.id}`,
        className,
        versionName: '—',
        shiftName: '—',
        sectionName: '—',
      })
      continue
    }

    for (const version of classVersions.sort((a, b) => a.name.localeCompare(b.name))) {
      const versionShifts = shifts.filter((shift) => shift.class_version_id === version.id)

      if (versionShifts.length === 0) {
        rows.push({
          key: `v${version.id}`,
          className,
          versionName: version.name,
          shiftName: '—',
          sectionName: '—',
        })
        continue
      }

      for (const shift of versionShifts.sort((a, b) => a.start_time.localeCompare(b.start_time))) {
        const shiftSections = sections
          .filter((section) => section.shift_id === shift.id)
          .sort((a, b) => a.name.localeCompare(b.name))

        rows.push({
          key: `sh${shift.id}`,
          className,
          versionName: version.name,
          shiftName: shift.name,
          sectionName: shiftSections.length > 0 ? shiftSections.map((section) => section.name).join(', ') : '—',
        })
      }
    }
  }

  return rows
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-2 last:border-0">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-right text-sm font-medium text-gray-800">{value}</span>
    </div>
  )
}

function SchoolInfo({ school }: { school: School }) {
  const typeLabel = SCHOOL_TYPES.find((option) => option.value === school.type)?.label ?? school.type
  const levelLabel = SCHOOL_LEVELS.find((option) => option.value === school.level)?.label ?? school.level

  return (
    <div className="flex flex-col gap-4 rounded-md bg-white p-5 shadow-sm sm:flex-row sm:gap-6">
      <div className="flex justify-center sm:justify-start">
        {school.logo_url ? (
          <img src={school.logo_url} alt="" className="h-24 w-24 rounded-full object-cover" />
        ) : (
          <span className="flex h-24 w-24 items-center justify-center rounded-full bg-gray-100 text-gray-400">
            <span className="material-symbols-outlined !text-4xl">school</span>
          </span>
        )}
      </div>

      <div className="flex-1">
        <h2 className="text-lg font-semibold text-gray-800">{school.name}</h2>
        {school.bn_name && <p className="text-sm text-gray-500">{school.bn_name}</p>}

        <div className="mt-3 flex flex-col gap-1">
          <DetailRow label={t['management.fields.eiin']} value={school.eiin ?? '—'} />
          <DetailRow label={t['management.fields.type']} value={typeLabel} />
          <DetailRow label={t['management.fields.level']} value={levelLabel} />
          <DetailRow label={t['management.fields.division']} value={school.division?.name ?? school.division_id} />
          <DetailRow label={t['management.fields.district']} value={school.district?.name ?? school.district_id} />
          <DetailRow label={t['management.fields.upazila']} value={school.upazila?.name ?? school.upazila_id} />
          <DetailRow label={t['management.fields.address']} value={school.address ?? '—'} />
          <DetailRow label={t['management.fields.phone']} value={school.phone ?? '—'} />
          <DetailRow label={t['management.fields.email']} value={school.email ?? '—'} />
          <DetailRow label={t['management.fields.establishedYear']} value={school.established_year ?? '—'} />
        </div>
      </div>
    </div>
  )
}

function AdoptedClassesPanel({
  schoolId,
  classSchools,
  onChanged,
}: {
  schoolId: number
  classSchools: ClassSchool[]
  onChanged: () => void
}) {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [allClasses, setAllClasses] = useState<SchoolClass[]>([])
  const [allSubjects, setAllSubjects] = useState<Subject[]>([])
  const [adoptClassId, setAdoptClassId] = useState('')
  const [adopting, setAdopting] = useState(false)
  const [removingId, setRemovingId] = useState<number | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)
  const [managingId, setManagingId] = useState<number | null>(null)
  const [checkedSubjectIds, setCheckedSubjectIds] = useState<Set<number>>(new Set())
  const [savingSubjects, setSavingSubjects] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setAllClasses(result.data))
      .catch(() => setAllClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setAllSubjects(result.data))
      .catch(() => setAllSubjects([]))
  }, [])

  const adoptedClassIds = new Set(classSchools.map((classSchool) => classSchool.class_id))
  const adoptableOptions = allClasses
    .filter((schoolClass) => !adoptedClassIds.has(schoolClass.id))
    .map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))

  async function handleAdopt() {
    if (!token || !adoptClassId) return

    setAdopting(true)
    try {
      await createClassSchool({ class_id: Number(adoptClassId), school_id: schoolId }, token)
      setAdoptClassId('')
      showToast({ type: 'success', message: t['management.schoolDetail.classAdopted'] })
      onChanged()
    } catch (error) {
      showToast({ type: 'error', message: error instanceof ApiError ? error.message : String(error) })
    } finally {
      setAdopting(false)
    }
  }

  async function handleRemove(id: number) {
    if (!token) return

    setRemovingId(id)
    setRemoveError(null)
    try {
      await deleteClassSchool(id, token)
      setRemovingId(null)
      showToast({ type: 'success', message: t['management.schoolDetail.classRemoved'] })
      onChanged()
    } catch (error) {
      const message = error instanceof ApiError ? error.message : String(error)
      setRemoveError(message)
      showToast({ type: 'error', message })
    } finally {
      setRemovingId(null)
    }
  }

  function openManageSubjects(classSchool: ClassSchool) {
    setManagingId(classSchool.id)
    setCheckedSubjectIds(new Set((classSchool.subjects ?? []).map((subject) => subject.id)))
  }

  function toggleSubject(subjectId: number) {
    setCheckedSubjectIds((prev) => {
      const next = new Set(prev)
      if (next.has(subjectId)) {
        next.delete(subjectId)
      } else {
        next.add(subjectId)
      }
      return next
    })
  }

  async function handleSaveSubjects() {
    if (!token || managingId === null) return

    setSavingSubjects(true)
    try {
      await updateClassSchoolSubjects(managingId, [...checkedSubjectIds], token)
      setManagingId(null)
      showToast({ type: 'success', message: t['management.schoolDetail.subjectsSaved'] })
      onChanged()
    } catch (error) {
      showToast({ type: 'error', message: error instanceof ApiError ? error.message : String(error) })
    } finally {
      setSavingSubjects(false)
    }
  }

  return (
    <div className="space-y-3">
      <h3 className="text-base font-semibold text-gray-800">{t['management.schoolDetail.adoptedClasses']}</h3>

      <div className="rounded-md bg-white p-5 shadow-sm">
        {classSchools.length === 0 ? (
          <p className="text-sm text-gray-500">{t['management.schoolDetail.noAdoptedClasses']}</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {classSchools.map((classSchool) => (
              <li key={classSchool.id} className="flex flex-col gap-2 py-3">
                <div className="flex items-center justify-between gap-3">
                  <span className="text-sm font-medium text-gray-800">{classSchool.class?.name ?? classSchool.class_id}</span>
                  <div className="flex items-center gap-3">
                    <button
                      type="button"
                      className="cursor-pointer text-sm text-primary-600 hover:underline"
                      onClick={() => openManageSubjects(classSchool)}
                    >
                      {t['management.schoolDetail.manageSubjects']}
                    </button>
                    <button
                      type="button"
                      className="cursor-pointer text-sm text-danger-500 hover:underline disabled:opacity-60"
                      disabled={removingId === classSchool.id}
                      onClick={() => {
                        if (window.confirm(t['management.schoolDetail.removeClassConfirm'])) {
                          handleRemove(classSchool.id)
                        }
                      }}
                    >
                      {t['management.schoolDetail.removeClass']}
                    </button>
                  </div>
                </div>

                {removeError && removingId === classSchool.id && (
                  <p className="text-xs text-danger-500">{removeError}</p>
                )}

                {managingId === classSchool.id && (
                  <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                      {allSubjects.map((subject) => (
                        <label key={subject.id} className="flex items-center gap-2 text-sm text-gray-700">
                          <input
                            type="checkbox"
                            checked={checkedSubjectIds.has(subject.id)}
                            onChange={() => toggleSubject(subject.id)}
                          />
                          {subject.name}
                        </label>
                      ))}
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                      <button
                        type="button"
                        className="cursor-pointer text-sm text-gray-500 hover:underline"
                        onClick={() => setManagingId(null)}
                      >
                        {t['management.table.cancel']}
                      </button>
                      <button
                        type="button"
                        className={buttonPrimary}
                        disabled={savingSubjects}
                        onClick={handleSaveSubjects}
                      >
                        {savingSubjects ? t['management.table.saving'] : t['management.table.save']}
                      </button>
                    </div>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4 flex items-end gap-3 border-t border-gray-100 pt-4">
          <div className="w-full sm:w-64">
            <SearchableSelect
              value={adoptClassId}
              onChange={setAdoptClassId}
              placeholder={t['management.schoolDetail.adoptClassPlaceholder']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={adoptableOptions}
            />
          </div>
          <button
            type="button"
            className={buttonPrimary}
            disabled={!adoptClassId || adopting}
            onClick={handleAdopt}
          >
            {adopting ? t['management.table.saving'] : t['management.schoolDetail.adopt']}
          </button>
        </div>
      </div>

      <ToastViewport toast={toast} />
    </div>
  )
}

export default function SchoolDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [school, setSchool] = useState<School | null>(null)
  const [classSchools, setClassSchools] = useState<ClassSchool[]>([])
  const [rows, setRows] = useState<HierarchyRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadSchool = useCallback((schoolId: number) => {
    setLoading(true)
    setError(null)

    Promise.all([
      getSchool(schoolId),
      listClassSchools({ schoolId, perPage: 500 }),
      listClassVersions({ schoolId, perPage: 500 }),
      listShifts({ schoolId, perPage: 500 }),
      listSections({ schoolId, perPage: 500 }),
    ])
      .then(([schoolResult, classSchoolsResult, versionsResult, shiftsResult, sectionsResult]) => {
        setSchool(schoolResult.data)
        setClassSchools(classSchoolsResult.data)
        setRows(
          buildHierarchyRows(classSchoolsResult.data, versionsResult.data, shiftsResult.data, sectionsResult.data),
        )
      })
      .catch((caught: unknown) => {
        setError(caught instanceof ApiError ? caught.message : String(caught))
      })
      .finally(() => setLoading(false))
  }, [])

  // A ref, not a direct call, so the effect's own body contains no
  // traceable setState calls — see GeoCrudTable's identical pattern.
  const loadSchoolRef = useRef(loadSchool)
  useEffect(() => {
    loadSchoolRef.current = loadSchool
  })

  useEffect(() => {
    const schoolId = Number(id)
    if (schoolId) loadSchoolRef.current(schoolId)
  }, [id])

  return (
    <section className="space-y-5">
      <button
        type="button"
        className={`${buttonNeutral} inline-flex items-center gap-1.5`}
        onClick={() => navigate('/dashboard/system-setup/schools')}
      >
        <span className="material-symbols-outlined !text-[18px] leading-none">arrow_back</span>
        {t['management.schoolDetail.back']}
      </button>

      {loading && <p className="text-sm text-gray-500">{t['management.schoolDetail.loading']}</p>}

      {error && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}

      {!loading && !error && !school && (
        <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">
          {t['management.schoolDetail.notFound']}
        </p>
      )}

      {school && (
        <>
          <SchoolInfo school={school} />

          <div className="space-y-3">
            <h3 className="text-base font-semibold text-gray-800">{t['management.schoolDetail.hierarchy']}</h3>

            <div className="overflow-x-auto rounded-md bg-white shadow-sm">
              <table className="w-full text-sm">
                <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                  <tr>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.fields.class']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.fields.version']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.fields.shift']}</th>
                    <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.fields.section']}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-5 py-6 text-center text-gray-500">
                        {t['management.schoolDetail.empty']}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row, index) => (
                      <tr key={row.key} className="border-t border-gray-100">
                        <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                        <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{row.className}</td>
                        <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{row.versionName}</td>
                        <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{row.shiftName}</td>
                        <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{row.sectionName}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <AdoptedClassesPanel
            schoolId={school.id}
            classSchools={classSchools}
            onChanged={() => loadSchool(school.id)}
          />
        </>
      )}
    </section>
  )
}
