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
        $videos = ChapterVideo::query()
            ->with('chapter')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return ChapterVideoResource::collection($videos);
    }

    public function show(ChapterVideo $chapter_video): ChapterVideoResource
    {
        return new ChapterVideoResource($chapter_video->load('chapter'));
    }

    public function store(StoreChapterVideoRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterVideo::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->validated();
        $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
        unset($data['youtube_url']);
        $data['school_id'] = $chapter->school_id;
        $data['district_id'] = $chapter->district_id;

        $video = ChapterVideo::create($data);

        return (new ChapterVideoResource($video->load('chapter')))
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

        return new ChapterVideoResource($chapter_video->load('chapter'));
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
                ChapterVideo::where('id', $id)->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }
}
