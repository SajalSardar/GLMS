<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentProfile extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'user_id',
        'school_id',
        'district_id',
        'class_id',
        'section_id',
        'student_id',
        'roll_number',
        'admission_date',
        'date_of_birth',
        'gender',
        'blood_group',
        'present_address',
        'permanent_address',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'date_of_birth' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(StudentUser::class);
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

    /**
     * Optional — only set for schools that have adopted the
     * Version/Shift/Section structure (`section_id` is nullable).
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function sectionTransfers(): HasMany
    {
        return $this->hasMany(StudentSectionTransfer::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }
}
