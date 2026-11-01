<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'type' => $this->type,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'order_index' => $this->order_index,
            'status' => $this->status,
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
