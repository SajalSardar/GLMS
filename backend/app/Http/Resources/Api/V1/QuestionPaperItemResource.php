<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionPaperItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->item_type,
            'order_index' => $this->order_index,
            'heading_text' => $this->heading_text,
            'academic_question' => $this->whenLoaded('academicQuestion', fn () => $this->academicQuestion === null ? null : [
                'id' => $this->academicQuestion->id,
                'question_type' => $this->academicQuestion->question_type,
                'question_text' => $this->academicQuestion->question_text,
                'options' => $this->academicQuestion->options,
                'marks' => $this->academicQuestion->marks,
                'chapter_number' => $this->academicQuestion->chapter_number,
                'chapter_name' => $this->academicQuestion->chapter_name,
            ]),
        ];
    }
}
