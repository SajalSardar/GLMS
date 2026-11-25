<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'chapter_number' => $this->chapter_number,
            'chapter_name' => $this->chapter_name,
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'options' => $this->options,
            'marks' => $this->marks,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
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
        ];
    }
}
