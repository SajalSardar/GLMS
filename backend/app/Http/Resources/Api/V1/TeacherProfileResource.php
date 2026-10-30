<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherProfileResource extends JsonResource
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
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'employee_id' => $this->employee_id,
            'designation' => $this->designation,
            'subject_specialization' => $this->subject_specialization,
            'qualification' => $this->qualification,
            'joining_date' => $this->joining_date?->toDateString(),
            'nid_number' => $this->nid_number,
            'phone' => $this->phone,
            'blood_group' => $this->blood_group,
            'present_address' => $this->present_address,
            'permanent_address' => $this->permanent_address,
            'photo_path' => $this->photo_path,
            'user' => new UserResource($this->whenLoaded('user')),
            'school' => new SchoolResource($this->whenLoaded('school')),
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
