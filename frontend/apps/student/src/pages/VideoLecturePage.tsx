import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError, listClasses, type SchoolClass, type Subject } from '@ttla/api-client'
import { subjectsForClass } from '../lib/resolveContent'
import { useLocale } from '../lib/locale'

// Same pattern as BooksPage.tsx — see the comment there for why a
// translated `fallback` is used instead of the raw `error.message`.
function messageFromApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstFieldErrors = error.errors ? Object.values(error.errors)[0] : undefined
    return firstFieldErrors?.[0] ?? fallback
  }

  return fallback
}

type CardTone = 'primary' | 'secondary'

const TONE_CLASSES: Record<CardTone, { border: string; iconBg: string }> = {
  primary: { border: 'border-primary-200 hover:border-primary-400', iconBg: 'bg-primary-500' },
  secondary: { border: 'border-[#f6c3c5] hover:border-[#EB1F27]', iconBg: 'bg-[#EB1F27]' },
}

function BookIcon() {
  return (
    <svg
      width={22}
      height={22}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      className="text-white"
    >
      <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v18H6.5A2.5 2.5 0 0 1 4 18.5v-13Z" />
      <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v18h5.5a2.5 2.5 0 0 0 2.5-2.5v-13Z" />
    </svg>
  )
}

function SubjectCard({
  subject,
  tone,
  startLabel,
  onStart,
}: {
  subject: Subject
  tone: CardTone
  startLabel: string
  onStart: () => void
}) {
  return (
    <div
      className={`flex flex-col items-center gap-3 rounded-xl border bg-white p-5 text-center shadow-sm transition ${TONE_CLASSES[tone].border}`}
    >
      <div className={`flex h-12 w-12 items-center justify-center rounded-full ${TONE_CLASSES[tone].iconBg}`}>
        <BookIcon />
      </div>
      <p className="font-semibold text-primary-800">{subject.bn_name ?? subject.name}</p>
      <button
        type="button"
        onClick={onStart}
        className="w-full cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#EB1F27]"
      >
        {startLabel}
      </button>
    </div>
  )
}

export default function VideoLecturePage() {
  const { t } = useLocale()
  const navigate = useNavigate()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classId, setClassId] = useState<number | null>(null)

  const [subjects, setSubjects] = useState<Subject[]>([])
  const [subjectsLoading, setSubjectsLoading] = useState(false)
  const [subjectsError, setSubjectsError] = useState<string | null>(null)

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))
  }, [])

  useEffect(() => {
    setSubjects([])

    if (!classId) return

    let active = true
    setSubjectsLoading(true)
    setSubjectsError(null)

    subjectsForClass(classId)
      .then((result) => {
        if (active) setSubjects(result)
      })
      .catch((caught) => {
        if (active) {
          setSubjects([])
          setSubjectsError(messageFromApiError(caught, t['student.videoLecture.loadError']))
        }
      })
      .finally(() => {
        if (active) setSubjectsLoading(false)
      })

    return () => {
      active = false
    }
  }, [classId, t])

  return (
    <section className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.videoLecture.title']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <select
          className="h-10 rounded-md border border-primary-200 px-3 text-sm"
          value={classId ?? ''}
          onChange={(event) => setClassId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['student.videoLecture.allClasses']}</option>
          {classes.map((schoolClass) => (
            <option key={schoolClass.id} value={schoolClass.id}>
              {schoolClass.bn_name ?? schoolClass.name}
            </option>
          ))}
        </select>
      </div>

      {!classId && <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.pickClass']}</p>}

      {classId && (
        <>
          {subjectsLoading && <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.loading']}</p>}

          {!subjectsLoading && subjectsError && <p className="mt-6 text-sm text-danger-600">{subjectsError}</p>}

          {!subjectsLoading && !subjectsError && subjects.length === 0 && (
            <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.noSubjects']}</p>
          )}

          <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {subjects.map((subject, index) => (
              <SubjectCard
                key={subject.id}
                subject={subject}
                tone={index % 2 === 0 ? 'primary' : 'secondary'}
                startLabel={t['student.videoLecture.start']}
                onStart={() => navigate(`/video-lecture/${classId}/${subject.id}`)}
              />
            ))}
          </div>
        </>
      )}
    </section>
  )
}
