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
use Illuminate\Database\QueryException;
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
            ->when(
                ! $request->user()?->hasPermission('chapter_videos.update'),
                fn ($query) => $query->where('is_enabled', true)
            )
            ->orderBy('chapter_id')
            ->orderBy('order_index')
            ->get();

        return ChapterVideoResource::collection($videos);
    }

    public function show(ChapterVideo $chapter_video): ChapterVideoResource
    {
        if (! $chapter_video->is_enabled && ! request()->user()?->hasPermission('chapter_videos.update')) {
            abort(404);
        }

        return new ChapterVideoResource($chapter_video->load('chapter'));
    }

    public function store(StoreChapterVideoRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterVideo::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->validated();
        $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
        unset($data['youtube_url']);

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
                ChapterVideo::where('id', $id)->where('chapter_id', $request->validated()['chapter_id'])->update(['order_index' => $index]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    public function updateProgress(UpdateChapterVideoProgressRequest $request, ChapterVideo $chapter_video): JsonResponse
    {
        $position = $request->integer('position_seconds');
        $reportedDuration = $request->input('duration_seconds');

        // Only backfill the shared duration from a client report when it's
        // internally consistent with the position being reported in the
        // same request — otherwise a single bad/short report could
        // permanently corrupt the completion calculation for every student
        // watching this video.
        if ($reportedDuration && $reportedDuration >= $position && ! $chapter_video->duration_seconds) {
            $chapter_video->update(['duration_seconds' => $reportedDuration]);
        }

        $duration = $chapter_video->duration_seconds ?? $reportedDuration;

        // Never persist a reported position beyond the known duration.
        $position = $duration ? min($position, $duration) : $position;

        $threshold = $chapter_video->completion_threshold_percent ?? config('content.video_completion_threshold_percent');

        $progress = ChapterVideoProgress::firstOrNew([
            'chapter_video_id' => $chapter_video->id,
            'user_type' => get_class($request->user()),
            'user_id' => $request->user()->id,
        ]);

        $this->applyVideoProgress($progress, $position, $duration, $threshold);

        try {
            $progress->save();
        } catch (QueryException $e) {
            // Two near-simultaneous first-time reports for the same
            // (chapter_video_id, user_id) pair can both pass the
            // firstOrNew() lookup above before either has saved. Only one
            // insert wins the unique constraint; retry as a read-then-update
            // against the row the other request just created.
            if (! str_contains($e->getMessage(), 'UNIQUE constraint') && $e->getCode() !== '23000') {
                throw $e;
            }

            $progress = ChapterVideoProgress::where('chapter_video_id', $chapter_video->id)
                ->where('user_type', get_class($request->user()))
                ->where('user_id', $request->user()->id)
                ->first();

            $this->applyVideoProgress($progress, $position, $duration, $threshold);
            $progress->save();
        }

        // Force 200: Laravel's resource response otherwise returns 201 when
        // the underlying progress record was just created by firstOrNew(),
        // but this endpoint represents "report state" at a URI scoped to
        // the video, not creation of a progress resource.
        return (new ChapterVideoProgressResource($progress))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Apply the high-water-mark position and completion status logic to a
     * progress record, without saving it. Shared by the happy path and the
     * unique-constraint race-recovery path in updateProgress(), so both
     * apply identical rules to whichever row ends up being the record of
     * truth.
     */
    private function applyVideoProgress(ChapterVideoProgress $progress, int $position, ?int $duration, int $threshold): void
    {
        if ($position > $progress->max_position_seconds) {
            $progress->max_position_seconds = $position;
        }

        $progress->started_at ??= now();

        if ($progress->status !== 'completed') {
            if ($duration && $duration > 0 && ($progress->max_position_seconds / $duration) * 100 >= $threshold) {
                $progress->status = 'completed';
                $progress->completed_at = now();
            } else {
                $progress->status = 'in_progress';
            }
        }
    }
}
