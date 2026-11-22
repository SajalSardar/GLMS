<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterMaterial extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'chapter_id',
        'school_id',
        'district_id',
        'shift_id',
        'class_version_id',
        'class_id',
        'section_id',
        'type',
        'title',
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'uploaded_by_id',
        'order_index',
        'status',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * These five, plus section() below, are set only when the uploading
     * actor is a TeacherUser who supplied an authorized section_id —
     * verified against a matching SubjectTeacherAssignment for that
     * section/subject/teacher (see
     * ChapterMaterialController::resolveUploaderScope() and
     * ChapterMaterialPolicy::create()) — null for every material
     * uploaded before this existed, and for one uploaded by a staff
     * actor (school_admin/national_admin), which means "visible to the
     * whole chapter" rather than one section.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
