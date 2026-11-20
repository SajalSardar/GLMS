<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
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
            'academic_year_id' => $this->academic_year_id,
            'type' => $this->type,
            'day_of_week' => $this->day_of_week,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
        ];
    }
}
