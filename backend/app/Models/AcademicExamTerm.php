<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamTerm extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'bn_name',
        'sort_order',
        'is_annual',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_annual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
