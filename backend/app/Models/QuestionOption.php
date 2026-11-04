<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    /**
     * In-memory defaults mirroring the migration's column defaults.
     */
    protected $attributes = [
        'is_correct' => false,
        'order_index' => 0,
    ];

    protected $fillable = [
        'question_id',
        'option_text',
        'bn_option_text',
        'is_correct',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
