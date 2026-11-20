import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { type SchoolClass, type Subject } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { allClasses, allClassVersions, allSubjects, classSubjectPairsWithVideos, ownClassId } from '../../lib/resolveContent'
import { useLocale } from '../../lib/locale'
import SearchableSelect from './components/SearchableSelect'

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

// One card per (class, subject) pair that actually has at least one video —
// a subject with zero videos anywhere is never shown, and since the same
// subject can appear under several classes, each card carries its own class
// name so two cards for e.g. "Mathematics" (Class 5 vs Class 6) are
// distinguishable. versionName is only set when every video behind this
// card came from the SAME school's class-version (the common case) — when
// a subject's videos are pooled from more than one version, there's no
// single version name that would be accurate to show, so the card falls
// back to just the class name.
interface SubjectCardEntry {
  classId: number
  className: string
  versionName: string | null
  subject: Subject
}

function SubjectCard({
  entry,
  tone,
  startLabel,
  onStart,
}: {
  entry: SubjectCardEntry
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
      <p className="font-semibold text-primary-800">{entry.subject.bn_name ?? entry.subject.name}</p>
      <p className="text-xs text-primary-500">
        {entry.versionName ? `${entry.className} - ${entry.versionName}` : entry.className}
      </p>
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

// Dashboard-only variant of the public website's VideoLecturePage (src/
// pages/VideoLecturePage.tsx) — same data/filtering logic, styled to match
// the management portal's dashboard pages (full-width layout, the shared
// SearchableSelect dropdown) instead of the public site's narrower,
// native-<select> style. Kept separate rather than a shared component with
// style branches, since the two contexts intentionally look nothing alike.
export default function VideoLecturePage() {
  const { t } = useLocale()
  const { token, userId } = useAuth()
  const navigate = useNavigate()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [classesById, setClassesById] = useState<Map<number, SchoolClass>>(new Map())
  const [classFilter, setClassFilter] = useState<number | null>(null)

  // Same pattern as BooksPage.tsx — a logged-in student's own class locks
  // the filter (shown as a badge) instead of a dropdown over every class.
  const [ownClassLocked, setOwnClassLocked] = useState(false)

  const [allEntries, setAllEntries] = useState<SubjectCardEntry[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!token || !userId) return

    let active = true

    ownClassId(userId, token)
      .then((result) => {
        if (!active || result === null) return
        setClassFilter(result)
        setOwnClassLocked(true)
      })
      .catch(() => {})

    return () => {
      active = false
    }
  }, [token, userId])

  useEffect(() => {
    let active = true
    setLoading(true)

    Promise.all([allClasses(), allSubjects(), allClassVersions(), classSubjectPairsWithVideos()])
      .then(([classesList, subjectsList, versions, pairs]) => {
        if (!active) return

        const classesById = new Map(classesList.map((schoolClass) => [schoolClass.id, schoolClass]))
        const subjectsById = new Map(subjectsList.map((subject) => [subject.id, subject]))
        const versionsById = new Map(versions.map((version) => [version.id, version]))

        setClasses(classesList)
        setClassesById(classesById)

        const entries = pairs
          .map((pair) => {
            const schoolClass = classesById.get(pair.classId)
            const subject = subjectsById.get(pair.subjectId)
            if (!schoolClass || !subject) return null

            const version = pair.versionIds.length === 1 ? versionsById.get(pair.versionIds[0]) : undefined
            const versionName = version ? (version.bn_name ?? version.name) : null

            return { classId: pair.classId, className: schoolClass.bn_name ?? schoolClass.name, versionName, subject }
          })
          .filter((entry): entry is SubjectCardEntry => entry !== null)

        setAllEntries(entries)
      })
      .catch(() => {
        if (active) {
          setClasses([])
          setAllEntries([])
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  const visibleEntries = classFilter === null ? allEntries : allEntries.filter((entry) => entry.classId === classFilter)
  const ownClass = ownClassLocked && classFilter !== null ? classesById.get(classFilter) : undefined

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-800">{t['student.videoLecture.title']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        {ownClassLocked ? (
          <span className="inline-flex h-11 items-center rounded-md bg-primary-50 px-3 text-sm font-semibold text-primary-700">
            {t['student.videoLecture.yourClass'].replace('{class}', ownClass ? (ownClass.bn_name ?? ownClass.name) : '')}
          </span>
        ) : (
          <div className="w-full sm:w-56">
            <SearchableSelect
              value={classFilter !== null ? String(classFilter) : ''}
              onChange={(value) => setClassFilter(value ? Number(value) : null)}
              placeholder={t['student.videoLecture.allClasses']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={[
                { value: '', label: t['student.videoLecture.allClasses'] },
                ...classes.map((schoolClass) => ({
                  value: String(schoolClass.id),
                  label: schoolClass.bn_name ?? schoolClass.name,
                })),
              ]}
            />
          </div>
        )}
      </div>

      {loading && <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.loading']}</p>}

      {!loading && visibleEntries.length === 0 && (
        <p className="mt-6 text-sm text-primary-600">{t['student.videoLecture.empty']}</p>
      )}

      {!loading && visibleEntries.length > 0 && (
        <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {visibleEntries.map((entry, index) => (
            <SubjectCard
              key={`${entry.classId}-${entry.subject.id}`}
              entry={entry}
              tone={index % 2 === 0 ? 'primary' : 'secondary'}
              startLabel={t['student.videoLecture.start']}
              onStart={() => navigate(`/video-lecture/${entry.classId}/${entry.subject.id}`)}
            />
          ))}
        </div>
      )}
    </section>
  )
}
