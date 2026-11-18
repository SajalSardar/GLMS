<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'school_classes';

    protected $fillable = [
        'name',
        'bn_name',
        'numeric_level',
    ];

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
