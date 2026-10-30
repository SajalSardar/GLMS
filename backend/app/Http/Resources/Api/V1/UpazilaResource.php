<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpazilaResource extends JsonResource
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
            'district_id' => $this->district_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'url' => $this->url,
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
