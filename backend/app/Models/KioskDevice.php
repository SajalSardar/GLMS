<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KioskDevice extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'school_id',
        'name',
        'token',
        'is_active',
        'last_used_at',
    ];

    /**
     * The token is already never serialized anywhere today — this is
     * cheap defense-in-depth against a future raw ->toArray()/->toJson()
     * call on the model leaking the hash.
     */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
