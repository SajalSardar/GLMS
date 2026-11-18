<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'division_id',
        'district_id',
        'upazila_id',
        'name',
        'bn_name',
        'eiin',
        'type',
        'level',
        'address',
        'phone',
        'email',
        'established_year',
        'logo_path',
        'logo_disk',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_schools', 'school_id', 'class_id');
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
