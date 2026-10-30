<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'class_version_id',
        'school_id',
        'district_id',
        'academic_year_id',
        'name',
        'bn_name',
        'start_time',
        'end_time',
        'days_of_week',
        'period_duration_minutes',
        'breaks',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'days_of_week' => 'array',
            'breaks' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
