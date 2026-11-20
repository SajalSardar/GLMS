<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicSubjectMarkConfigure extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'class_id',
        'subject_id',
        'cq_full_marks',
        'mcq_full_marks',
        'practical_full_marks',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Sum of whichever components are configured (non-null) — the
     * denominator every percentage calculation in this feature uses.
     * Relied on by MarksheetBuilder (added in a later phase); keep this
     * exact method name.
     */
    public function totalFullMarks(): int
    {
        return ($this->cq_full_marks ?? 0) + ($this->mcq_full_marks ?? 0) + ($this->practical_full_marks ?? 0);
    }
}
