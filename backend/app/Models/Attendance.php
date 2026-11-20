<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    public const STATUSES = ['present', 'absent', 'leave'];

    protected $fillable = [
        'student_profile_id',
        'school_id',
        'district_id',
        'class_id',
        'section_id',
        'shift_id',
        'class_version_id',
        'academic_year_id',
        'date',
        'status',
        'marked_by_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
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
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'marked_by_id');
    }
}
