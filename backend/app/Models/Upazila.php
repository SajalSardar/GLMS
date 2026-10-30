<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Upazila extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'district_id',
        'name',
        'bn_name',
        'url',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
