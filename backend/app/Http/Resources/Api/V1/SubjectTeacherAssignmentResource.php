<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectTeacherAssignmentResource extends JsonResource
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
            'section_id' => $this->section_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'section' => new SectionResource($this->whenLoaded('section')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            // Deliberately minimal — see SectionResource's class_teacher.
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
        ];
    }
}
