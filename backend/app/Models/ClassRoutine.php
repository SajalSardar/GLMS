<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassRoutine extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    /**
     * Canonical weekly order (Bangladesh school week: Sunday–Thursday,
     * Friday/Saturday weekend), used for sorting and for validating
     * `day_of_week`.
     */
    public const DAYS_OF_WEEK = [
        'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
    ];

    protected $fillable = [
        'school_id',
        'district_id',
        'class_id',
        'section_id',
        'shift_id',
        'class_version_id',
        'subject_id',
        'teacher_id',
        'classroom_id',
        'academic_year_id',
        'day_of_week',
        'period_no',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Optional — only set for schools that have adopted the Version/Shift/
     * Section structure (`section_id` is nullable), same as
     * StudentProfile::section().
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Denormalized from section_id — see the migration that added these
     * two columns for why.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
