<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionPaperItem extends Model
{
    public const TYPES = ['question', 'heading'];

    protected $fillable = [
        'question_paper_id',
        'item_type',
        'academic_question_id',
        'heading_text',
        'order_index',
    ];

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    public function academicQuestion(): BelongsTo
    {
        return $this->belongsTo(AcademicQuestion::class)->withTrashed();
    }
}
