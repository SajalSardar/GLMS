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
            'indent_level' => $this->indent_level,
            // A 'question' item is now a fully self-contained copy —
            // question_type/question_text/options/marks were copied in
            // from the bank question at insert time and may since have
            // been edited for this paper only; there is no longer a
            // academic_questions row backing it to read from.
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'options' => $this->options,
            'marks' => $this->marks,
            'serial_label' => $this->serial_label,
            'text_content' => $this->text_content,
        ];
    }
}
