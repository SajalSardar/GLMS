<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
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
            'class_version_id' => $this->class_version_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'academic_year_id' => $this->academic_year_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'days_of_week' => $this->days_of_week,
            'period_duration_minutes' => $this->period_duration_minutes,
            'breaks' => $this->breaks,
            'is_active' => $this->is_active,
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
