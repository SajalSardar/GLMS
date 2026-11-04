<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_id' => $this->chapter_id,
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'bn_question_text' => $this->bn_question_text,
            'image_url' => $this->image_path ? Storage::disk($this->image_disk)->url($this->image_path) : null,
            'marks' => $this->marks,
            'difficulty' => $this->difficulty,
            'order_index' => $this->order_index,
            'is_active' => $this->is_active,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            'chapter' => new ChapterResource($this->whenLoaded('chapter')),
        ];
    }
}
