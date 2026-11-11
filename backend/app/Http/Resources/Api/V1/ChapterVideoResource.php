<?php

namespace App\Http\Resources\Api\V1;

use App\Support\YoutubeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterVideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'chapter_id' => $this->chapter_id,
            'youtube_video_id' => $this->youtube_video_id,
            'embed_url' => YoutubeUrl::embedUrl($this->youtube_video_id),
            'title' => $this->title,
            'bn_title' => $this->bn_title,
            'description' => $this->description,
            'instructor_name' => $this->instructor_name,
            'duration_seconds' => $this->duration_seconds,
            'learning_objectives' => $this->learning_objectives,
            'completion_threshold_percent' => $this->completion_threshold_percent,
            'order_index' => $this->order_index,
            'is_enabled' => $this->is_enabled,
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
