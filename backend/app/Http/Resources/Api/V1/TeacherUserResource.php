<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'label' => $this->role->label,
                'permissions' => $this->role->permissions->pluck('name'),
            ]),
            'school' => new SchoolResource($this->whenLoaded('school')),
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
