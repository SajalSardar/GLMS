<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'order_index' => $this->order_index,
            'chapter_number' => $this->chapter_number,
            'bn_chapter_number' => $this->bn_chapter_number,
            'description' => $this->description,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
        ];
    }
}
