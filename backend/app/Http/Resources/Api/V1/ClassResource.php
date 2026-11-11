<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassResource extends JsonResource
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
            'bn_name' => $this->bn_name,
            'numeric_level' => $this->numeric_level,
            'section' => $this->section,
            'school' => new SchoolResource($this->whenLoaded('school')),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
        ];
    }
}
