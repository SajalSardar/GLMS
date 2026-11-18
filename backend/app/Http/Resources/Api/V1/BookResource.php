<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookResource extends JsonResource
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
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'updated_by_type' => $this->updated_by_type,
            'updated_by_id' => $this->updated_by_id,
            'class_version_id' => $this->class_version_id,
            'subject_id' => $this->subject_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'order_index' => $this->order_index,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'pdf_original_filename' => $this->pdf_original_filename,
            'pdf_mime_type' => $this->pdf_mime_type,
            'pdf_size_bytes' => $this->pdf_size_bytes,
            'pdf_uploaded_at' => $this->pdf_uploaded_at,
            'pdf_status' => $this->pdf_status,
            'has_available_pdf' => $this->hasAvailablePdf(),
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'chapters' => ChapterResource::collection($this->whenLoaded('chapters')),
        ];
    }
}
