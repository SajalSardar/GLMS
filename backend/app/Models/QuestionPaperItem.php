<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionPaperItem extends Model
{
    public const TYPES = ['question', 'heading', 'text'];

    protected $fillable = [
        'question_paper_id',
        'item_type',
        'question_type',
        'question_text',
        'options',
        'marks',
        'serial_label',
        'indent_level',
        'text_content',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }
}
