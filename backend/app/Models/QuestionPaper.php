<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuestionPaper extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'teacher_id',
        'school_id',
        'district_id',
        'title',
        'school_class_id',
        'subject_id',
        'time_duration_minutes',
        'full_marks',
        'instructions',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuestionPaperItem::class)->orderBy('order_index');
    }
}
