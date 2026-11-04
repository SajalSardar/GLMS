<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'bn_option_text' => $this->bn_option_text,
            'order_index' => $this->order_index,
            // A student fetching exam questions must never see which
            // option is flagged correct through this same resource class.
            'is_correct' => $this->when(
                $request->user()?->hasPermission('questions.update') ?? false,
                $this->is_correct
            ),
        ];
    }
}
