<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->pivot->id,
            'order_index' => $this->pivot->order_index,
            // QuestionResource already nests QuestionOptionResource,
            // which already strips is_correct from any viewer without
            // questions.update — that protection applies here for free.
            'question' => new QuestionResource($this->resource),
        ];
    }
}
