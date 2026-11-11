<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
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
            'user_id' => $this->user_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'nid_number' => $this->nid_number,
            'occupation' => $this->occupation,
            'present_address' => $this->present_address,
            'relation' => $this->whenPivotLoaded('student_guardian', fn () => $this->pivot->relation),
            'is_primary' => $this->whenPivotLoaded('student_guardian', fn () => (bool) $this->pivot->is_primary),
        ];
    }
}
