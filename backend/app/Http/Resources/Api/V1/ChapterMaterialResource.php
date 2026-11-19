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
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'updated_by_type' => $this->updated_by_type,
            'updated_by_id' => $this->updated_by_id,
            'chapter_id' => $this->chapter_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'shift_id' => $this->shift_id,
            'class_version_id' => $this->class_version_id,
            'class_id' => $this->class_id,
            'section_id' => $this->section_id,
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
