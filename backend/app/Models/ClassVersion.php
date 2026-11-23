<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassVersion extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'class_id',
        'school_id',
        'district_id',
        'version_id',
        'is_active',
    ];

    /**
     * Every query eager-loads the linked national Version — the table is
     * two rows, so the join cost is negligible, and this is what keeps
     * name()/bnName() below from N+1ing across the many existing call
     * sites that read ->name/->bn_name without having explicitly
     * eager-loaded anything.
     */
    protected $with = ['version'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'version_subjects')
            ->withPivot('required_weekly_periods')
            ->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Derived from the linked Version, not a real column — keeps every
     * existing consumer that reads ->name working unchanged now that the
     * version's display name lives in the national versions catalog
     * instead of on this row. Null-safe: an unsaved/incomplete model with
     * no version_id has no linked Version yet.
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => $this->version?->name);
    }

    protected function bnName(): Attribute
    {
        return Attribute::make(get: fn () => $this->version?->bn_name);
    }
}
