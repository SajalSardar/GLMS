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
        'shift_id',
        'class_version_id',
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

    /**
     * The next "STU-0001"-style id — student_id is the student's login
     * credential (see AuthController::studentLogin(), which resolves
     * purely by this column), so it's always server-generated rather
     * than typed by an admin (see StudentProfileController::store(),
     * which is the only caller — StoreStudentProfileRequest rejects a
     * client-supplied student_id outright). withTrashed() so a number
     * once used is never reissued, even if that row was later
     * soft-deleted — student_id's DB-level unique index has no trashed
     * exclusion, so reusing one would fail the insert outright.
     */
    public static function nextStudentId(): string
    {
        $maxNumber = static::withTrashed()
            ->where('student_id', 'like', 'STU-%')
            ->get(['student_id'])
            ->map(fn (self $profile) => (int) substr($profile->student_id, 4))
            ->max() ?? 0;

        return 'STU-'.str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);
    }
}
