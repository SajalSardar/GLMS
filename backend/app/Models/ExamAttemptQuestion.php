<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamAttemptQuestion extends Model
{
    protected $attributes = [
        'order_index' => 0,
    ];

    protected $fillable = [
        'exam_attempt_id',
        'question_id',
        'order_index',
        'marks',
    ];

    public function examAttempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }
}
