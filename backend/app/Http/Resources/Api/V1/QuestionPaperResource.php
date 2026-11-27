<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionPaperResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'title' => $this->title,
            'school_class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'time_duration_minutes' => $this->time_duration_minutes,
            'full_marks' => $this->full_marks,
            'instructions' => $this->instructions,
            'created_at' => $this->created_at?->toIso8601String(),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
            'school' => $this->whenLoaded('school', fn () => $this->school === null ? null : [
                'id' => $this->school->id,
                'name' => $this->school->name,
                'bn_name' => $this->school->bn_name,
                'address' => $this->school->address,
            ]),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass === null ? null : [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'bn_name' => $this->schoolClass->bn_name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'bn_name' => $this->subject->bn_name,
            ]),
            'items' => QuestionPaperItemResource::collection($this->whenLoaded('items')),
            // Lets the frontend show Edit/Delete only where update()/
            // delete() would actually allow it — the client has no way to
            // re-derive the creator-only ownership check itself.
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
