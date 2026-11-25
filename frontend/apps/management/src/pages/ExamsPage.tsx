import { useEffect, useState } from 'react'
import {
  createExam,
  deleteExam,
  getExam,
  listAcademicYears,
  listChapters,
  listClasses,
  listClassVersions,
  listExams,
  listQuestions,
  listSubjects,
  randomizeExam,
  setExamQuestions,
  setExamRandomChapters,
  updateExam,
  type AcademicYear,
  type Chapter,
  type ClassVersion,
  type Exam,
  type ExamSelectionMode,
  type Question,
  type QuestionDifficulty,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function matchesSearch(name: string, bnName: string | null, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return name.toLowerCase().includes(needle) || (bnName ?? '').toLowerCase().includes(needle)
}

// Class -> this school's ClassVersion — same cascade as BooksPage's
// ClassVersionCascadeFields (and the same known ambiguity it documents:
// the Version dropdown is labeled only "Bangla"/"English", indistinguishable
// between schools when several share a class). class_version_id/subject_id/
// academic_year_id/selection_mode are immutable after creation, so this
// cascade only ever renders on create — edit mode shows them as fixed text.
function ExamCascadeFields({
  schoolClassId,
  classVersionId,
  classes,
  onChange,
  fieldErrors,
}: {
  schoolClassId: string
  classVersionId: string
  classes: SchoolClass[]
  onChange: (key: string, value: string) => void
  fieldErrors: Record<string, string>
}) {
  const [classVersions, setClassVersions] = useState<ClassVersion[]>([])

  useEffect(() => {
    if (!schoolClassId) {
      setClassVersions([])
      return
    }

    let active = true
    listClassVersions({ classId: Number(schoolClassId), perPage: 500 })
      .then((result) => {
        if (active) setClassVersions(result.data)
      })
      .catch(() => {
        if (active) setClassVersions([])
      })

    return () => {
      active = false
    }
  }, [schoolClassId])

  return (
    <>
      <GeoField label={t['management.fields.class']} required error={fieldErrors.school_class_id}>
        <SearchableSelect
          value={schoolClassId}
          onChange={(next) => {
            onChange('school_class_id', next)
            onChange('class_version_id', '')
          }}
          placeholder={t['management.fields.class']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))}
        />
      </GeoField>

      <GeoField label={t['management.fields.classVersion']} required error={fieldErrors.class_version_id}>
        <SearchableSelect
          value={classVersionId}
          onChange={(next) => onChange('class_version_id', next)}
          placeholder={t['management.fields.classVersion']}
          searchPlaceholder={t['management.table.search']}
          noMatchesLabel={t['management.table.noMatches']}
          options={classVersions.map((version) => ({ value: String(version.id), label: version.name }))}
          className={!schoolClassId ? `${inputClass} cursor-not-allowed opacity-60` : undefined}
        />
      </GeoField>
    </>
  )
}

// The exam's own class_version_id/subject_id pin the chapter pool — loose
// (no version_id filter) since ClassVersionResource doesn't expose
// version_id and this is just a convenience picker for attaching
// questions, not the exam's own stored scope.
function useExamChapters(classId: number | null, subjectId: number | null): Chapter[] {
  const [chapters, setChapters] = useState<Chapter[]>([])

  useEffect(() => {
    if (!classId || !subjectId) {
      setChapters([])
      return
    }

    let active = true
    listChapters({ classId, subjectId })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [classId, subjectId])

  return chapters
}

function ManageExamQuestionsModal({ examId, onClose }: { examId: number; onClose: () => void }) {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [exam, setExam] = useState<Exam | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [attachedIds, setAttachedIds] = useState<number[]>([])
  const [selectedChapterIds, setSelectedChapterIds] = useState<number[]>([])
  const [chapterId, setChapterId] = useState('')
  const [availableQuestions, setAvailableQuestions] = useState<Question[]>([])

  function load() {
    if (!token) return

    setLoading(true)
    getExam(examId, token)
      .then((result) => {
        const loaded = result.data
        setExam(loaded)
        setAttachedIds((loaded.questions ?? []).slice().sort((a, b) => a.order_index - b.order_index).map((link) => link.question.id))
        setSelectedChapterIds((loaded.random_chapters ?? []).map((chapter) => chapter.id))
      })
      .catch(() => setExam(null))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [examId, token])

  const classId = exam?.class_version?.class_id ?? null
  const subjectId = exam?.subject_id ?? null
  const chapters = useExamChapters(classId, subjectId)

  useEffect(() => {
    if (!chapterId) {
      setAvailableQuestions([])
      return
    }

    let active = true
    listQuestions({ chapterId: Number(chapterId) }, token ?? undefined)
      .then((result) => {
        if (active) setAvailableQuestions(result.data)
      })
      .catch(() => {
        if (active) setAvailableQuestions([])
      })

    return () => {
      active = false
    }
  }, [chapterId, token])

  function toggleAttached(question: Question, checked: boolean) {
    setAttachedIds((prev) => (checked ? [...prev, question.id] : prev.filter((id) => id !== question.id)))
  }

  function moveAttached(index: number, direction: -1 | 1) {
    setAttachedIds((prev) => {
      const next = [...prev]
      const target = index + direction
      if (target < 0 || target >= next.length) return prev
      ;[next[index], next[target]] = [next[target], next[index]]
      return next
    })
  }

  async function saveQuestions() {
    if (!token) return

    setSaving(true)
    try {
      const result = await setExamQuestions(examId, attachedIds, token)
      setExam(result.data)
      showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
    } catch {
      showToast({ type: 'error', message: t['management.exams.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  async function saveChapters() {
    if (!token) return

    setSaving(true)
    try {
      const result = await setExamRandomChapters(examId, selectedChapterIds, token)
      setExam(result.data)
      showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
    } catch {
      showToast({ type: 'error', message: t['management.exams.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  async function draw() {
    if (!token) return

    setSaving(true)
    try {
      const result = await randomizeExam(examId, token)
      setExam(result.data)
      setAttachedIds((result.data.questions ?? []).map((link) => link.question.id))
      showToast({ type: 'success', message: t['management.exams.drawSuccess'] })
    } catch {
      showToast({ type: 'error', message: t['management.exams.drawError'] })
    } finally {
      setSaving(false)
    }
  }

  const attachedQuestions = exam?.questions ?? []
  const attachedById = new Map(attachedQuestions.map((link) => [link.question.id, link.question]))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="flex max-h-[calc(100vh-2.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">
            {t['management.exams.manageQuestions']}
            {exam ? ` — ${exam.name}` : ''}
          </h3>
          <button type="button" className="cursor-pointer text-xl leading-none text-gray-400 hover:text-gray-600" onClick={onClose}>
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 overflow-y-auto p-5">
          {loading && <p className="text-sm text-gray-500">{t['management.table.loading']}</p>}

          {!loading && exam && exam.selection_mode === 'manual' && (
            <>
              <div>
                <p className="mb-2 text-sm font-medium text-gray-700">{t['management.exams.attachedQuestions']}</p>
                {attachedIds.length === 0 && <p className="text-sm text-gray-500">{t['management.exams.noAttachedQuestions']}</p>}
                <ul className="flex flex-col gap-2">
                  {attachedIds.map((id, index) => {
                    const question = attachedById.get(id)
                    return (
                      <li key={id} className="flex items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm">
                        <span className="flex-1">{question?.question_text ?? `#${id}`}</span>
                        <div className="flex items-center gap-1">
                          <button type="button" className="text-gray-400 hover:text-gray-700" onClick={() => moveAttached(index, -1)}>
                            <span className="material-symbols-outlined !text-[18px]">arrow_upward</span>
                          </button>
                          <button type="button" className="text-gray-400 hover:text-gray-700" onClick={() => moveAttached(index, 1)}>
                            <span className="material-symbols-outlined !text-[18px]">arrow_downward</span>
                          </button>
                          <button
                            type="button"
                            className="text-danger-500 hover:text-danger-600"
                            onClick={() => setAttachedIds((prev) => prev.filter((existing) => existing !== id))}
                          >
                            <span className="material-symbols-outlined !text-[18px]">close</span>
                          </button>
                        </div>
                      </li>
                    )
                  })}
                </ul>
              </div>

              <div className="w-full sm:w-72">
                <SearchableSelect
                  value={chapterId}
                  onChange={setChapterId}
                  placeholder={t['management.examQuestions.selectChapter']}
                  searchPlaceholder={t['management.table.search']}
                  noMatchesLabel={t['management.table.noMatches']}
                  options={chapters.map((chapter) => ({ value: String(chapter.id), label: chapter.name }))}
                />
              </div>

              {chapterId && (
                <ul className="flex flex-col gap-1.5">
                  {availableQuestions.length === 0 && <p className="text-sm text-gray-500">{t['management.table.empty']}</p>}
                  {availableQuestions.map((question) => (
                    <li key={question.id} className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={attachedIds.includes(question.id)}
                        onChange={(event) => toggleAttached(question, event.target.checked)}
                      />
                      {question.question_text}
                    </li>
                  ))}
                </ul>
              )}

              <button type="button" className={buttonPrimary} onClick={saveQuestions} disabled={saving}>
                {saving ? t['management.table.saving'] : t['management.table.save']}
              </button>
            </>
          )}

          {!loading && exam && exam.selection_mode !== 'manual' && (
            <>
              <p className="text-sm text-gray-600">
                {t['management.exams.randomSummary']
                  .replace('{count}', String(exam.random_question_count ?? 0))
                  .replace('{difficulty}', exam.random_difficulty ?? t['management.exams.anyDifficulty'])}
              </p>

              <div>
                <p className="mb-2 text-sm font-medium text-gray-700">{t['management.exams.randomChapters']}</p>
                <p className="mb-2 text-xs text-gray-500">{t['management.exams.randomChaptersHint']}</p>
                <ul className="flex flex-col gap-1.5">
                  {chapters.map((chapter) => (
                    <li key={chapter.id} className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={selectedChapterIds.includes(chapter.id)}
                        onChange={(event) =>
                          setSelectedChapterIds((prev) =>
                            event.target.checked ? [...prev, chapter.id] : prev.filter((id) => id !== chapter.id),
                          )
                        }
                      />
                      {chapter.name}
                    </li>
                  ))}
                </ul>
              </div>

              <div className="flex gap-3">
                <button type="button" className={buttonNeutral} onClick={saveChapters} disabled={saving}>
                  {saving ? t['management.table.saving'] : t['management.exams.saveChapters']}
                </button>

                {exam.selection_mode === 'fixed_random' && (
                  <button type="button" className={buttonPrimary} onClick={draw} disabled={saving}>
                    {t['management.exams.drawNow']}
                  </button>
                )}
              </div>

              {exam.selection_mode === 'fixed_random' && attachedQuestions.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-medium text-gray-700">{t['management.exams.attachedQuestions']}</p>
                  <ul className="flex flex-col gap-1.5">
                    {attachedQuestions.map((link) => (
                      <li key={link.id} className="text-sm text-gray-700">
                        {link.question.question_text}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>

        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
          <button type="button" className={buttonNeutral} onClick={onClose}>
            {t['management.table.close']}
          </button>
        </div>
      </div>

      <ToastViewport toast={toast} />
    </div>
  )
}

const initialValues: Record<string, string> = {
  school_class_id: '',
  class_version_id: '',
  subject_id: '',
  academic_year_id: '',
  name: '',
  bn_name: '',
  selection_mode: 'manual',
  time_limit_minutes: '',
  max_attempts: '',
  passing_score_percent: '',
  available_from: '',
  available_until: '',
  random_question_count: '',
  random_difficulty: '',
  marks_per_question: '',
  status: 'draft',
}

const requiredFields = ['school_class_id', 'class_version_id', 'subject_id', 'academic_year_id', 'name', 'selection_mode']

export default function ExamsPage() {
  const { token } = useAuth()
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [academicYears, setAcademicYears] = useState<AcademicYear[]>([])
  const [manageExamId, setManageExamId] = useState<number | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))

    listAcademicYears({ perPage: 100 })
      .then((result) => setAcademicYears(result.data))
      .catch(() => setAcademicYears([]))
  }, [])

  const classesById = new Map(classes.map((schoolClass) => [schoolClass.id, schoolClass]))

  const columns: GeoColumn<Exam>[] = [
    { key: 'name', label: t['management.fields.name'], render: (row) => row.name },
    {
      key: 'class',
      label: t['management.fields.class'],
      render: (row) => (row.class_version ? classesById.get(row.class_version.class_id)?.name ?? '—' : '—'),
    },
    { key: 'version', label: t['management.fields.classVersion'], render: (row) => row.class_version?.name ?? '—' },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.subject?.name ?? '—' },
    {
      key: 'mode',
      label: t['management.exams.colMode'],
      render: (row) => t[`management.exams.mode.${row.selection_mode}`],
    },
    {
      key: 'status',
      label: t['management.exams.colStatus'],
      render: (row) => (
        <span
          className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
            row.status === 'published' ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
          }`}
        >
          {row.status === 'published' ? t['management.exams.published'] : t['management.exams.draft']}
        </span>
      ),
    },
  ]

  return (
    <>
      <GeoCrudTable<Exam>
        title={t['management.exams.title']}
        columns={columns}
        initialValues={initialValues}
        requiredFields={requiredFields}
        valuesFromRow={(row) => ({
          school_class_id: row.class_version ? String(row.class_version.class_id) : '',
          class_version_id: String(row.class_version_id),
          subject_id: String(row.subject_id),
          academic_year_id: String(row.academic_year_id),
          name: row.name,
          bn_name: row.bn_name ?? '',
          selection_mode: row.selection_mode,
          time_limit_minutes: row.time_limit_minutes !== null ? String(row.time_limit_minutes) : '',
          max_attempts: row.max_attempts !== null ? String(row.max_attempts) : '',
          passing_score_percent: row.passing_score_percent !== null ? String(row.passing_score_percent) : '',
          available_from: row.available_from ?? '',
          available_until: row.available_until ?? '',
          random_question_count: row.random_question_count !== null ? String(row.random_question_count) : '',
          random_difficulty: row.random_difficulty ?? '',
          marks_per_question: row.marks_per_question !== null ? String(row.marks_per_question) : '',
          status: row.status,
        })}
        renderFields={(values, onChange, fieldErrors, editingId) => (
          <>
            {editingId ? (
              <>
                <GeoField label={t['management.fields.class']}>
                  <input className={`${inputClass} cursor-not-allowed opacity-60`} value={classesById.get(Number(values.school_class_id))?.name ?? ''} disabled />
                </GeoField>
                <GeoField label={t['management.fields.classVersion']}>
                  <input className={`${inputClass} cursor-not-allowed opacity-60`} value={values.class_version_id} disabled />
                </GeoField>
                <GeoField label={t['management.fields.subject']}>
                  <input
                    className={`${inputClass} cursor-not-allowed opacity-60`}
                    value={subjects.find((subject) => String(subject.id) === values.subject_id)?.name ?? ''}
                    disabled
                  />
                </GeoField>
                <GeoField label={t['management.books.academicYear']}>
                  <input
                    className={`${inputClass} cursor-not-allowed opacity-60`}
                    value={academicYears.find((year) => String(year.id) === values.academic_year_id)?.name ?? ''}
                    disabled
                  />
                </GeoField>
                <GeoField label={t['management.exams.colMode']}>
                  <input
                    className={`${inputClass} cursor-not-allowed opacity-60`}
                    value={t[`management.exams.mode.${values.selection_mode as ExamSelectionMode}`]}
                    disabled
                  />
                </GeoField>
                <GeoField label={t['management.exams.colStatus']} error={fieldErrors.status}>
                  <select className={inputClass} value={values.status} onChange={(event) => onChange('status', event.target.value)}>
                    <option value="draft">{t['management.exams.draft']}</option>
                    <option value="published">{t['management.exams.published']}</option>
                  </select>
                </GeoField>
              </>
            ) : (
              <>
                <ExamCascadeFields
                  schoolClassId={values.school_class_id}
                  classVersionId={values.class_version_id}
                  classes={classes}
                  onChange={onChange}
                  fieldErrors={fieldErrors}
                />

                <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
                  <SearchableSelect
                    value={values.subject_id}
                    onChange={(next) => onChange('subject_id', next)}
                    placeholder={t['management.fields.subject']}
                    searchPlaceholder={t['management.table.search']}
                    noMatchesLabel={t['management.table.noMatches']}
                    options={subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))}
                  />
                </GeoField>

                <GeoField label={t['management.books.academicYear']} required error={fieldErrors.academic_year_id}>
                  <SearchableSelect
                    value={values.academic_year_id}
                    onChange={(next) => onChange('academic_year_id', next)}
                    placeholder={t['management.books.academicYear']}
                    searchPlaceholder={t['management.table.search']}
                    noMatchesLabel={t['management.table.noMatches']}
                    options={academicYears.map((year) => ({ value: String(year.id), label: year.name }))}
                  />
                </GeoField>

                <GeoField label={t['management.exams.colMode']} required error={fieldErrors.selection_mode}>
                  <select
                    className={inputClass}
                    value={values.selection_mode}
                    onChange={(event) => onChange('selection_mode', event.target.value as ExamSelectionMode)}
                  >
                    <option value="manual">{t['management.exams.mode.manual']}</option>
                    <option value="fixed_random">{t['management.exams.mode.fixed_random']}</option>
                    <option value="per_attempt_random">{t['management.exams.mode.per_attempt_random']}</option>
                  </select>
                </GeoField>
              </>
            )}

            <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
              <input className={inputClass} value={values.name} onChange={(event) => onChange('name', event.target.value)} />
            </GeoField>

            <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
              <input className={inputClass} value={values.bn_name} onChange={(event) => onChange('bn_name', event.target.value)} />
            </GeoField>

            <GeoField label={t['management.exams.timeLimit']} error={fieldErrors.time_limit_minutes}>
              <input
                type="number"
                min={1}
                className={inputClass}
                value={values.time_limit_minutes}
                onChange={(event) => onChange('time_limit_minutes', event.target.value)}
              />
            </GeoField>

            <GeoField label={t['management.exams.maxAttempts']} error={fieldErrors.max_attempts}>
              <input
                type="number"
                min={1}
                className={inputClass}
                value={values.max_attempts}
                onChange={(event) => onChange('max_attempts', event.target.value)}
              />
            </GeoField>

            <GeoField label={t['management.exams.passingScore']} error={fieldErrors.passing_score_percent}>
              <input
                type="number"
                min={0}
                max={100}
                className={inputClass}
                value={values.passing_score_percent}
                onChange={(event) => onChange('passing_score_percent', event.target.value)}
              />
            </GeoField>

            <GeoField label={t['management.exams.availableFrom']} error={fieldErrors.available_from}>
              <input
                type="datetime-local"
                className={inputClass}
                value={values.available_from}
                onChange={(event) => onChange('available_from', event.target.value)}
              />
            </GeoField>

            <GeoField label={t['management.exams.availableUntil']} error={fieldErrors.available_until}>
              <input
                type="datetime-local"
                className={inputClass}
                value={values.available_until}
                onChange={(event) => onChange('available_until', event.target.value)}
              />
            </GeoField>

            {values.selection_mode !== 'manual' && (
              <>
                <GeoField label={t['management.exams.randomCount']} required error={fieldErrors.random_question_count}>
                  <input
                    type="number"
                    min={1}
                    className={inputClass}
                    value={values.random_question_count}
                    onChange={(event) => onChange('random_question_count', event.target.value)}
                  />
                </GeoField>

                <GeoField label={t['management.exams.randomDifficulty']} error={fieldErrors.random_difficulty}>
                  <select
                    className={inputClass}
                    value={values.random_difficulty}
                    onChange={(event) => onChange('random_difficulty', event.target.value as QuestionDifficulty)}
                  >
                    <option value="">{t['management.exams.anyDifficulty']}</option>
                    <option value="easy">{t['management.examQuestions.difficulty.easy']}</option>
                    <option value="medium">{t['management.examQuestions.difficulty.medium']}</option>
                    <option value="hard">{t['management.examQuestions.difficulty.hard']}</option>
                  </select>
                </GeoField>
              </>
            )}

            {values.selection_mode === 'per_attempt_random' && (
              <GeoField label={t['management.exams.marksPerQuestion']} required error={fieldErrors.marks_per_question}>
                <input
                  type="number"
                  min={1}
                  className={inputClass}
                  value={values.marks_per_question}
                  onChange={(event) => onChange('marks_per_question', event.target.value)}
                />
              </GeoField>
            )}
          </>
        )}
        fetchPage={(_page, filters) =>
          listExams({ subjectId: filters.parentId }, token ?? undefined).then((result) =>
            toSinglePage(result.data.filter((exam) => matchesSearch(exam.name, exam.bn_name, filters.search))),
          )
        }
        createRow={(values, token) =>
          createExam(
            {
              class_version_id: Number(values.class_version_id),
              subject_id: Number(values.subject_id),
              academic_year_id: Number(values.academic_year_id),
              name: values.name,
              bn_name: values.bn_name || null,
              selection_mode: values.selection_mode as ExamSelectionMode,
              time_limit_minutes: values.time_limit_minutes ? Number(values.time_limit_minutes) : null,
              max_attempts: values.max_attempts ? Number(values.max_attempts) : null,
              passing_score_percent: values.passing_score_percent ? Number(values.passing_score_percent) : null,
              available_from: values.available_from || null,
              available_until: values.available_until || null,
              random_question_count: values.random_question_count ? Number(values.random_question_count) : null,
              random_difficulty: (values.random_difficulty as QuestionDifficulty) || null,
              marks_per_question: values.marks_per_question ? Number(values.marks_per_question) : null,
            },
            token,
          )
        }
        updateRow={(id, values, token) =>
          updateExam(
            id,
            {
              name: values.name,
              bn_name: values.bn_name || null,
              time_limit_minutes: values.time_limit_minutes ? Number(values.time_limit_minutes) : null,
              max_attempts: values.max_attempts ? Number(values.max_attempts) : null,
              passing_score_percent: values.passing_score_percent ? Number(values.passing_score_percent) : null,
              available_from: values.available_from || null,
              available_until: values.available_until || null,
              random_question_count: values.random_question_count ? Number(values.random_question_count) : null,
              random_difficulty: (values.random_difficulty as QuestionDifficulty) || null,
              marks_per_question: values.marks_per_question ? Number(values.marks_per_question) : null,
              status: values.status as 'draft' | 'published',
            },
            token,
          )
        }
        deleteRow={(id, token) => deleteExam(id, token)}
        createPermission="exams.create"
        updatePermission="exams.update"
        deletePermission="exams.delete"
        rowActions={(row) => (
          <button
            type="button"
            className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
            onClick={() => setManageExamId(row.id)}
            aria-label={t['management.exams.manageQuestions']}
            title={t['management.exams.manageQuestions']}
          >
            <span className="material-symbols-outlined !text-md">checklist</span>
          </button>
        )}
        parentFilter={{
          label: t['management.fields.subject'],
          allLabel: t['management.fields.allSubjects'],
          options: subjects.map((subject) => ({ id: subject.id, label: subject.name })),
        }}
      />

      {manageExamId !== null && <ManageExamQuestionsModal examId={manageExamId} onClose={() => setManageExamId(null)} />}
    </>
  )
}
