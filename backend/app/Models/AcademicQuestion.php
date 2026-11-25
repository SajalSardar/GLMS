<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicQuestion extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = ['cq', 'mcq'];

    protected $fillable = [
        'school_class_id',
        'subject_id',
        'version_id',
        'chapter_id',
        'question_type',
        'question_text',
        'options',
        'marks',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
