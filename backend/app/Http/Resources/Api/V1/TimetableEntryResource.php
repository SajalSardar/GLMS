<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableEntryResource extends JsonResource
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
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'section_id' => $this->section_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'classroom_id' => $this->classroom_id,
            'academic_year_id' => $this->academic_year_id,
            'day_of_week' => $this->day_of_week,
            'period_no' => $this->period_no,
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'section' => new SectionResource($this->whenLoaded('section')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            // Deliberately minimal — see SectionResource's class_teacher.
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
            'classroom' => new ClassroomResource($this->whenLoaded('classroom')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
        ];
    }
}
