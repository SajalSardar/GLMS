<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentClassPromotion extends Model
{
    protected $fillable = [
        'student_profile_id',
        'academic_exam_term_id',
        'academic_year_id',
        'from_class_id',
        'from_section_id',
        'to_class_id',
        'to_section_id',
        'to_shift_id',
        'to_class_version_id',
        'result',
        'gpa',
        'promoted_by_id',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'gpa' => 'decimal:2',
            'promoted_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    public function fromSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'from_section_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    public function toSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'to_section_id');
    }

    public function toShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'to_shift_id');
    }

    public function toClassVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class, 'to_class_version_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_id');
    }
}
