<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassVersionResource extends JsonResource
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
            'class_id' => $this->class_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'is_active' => $this->is_active,
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'shifts' => ShiftResource::collection($this->whenLoaded('shifts')),
        ];
    }
}
