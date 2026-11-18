<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassSchoolResource extends JsonResource
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
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'school' => new SchoolResource($this->whenLoaded('school')),
        ];
    }
}
