import {
  listAcademicYears,
  listBooks,
  listChapters,
  listChapterVideos,
  listClassVersions,
  type Book,
  type Chapter,
  type Subject,
} from '@ttla/api-client'

// There is exactly one current academic year nationally at a time
// (AcademicYearController::setCurrent() enforces this backend-side), so
// this is resolved once and reused across calls rather than re-fetched per
// class/subject lookup.
let currentAcademicYearIdPromise: Promise<number | null> | null = null

function currentAcademicYearId(): Promise<number | null> {
  if (!currentAcademicYearIdPromise) {
    currentAcademicYearIdPromise = listAcademicYears({ currentOnly: true, perPage: 1 }).then(
      (result) => result.data[0]?.id ?? null,
    )
  }

  return currentAcademicYearIdPromise
}

// Books (and, one level deeper, chapters/videos) aren't nationally unified —
// each school has its own `ClassVersion` row for a given class, and that
// version carries its own books. So browsing "Class Six Math" means querying
// every school's version of that class and merging the results. This is the
// shared first stage used by both BooksPage (which stops here) and
// VideoLecturePage (which continues on to chapters and videos).
//
// Results are further filtered down to the current academic year's books
// only — a book created for a past/future year shouldn't appear to a
// browsing student.
export async function booksForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Book[]> {
  if (!classId) return []

  const [versionsResult, academicYearId] = await Promise.all([
    listClassVersions({ classId, perPage: 500 }),
    currentAcademicYearId(),
  ])
  const versions = versionsResult.data

  const bookLists = await Promise.all(
    versions.map((version) => listBooks({ classVersionId: version.id, subjectId: subjectId ?? undefined })),
  )

  const books = bookLists.flatMap((result) => result.data)

  return academicYearId === null ? books : books.filter((book) => book.academic_year_id === academicYearId)
}

// "Which subjects does this class actually have" — not every subject
// nationally, but the union of subjects taught by every school's version
// of this class (via each ClassVersion's own `version_subjects` pivot,
// already eager-loaded by listClassVersions()). Reuses the same
// cross-school ClassVersion resolution as booksForClassAndSubject(), kept
// separate from it since this needs the versions' `subjects` field rather
// than their books.
export async function subjectsForClass(classId: number | null): Promise<Subject[]> {
  if (!classId) return []

  const versionsResult = await listClassVersions({ classId, perPage: 500 })
  const bySubjectId = new Map<number, Subject>()

  for (const version of versionsResult.data) {
    for (const subject of version.subjects ?? []) {
      bySubjectId.set(subject.id, subject)
    }
  }

  return [...bySubjectId.values()].sort((a, b) => a.name.localeCompare(b.name))
}

export interface ChapterWithBook {
  chapter: Chapter
  book: Book
}

// One level deeper than `booksForClassAndSubject`: every chapter under
// every cross-school book for this class/subject, paired with its own
// book. No video filtering here — `chaptersWithVideosForClassAndSubject`
// (below) adds that on top; the practice-exam chapter picker needs every
// chapter regardless of whether it has a video.
export async function chaptersForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithBook[]> {
  const books = await booksForClassAndSubject(classId, subjectId)

  const chapterLists = await Promise.all(
    books.map(async (book) => {
      const chaptersResult = await listChapters({ bookId: book.id })
      return chaptersResult.data.map((chapter) => ({ chapter, book }))
    }),
  )

  return chapterLists.flat()
}

export interface ChapterWithVideoCount {
  chapter: Chapter
  book: Book
  videoCount: number
}

// One level deeper than `chaptersForClassAndSubject`: for every chapter,
// check for at least one (enabled) video. This is an N+1 from the
// frontend's perspective (chapters -> per-chapter video lookup), on top
// of `chaptersForClassAndSubject`'s own already-accepted N+1.
export async function chaptersWithVideosForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithVideoCount[]> {
  const chaptersWithBooks = await chaptersForClassAndSubject(classId, subjectId)

  const withCounts = await Promise.all(
    chaptersWithBooks.map(async ({ chapter, book }) => {
      const videosResult = await listChapterVideos({ chapterId: chapter.id })
      return { chapter, book, videoCount: videosResult.data.length }
    }),
  )

  return withCounts.filter((entry) => entry.videoCount > 0)
}
