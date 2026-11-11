<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectionResource extends JsonResource
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
            'shift_id' => $this->shift_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'code' => $this->code,
            'capacity' => $this->capacity,
            'class_teacher_id' => $this->class_teacher_id,
            'default_classroom_id' => $this->default_classroom_id,
            'is_active' => $this->is_active,
            // Deliberately minimal rather than UserResource: this section
            // may be listed on a publicly readable route, and UserResource
            // includes the teacher's email/phone.
            'class_teacher' => $this->whenLoaded('classTeacher', fn () => $this->classTeacher === null ? null : [
                'id' => $this->classTeacher->id,
                'name' => $this->classTeacher->name,
            ]),
            'default_classroom' => new ClassroomResource($this->whenLoaded('defaultClassroom')),
            'shift' => new ShiftResource($this->whenLoaded('shift')),
        ];
    }
}
