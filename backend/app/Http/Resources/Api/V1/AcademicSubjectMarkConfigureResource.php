<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicSubjectMarkConfigureResource extends JsonResource
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
            'class_id' => $this->class_id,
            'subject_id' => $this->subject_id,
            'cq_full_marks' => $this->cq_full_marks,
            'mcq_full_marks' => $this->mcq_full_marks,
            'practical_full_marks' => $this->practical_full_marks,
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
        ];
    }
}
