<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'code',
        'capacity',
        'class_teacher_id',
        'default_classroom_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function defaultClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'default_classroom_id');
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function subjectTeacherAssignments(): HasMany
    {
        return $this->hasMany(SubjectTeacherAssignment::class);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    /**
     * Current enrolled student count, for capacity checks.
     */
    public function enrolledCount(): int
    {
        return $this->studentProfiles()->count();
    }
}
