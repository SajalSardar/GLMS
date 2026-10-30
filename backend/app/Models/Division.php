<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Division extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'bn_name',
        'url',
    ];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
