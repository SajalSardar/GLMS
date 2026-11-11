<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassroomResource extends JsonResource
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
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'building' => $this->building,
            'floor' => $this->floor,
            'capacity' => $this->capacity,
            'description' => $this->description,
            'school' => new SchoolResource($this->whenLoaded('school')),
        ];
    }
}
