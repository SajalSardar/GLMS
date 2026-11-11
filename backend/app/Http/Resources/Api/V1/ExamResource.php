<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
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
            'class_version_id' => $this->class_version_id,
            'subject_id' => $this->subject_id,
            'academic_year_id' => $this->academic_year_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'selection_mode' => $this->selection_mode,
            'time_limit_minutes' => $this->time_limit_minutes,
            'max_attempts' => $this->max_attempts,
            'passing_score_percent' => $this->passing_score_percent,
            'available_from' => $this->available_from,
            'available_until' => $this->available_until,
            'status' => $this->status,
            'random_question_count' => $this->random_question_count,
            'random_difficulty' => $this->random_difficulty,
            'marks_per_question' => $this->marks_per_question,
            'total_marks' => $this->total_marks,
            'class_version' => new ClassVersionResource($this->whenLoaded('classVersion')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'questions' => ExamQuestionResource::collection($this->whenLoaded('questions')),
            'random_chapters' => ChapterResource::collection($this->whenLoaded('randomChapters')),
        ];
    }
}
