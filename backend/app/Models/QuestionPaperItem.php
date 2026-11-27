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
        'academic_question_id',
        'marks_override',
        'serial_label',
        'text_content',
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
