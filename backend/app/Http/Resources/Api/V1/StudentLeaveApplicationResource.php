<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentLeaveApplicationResource extends JsonResource
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
            'student_profile_id' => $this->student_profile_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class_id' => $this->class_id,
            'section_id' => $this->section_id,
            'shift_id' => $this->shift_id,
            'class_version_id' => $this->class_version_id,
            'academic_year_id' => $this->academic_year_id,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'teacher_comment' => $this->teacher_comment,
            'reviewed_by_id' => $this->reviewed_by_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'student_profile' => new StudentProfileResource($this->whenLoaded('studentProfile')),
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            // Deliberately minimal rather than UserResource — same
            // reasoning as ClassRoutineResource's own 'teacher' field.
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy === null ? null : [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            // Lets the frontend show the approve/reject/comment form only
            // where review() would actually allow it (the section's own
            // class teacher, or a staff actor with the .update
            // permission) — the client has no way to re-derive the
            // class-teacher check itself.
            'can_review' => $request->user()?->can('review', $this->resource) ?? false,
        ];
    }
}
