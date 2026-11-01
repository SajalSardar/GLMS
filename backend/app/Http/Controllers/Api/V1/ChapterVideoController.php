<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReorderChapterVideosRequest;
use App\Http\Requests\Api\V1\StoreChapterVideoRequest;
use App\Http\Requests\Api\V1\UpdateChapterVideoProgressRequest;
use App\Http\Requests\Api\V1\UpdateChapterVideoRequest;
use App\Http\Resources\Api\V1\ChapterVideoProgressResource;
use App\Http\Resources\Api\V1\ChapterVideoResource;
use App\Models\Chapter;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
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

    public function updateProgress(UpdateChapterVideoProgressRequest $request, ChapterVideo $chapter_video): JsonResponse
    {
        $position = $request->integer('position_seconds');
        $reportedDuration = $request->input('duration_seconds');

        if ($reportedDuration && ! $chapter_video->duration_seconds) {
            $chapter_video->update(['duration_seconds' => $reportedDuration]);
        }

        $progress = ChapterVideoProgress::firstOrNew([
            'chapter_video_id' => $chapter_video->id,
            'user_id' => $request->user()->id,
        ]);

        if (! $progress->exists) {
            $progress->status = 'not_started';
            $progress->max_position_seconds = 0;
        }

        if ($position > $progress->max_position_seconds) {
            $progress->max_position_seconds = $position;
        }

        $progress->started_at ??= now();

        $duration = $chapter_video->duration_seconds ?? $reportedDuration;
        $threshold = $chapter_video->completion_threshold_percent ?? config('content.video_completion_threshold_percent');

        if ($progress->status !== 'completed') {
            if ($duration && $duration > 0 && ($progress->max_position_seconds / $duration) * 100 >= $threshold) {
                $progress->status = 'completed';
                $progress->completed_at = now();
            } else {
                $progress->status = 'in_progress';
            }
        }

        $progress->save();

        // Force 200: Laravel's resource response otherwise returns 201 when
        // the underlying progress record was just created by firstOrNew(),
        // but this endpoint represents "report state" at a URI scoped to
        // the video, not creation of a progress resource.
        return (new ChapterVideoProgressResource($progress))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
