<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClassSchool extends Model
{
    use Auditable, TenantScoped;

    protected $fillable = [
        'class_id',
        'school_id',
        'district_id',
    ];

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

    /**
     * NOTE: the brief's version of this relation omitted the $parentKey
     * argument, which left Eloquent joining on class_subjects.class_id =
     * class_schools.id (this row's own primary key) instead of
     * class_schools.class_id (the school_classes row it points at) —
     * those only coincided by luck in small fixtures. Passing 'class_id'
     * as the fifth belongsToMany() argument makes it join on
     * $this->class_id, which is what "this school's offering of this
     * class" actually means.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id', 'class_id')
            ->wherePivot('school_id', $this->school_id);
    }
}
