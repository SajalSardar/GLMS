<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistrictResource extends JsonResource
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
            'division_id' => $this->division_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'url' => $this->url,
            'division' => new DivisionResource($this->whenLoaded('division')),
            'upazilas' => UpazilaResource::collection($this->whenLoaded('upazilas')),
        ];
    }
}
