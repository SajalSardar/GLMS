<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamPublication extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'section_id',
        'academic_exam_term_id',
        'academic_year_id',
        'school_id',
        'district_id',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Whether (section, term, year) has already been published — the
     * single check every publish-state gate in this feature uses. Relied
     * on by MarksheetBuilder and the student marksheet gate in a later
     * phase; keep this exact method name and signature.
     */
    public static function isPublished(int $sectionId, int $termId, int $yearId): bool
    {
        return static::where('section_id', $sectionId)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $yearId)
            ->exists();
    }
}
