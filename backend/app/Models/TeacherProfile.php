<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'school_id',
        'district_id',
        'employee_id',
        'designation',
        'subject_specialization',
        'qualification',
        'joining_date',
        'nid_number',
        'phone',
        'blood_group',
        'present_address',
        'permanent_address',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * Restrict a query to profiles within the given actor's scope: a
     * single school if school-scoped, a whole district if
     * district-scoped, or unrestricted if unscoped.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->school_id !== null) {
            return $query->where('school_id', $actor->school_id);
        }

        if ($actor->district_id !== null) {
            return $query->where('district_id', $actor->district_id);
        }

        return $query;
    }
}
