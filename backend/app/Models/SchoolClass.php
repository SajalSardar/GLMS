<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $table = 'school_classes';

    protected $fillable = [
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'numeric_level',
        'section',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id');
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class, 'class_id');
    }

    public function routines(): HasMany
    {
        return $this->hasMany(ClassRoutine::class, 'class_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClassVersion::class, 'class_id');
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'class_schools', 'class_id', 'school_id');
    }
}
