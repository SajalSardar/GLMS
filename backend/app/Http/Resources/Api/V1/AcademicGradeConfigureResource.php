<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicGradeConfigureResource extends JsonResource
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
            'min_percent' => (string) $this->min_percent,
            'max_percent' => (string) $this->max_percent,
            'grade_name' => $this->grade_name,
            'bn_grade_name' => $this->bn_grade_name,
            'grade_point' => (string) $this->grade_point,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
