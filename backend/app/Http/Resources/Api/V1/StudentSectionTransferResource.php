<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSectionTransferResource extends JsonResource
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
            'student_profile_id' => $this->student_profile_id,
            'from_section_id' => $this->from_section_id,
            'to_section_id' => $this->to_section_id,
            'transferred_by' => $this->transferred_by,
            'reason' => $this->reason,
            'transferred_at' => $this->transferred_at?->toIso8601String(),
            'from_section' => new SectionResource($this->whenLoaded('fromSection')),
            'to_section' => new SectionResource($this->whenLoaded('toSection')),
        ];
    }
}
