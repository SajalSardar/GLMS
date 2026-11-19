import {
  listAcademicYears,
  listBooks,
  listChapters,
  listChapterVideos,
  listClassVersions,
  type Book,
  type Chapter,
  type ChapterVideo,
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

// BookController/ChapterController/ChapterVideoController::index() all
// return every row unfiltered (and unpaginated) when called with no
// params — confirmed by reading the backend controllers, which only apply
// a `->where(...)` when the corresponding query param is actually present.
// So "every book/chapter/chapter-video platform-wide" is one cheap call
// each, cached for the page's lifetime, and every class/subject-scoped
// helper below filters these in memory instead of asking the backend to
// filter per class-version/book/chapter. An earlier version of this file
// called listBooks()/listChapters()/listChapterVideos() once PER
// class-version/book/chapter it had already fetched — harmless for a
// single class+subject lookup, but classSubjectPairsWithVideos() (which
// scans every class) turned that into 1000+ requests in practice.
let allBooksPromise: Promise<Book[]> | null = null

function allBooks(): Promise<Book[]> {
  if (!allBooksPromise) {
    allBooksPromise = listBooks().then((result) => result.data)
  }

  return allBooksPromise
}

let allChaptersPromise: Promise<Chapter[]> | null = null

function allChapters(): Promise<Chapter[]> {
  if (!allChaptersPromise) {
    allChaptersPromise = listChapters().then((result) => result.data)
  }

  return allChaptersPromise
}

let allChapterVideosPromise: Promise<ChapterVideo[]> | null = null

// Only ever used to answer "does this chapter have at least one (enabled)
// video" — the actual playable video list for ONE chapter is still fetched
// directly via listChapterVideos({ chapterId }) where it's needed (that's
// already a single, correctly-scoped call, not N+1).
function allChapterVideos(): Promise<ChapterVideo[]> {
  if (!allChapterVideosPromise) {
    allChapterVideosPromise = listChapterVideos().then((result) => result.data)
  }

  return allChapterVideosPromise
}

// The "browse every book" entry point for BooksPage's default view — every
// book for the current academic year, across every class/subject/school,
// built from the same cached allBooks() the class/subject-scoped helpers
// below already use (so visiting BooksPage after VideoLecturePage, or vice
// versa, doesn't re-fetch /books a second time).
export async function currentYearBooks(): Promise<Book[]> {
  const [books, academicYearId] = await Promise.all([allBooks(), currentAcademicYearId()])

  return academicYearId === null ? books : books.filter((book) => book.academic_year_id === academicYearId)
}

// Books (and, one level deeper, chapters/videos) aren't nationally unified —
// each school has its own `ClassVersion` row for a given class, and that
// version carries its own books. So browsing "Class Six Math" means every
// school's version of that class. This is the shared first stage used by
// both BooksPage (which stops here) and VideoLecturePage (which continues
// on to chapters and videos).
//
// Results are further filtered down to the current academic year's books
// only — a book created for a past/future year shouldn't appear to a
// browsing student.
export async function booksForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Book[]> {
  if (!classId) return []

  const [versionsResult, academicYearId, books] = await Promise.all([
    listClassVersions({ classId, perPage: 500 }),
    currentAcademicYearId(),
    allBooks(),
  ])

  const versionIds = new Set(versionsResult.data.map((version) => version.id))

  return books.filter(
    (book) =>
      versionIds.has(book.class_version_id) &&
      (subjectId === null || book.subject_id === subjectId) &&
      (academicYearId === null || book.academic_year_id === academicYearId),
  )
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
  const [books, chapters] = await Promise.all([booksForClassAndSubject(classId, subjectId), allChapters()])
  const bookById = new Map(books.map((book) => [book.id, book]))

  return chapters
    .filter((chapter) => bookById.has(chapter.book_id))
    .map((chapter) => ({ chapter, book: bookById.get(chapter.book_id)! }))
}

export interface ChapterWithVideoCount {
  chapter: Chapter
  book: Book
  videoCount: number
}

// One level deeper than `chaptersForClassAndSubject`: for every chapter,
// check for at least one (enabled) video.
export async function chaptersWithVideosForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithVideoCount[]> {
  const [chaptersWithBooks, videos] = await Promise.all([
    chaptersForClassAndSubject(classId, subjectId),
    allChapterVideos(),
  ])

  const videoCountByChapterId = new Map<number, number>()
  for (const video of videos) {
    videoCountByChapterId.set(video.chapter_id, (videoCountByChapterId.get(video.chapter_id) ?? 0) + 1)
  }

  return chaptersWithBooks
    .map(({ chapter, book }) => ({ chapter, book, videoCount: videoCountByChapterId.get(chapter.id) ?? 0 }))
    .filter((entry) => entry.videoCount > 0)
}

export interface ClassSubjectPair {
  classId: number
  subjectId: number
  // Every ClassVersion (school's own offering of this class) that
  // contributed at least one video to this pair — since chapters pool
  // cross-school, a subject can have videos from more than one version.
  // The page decides what to do with more than one (e.g. only label the
  // card with a version name when there's exactly one).
  versionIds: number[]
}

// The "browse everything" entry point for VideoLecturePage's default view —
// every (class, subject) pair that has at least one video, ACROSS every
// class. Built entirely from the cached allBooks()/allChapters()/
// allChapterVideos() plus one ClassVersion fetch, so this costs a handful
// of requests total regardless of how many classes/subjects/chapters exist
// — see the cache comment above for why that matters here specifically.
export async function classSubjectPairsWithVideos(): Promise<ClassSubjectPair[]> {
  const [versionsResult, academicYearId, books, chapters, videos] = await Promise.all([
    listClassVersions({ perPage: 500 }),
    currentAcademicYearId(),
    allBooks(),
    allChapters(),
    allChapterVideos(),
  ])

  const classIdByVersionId = new Map(versionsResult.data.map((version) => [version.id, version.class_id]))
  const chapterIdsWithVideo = new Set(videos.map((video) => video.chapter_id))

  const classAndSubjectByBookId = new Map<number, { classId: number; subjectId: number; versionId: number }>()
  for (const book of books) {
    if (academicYearId !== null && book.academic_year_id !== academicYearId) continue

    const classId = classIdByVersionId.get(book.class_version_id)
    if (classId === undefined) continue

    classAndSubjectByBookId.set(book.id, { classId, subjectId: book.subject_id, versionId: book.class_version_id })
  }

  const pairsWithVideo = new Map<string, { classId: number; subjectId: number; versionIds: Set<number> }>()
  for (const chapter of chapters) {
    if (!chapterIdsWithVideo.has(chapter.id)) continue

    const info = classAndSubjectByBookId.get(chapter.book_id)
    if (!info) continue

    const key = `${info.classId}:${info.subjectId}`
    const existing = pairsWithVideo.get(key)

    if (existing) {
      existing.versionIds.add(info.versionId)
    } else {
      pairsWithVideo.set(key, { classId: info.classId, subjectId: info.subjectId, versionIds: new Set([info.versionId]) })
    }
  }

  return [...pairsWithVideo.values()].map((pair) => ({
    classId: pair.classId,
    subjectId: pair.subjectId,
    versionIds: [...pair.versionIds],
  }))
}
