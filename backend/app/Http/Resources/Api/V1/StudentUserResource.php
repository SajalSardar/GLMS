<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
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
            // The student's current class, via its profile — null when no
            // profile has been created yet, or the profile has no class set.
            'class' => $this->whenLoaded(
                'profile',
                fn () => $this->profile?->schoolClass ? new ClassResource($this->profile->schoolClass) : null,
            ),
        ];
    }
}
