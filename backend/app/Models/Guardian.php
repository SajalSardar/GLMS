<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'bn_name',
        'phone',
        'email',
        'nid_number',
        'occupation',
        'present_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(GuardianUser::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'student_guardian')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Restrict a query to guardians the given actor may see: a
     * parent/guardian account only sees guardians who share a child with
     * them; a school/district-scoped staff account sees guardians linked
     * to a student in their scope (plus not-yet-linked guardians, so a
     * freshly created guardian can still be attached); an unscoped actor
     * (e.g. national administrator) is unrestricted.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        $ownGuardian = $actor->guardian;

        if ($ownGuardian !== null) {
            $sharedStudentIds = $ownGuardian->students()->pluck('student_profiles.id');

            return $query->whereHas('students', fn (Builder $q) => $q->whereIn('student_profiles.id', $sharedStudentIds));
        }

        if ($actor->school_id !== null) {
            return $query->where(fn (Builder $q) => $q->doesntHave('students')
                ->orWhereHas('students', fn (Builder $sq) => $sq->where('school_id', $actor->school_id)));
        }

        if ($actor->district_id !== null) {
            return $query->where(fn (Builder $q) => $q->doesntHave('students')
                ->orWhereHas('students', fn (Builder $sq) => $sq->where('district_id', $actor->district_id)));
        }

        return $query;
    }

    /**
     * Instance-level equivalent of scopeVisibleTo(), for Policy checks on
     * a single already-loaded guardian.
     */
    public function isVisibleTo(User $actor): bool
    {
        $ownGuardian = $actor->guardian;

        if ($ownGuardian !== null) {
            $sharedStudentIds = $ownGuardian->students()->pluck('student_profiles.id');

            return $this->students()->whereIn('student_profiles.id', $sharedStudentIds)->exists();
        }

        if ($actor->school_id !== null) {
            return ! $this->students()->exists() || $this->students()->where('school_id', $actor->school_id)->exists();
        }

        if ($actor->district_id !== null) {
            return ! $this->students()->exists() || $this->students()->where('district_id', $actor->district_id)->exists();
        }

        return true;
    }
}
