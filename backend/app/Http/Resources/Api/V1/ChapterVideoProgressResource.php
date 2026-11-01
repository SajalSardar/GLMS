<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterVideoProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_video_id' => $this->chapter_video_id,
            'status' => $this->status,
            'max_position_seconds' => $this->max_position_seconds,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
