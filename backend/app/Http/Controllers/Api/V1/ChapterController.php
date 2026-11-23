<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChapterRequest;
use App\Http\Requests\Api\V1\UpdateChapterRequest;
use App\Http\Resources\Api\V1\ChapterResource;
use App\Models\Book;
use App\Models\Chapter;
use App\Support\Ordinal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ChapterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user('sanctum');

        $chapters = Chapter::query()
            ->with(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear'])
            ->when($request->integer('book_id'), fn ($query, $bookId) => $query->where('book_id', $bookId))
            ->when($request->integer('class_id'), fn ($query, $id) => $query->whereHas('book.classVersion', fn ($q) => $q->where('class_id', $id)))
            ->when($request->integer('version_id'), fn ($query, $id) => $query->whereHas('book.classVersion', fn ($q) => $q->where('version_id', $id)))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->whereHas('book', fn ($q) => $q->where('subject_id', $id)))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->whereHas('book', fn ($q) => $q->where('academic_year_id', $id)))
            ->when($actor?->school_id, fn ($query, $schoolId) => $query->whereHas('book.classVersion', fn ($q) => $q->where('school_id', $schoolId)))
            ->orderBy('book_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return ChapterResource::collection($chapters);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Chapter $chapter): ChapterResource
    {
        $actor = $request->user('sanctum');
        $chapter->loadMissing('book.classVersion');
        abort_if($actor?->school_id && $chapter->book?->classVersion?->school_id !== $actor->school_id, 404);

        return new ChapterResource($chapter->load(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted chapter with the same (book_id, name) is restored
     * and updated in place, rather than attempting a second insert that
     * would collide with the still-present unique index on a trashed row.
     */
    public function store(StoreChapterRequest $request): JsonResponse
    {
        $this->authorize('create', Chapter::class);

        $book = Book::with('classVersion')->findOrFail($request->integer('book_id'));
        $actor = $request->user('sanctum');
        abort_if($actor?->school_id && $book->classVersion?->school_id !== $actor->school_id, 404);

        $data = $this->applyAutoNumbering($request->validated());

        $chapter = Chapter::onlyTrashed()
            ->where('book_id', $book->id)
            ->where('name', $data['name'])
            ->first();

        if ($chapter) {
            $chapter->restore();
            $chapter->update($data);
        } else {
            $chapter = Chapter::create($data);
        }

        return (new ChapterResource($chapter->load(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateChapterRequest $request, Chapter $chapter): ChapterResource
    {
        $this->authorize('update', $chapter);

        $chapter->update($this->applyAutoNumbering($request->validated()));

        return new ChapterResource($chapter->load(['book.classVersion.schoolClass', 'book.subject', 'book.academicYear']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Chapter $chapter): Response
    {
        $this->authorize('delete', $chapter);

        $chapter->delete();

        return response()->noContent();
    }

    /**
     * Fill chapter_number/bn_chapter_number from order_index when the
     * caller gave an order_index but left the ordinal labels unset.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyAutoNumbering(array $data): array
    {
        if (($data['order_index'] ?? null) !== null) {
            $data['chapter_number'] ??= Ordinal::englishChapter($data['order_index']);
            $data['bn_chapter_number'] ??= Ordinal::banglaChapter($data['order_index']);
        }

        return $data;
    }
}
