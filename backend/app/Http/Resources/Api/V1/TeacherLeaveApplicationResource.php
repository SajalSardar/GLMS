<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherLeaveApplicationResource extends JsonResource
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
            'teacher_id' => $this->teacher_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'delegate_teacher_id' => $this->delegate_teacher_id,
            'admin_comment' => $this->admin_comment,
            'reviewed_by_id' => $this->reviewed_by_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'school_name' => $this->teacher->school?->name,
            ]),
            'delegate' => $this->whenLoaded('delegate', fn () => $this->delegate === null ? null : [
                'id' => $this->delegate->id,
                'name' => $this->delegate->name,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy === null ? null : [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            // Lets the frontend show the approve/reject/comment form only
            // where review() would actually allow it — the client has no
            // way to re-derive the permission check itself.
            'can_review' => $request->user()?->can('review', $this->resource) ?? false,
        ];
    }
}
