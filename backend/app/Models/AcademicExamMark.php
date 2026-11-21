<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamMark extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'student_profile_id',
        'subject_id',
        'academic_exam_term_id',
        'academic_year_id',
        'school_id',
        'district_id',
        'class_id',
        'section_id',
        'shift_id',
        'class_version_id',
        'cq_marks_obtained',
        'mcq_marks_obtained',
        'practical_marks_obtained',
        'entered_by_id',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'entered_by_id');
    }
}
