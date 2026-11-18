import { listBooks, listChapters, listChapterVideos, listClassVersions, type Book, type Chapter } from '@ttla/api-client'

// Books (and, one level deeper, chapters/videos) aren't nationally unified —
// each school has its own `ClassVersion` row for a given class, and that
// version carries its own books. So browsing "Class Six Math" means querying
// every school's version of that class and merging the results. This is the
// shared first stage used by both BooksPage (which stops here) and
// VideosPage (which continues on to chapters and videos).
export async function booksForClassAndSubject(classId: number | null, subjectId: number | null): Promise<Book[]> {
  if (!classId) return []

  const versionsResult = await listClassVersions({ classId, perPage: 500 })
  const versions = versionsResult.data

  const bookLists = await Promise.all(
    versions.map((version) => listBooks({ classVersionId: version.id, subjectId: subjectId ?? undefined })),
  )

  return bookLists.flatMap((result) => result.data)
}

export interface ChapterWithVideoCount {
  chapter: Chapter
  book: Book
  videoCount: number
}

// One level deeper than `booksForClassAndSubject`: for every cross-school
// book found for this class/subject, fetch its chapters, then check each
// chapter for at least one (enabled) video. This is an N+2 from the
// frontend's perspective (books -> chapters -> per-chapter video lookup),
// consistent with the already-accepted N+1 pattern `booksForClassAndSubject`
// itself uses for cross-school books.
export async function chaptersWithVideosForClassAndSubject(
  classId: number | null,
  subjectId: number | null,
): Promise<ChapterWithVideoCount[]> {
  const books = await booksForClassAndSubject(classId, subjectId)

  const chapterLists = await Promise.all(
    books.map(async (book) => {
      const chaptersResult = await listChapters({ bookId: book.id })
      return chaptersResult.data.map((chapter) => ({ chapter, book }))
    }),
  )

  const chaptersWithBooks = chapterLists.flat()

  const withCounts = await Promise.all(
    chaptersWithBooks.map(async ({ chapter, book }) => {
      const videosResult = await listChapterVideos({ chapterId: chapter.id })
      return { chapter, book, videoCount: videosResult.data.length }
    }),
  )

  return withCounts.filter((entry) => entry.videoCount > 0)
}
