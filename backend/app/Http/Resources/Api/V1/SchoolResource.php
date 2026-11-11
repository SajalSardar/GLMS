<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SchoolResource extends JsonResource
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
            'division_id' => $this->division_id,
            'district_id' => $this->district_id,
            'upazila_id' => $this->upazila_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'eiin' => $this->eiin,
            'type' => $this->type,
            'level' => $this->level,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'established_year' => $this->established_year,
            'logo_url' => $this->logo_path ? Storage::disk($this->logo_disk)->url($this->logo_path) : null,
            'division' => new DivisionResource($this->whenLoaded('division')),
            'district' => new DistrictResource($this->whenLoaded('district')),
            'upazila' => new UpazilaResource($this->whenLoaded('upazila')),
        ];
    }
}
