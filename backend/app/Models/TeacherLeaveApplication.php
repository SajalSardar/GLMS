<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherLeaveApplication extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'teacher_id',
        'school_id',
        'district_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'delegate_teacher_id',
        'admin_comment',
        'reviewed_by_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'delegate_teacher_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
