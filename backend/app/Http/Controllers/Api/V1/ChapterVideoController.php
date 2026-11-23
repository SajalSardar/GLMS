<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderChapterVideosRequest;
use App\Http\Requests\Api\V1\StoreChapterVideoRequest;
use App\Http\Requests\Api\V1\UpdateChapterVideoRequest;
use App\Http\Resources\Api\V1\ChapterVideoResource;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Support\YoutubeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ChapterVideoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // This route sits outside the auth:sanctum group (it must also
        // serve anonymous requests), so the user is resolved explicitly
        // from the sanctum guard rather than via $request->user().
        $actor = $request->user('sanctum');

        $videos = ChapterVideo::query()
            ->with(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear'])
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->when($request->integer('book_id'), fn ($query, $id) => $query->whereHas('chapter', fn ($q) => $q->where('book_id', $id)))
            ->when($request->integer('class_id'), fn ($query, $id) => $query->whereHas('chapter.book.classVersion', fn ($q) => $q->where('class_id', $id)))
            ->when($request->integer('version_id'), fn ($query, $id) => $query->whereHas('chapter.book.classVersion', fn ($q) => $q->where('version_id', $id)))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->whereHas('chapter.book', fn ($q) => $q->where('subject_id', $id)))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->whereHas('chapter.book', fn ($q) => $q->where('academic_year_id', $id)))
            ->when(
                ! $actor?->hasPermission('chapter_videos.update'),
                fn ($query) => $query->where('is_enabled', true)
            )
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return ChapterVideoResource::collection($videos);
    }

    public function show(Request $request, ChapterVideo $chapter_video): ChapterVideoResource
    {
        // This route sits outside the auth:sanctum group, so the user is
        // resolved explicitly from the sanctum guard.
        if (! $chapter_video->is_enabled && ! $request->user('sanctum')?->hasPermission('chapter_videos.update')) {
            abort(404);
        }

        return new ChapterVideoResource($chapter_video->load(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear']));
    }

    public function store(StoreChapterVideoRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterVideo::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->validated();
        $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
        unset($data['youtube_url']);

        $video = ChapterVideo::create($data);

        return (new ChapterVideoResource($video->load(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateChapterVideoRequest $request, ChapterVideo $chapter_video): ChapterVideoResource
    {
        $this->authorize('update', $chapter_video);

        $data = $request->validated();

        if (array_key_exists('youtube_url', $data)) {
            $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
            unset($data['youtube_url']);
        }

        $chapter_video->update($data);

        return new ChapterVideoResource($chapter_video->load(['chapter.book.classVersion.schoolClass', 'chapter.book.subject', 'chapter.book.academicYear']));
    }

    public function destroy(ChapterVideo $chapter_video): Response
    {
        $this->authorize('delete', $chapter_video);

        $chapter_video->delete();

        return response()->noContent();
    }

    public function reorder(ReorderChapterVideosRequest $request): JsonResponse
    {
        $this->authorize('reorder', ChapterVideo::class);

        DB::transaction(function () use ($request) {
            foreach ($request->validated()['ordered_ids'] as $index => $id) {
                ChapterVideo::where('id', $id)->where('chapter_id', $request->validated()['chapter_id'])->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }
}
