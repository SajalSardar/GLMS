<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicGradeConfigure extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'min_percent',
        'max_percent',
        'grade_name',
        'bn_grade_name',
        'grade_point',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_percent' => 'decimal:2',
            'max_percent' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The grade whose [min_percent, max_percent] range contains $percent,
     * among active rows — the single lookup every grade/GPA computation
     * in the Academic Exam Results feature uses (MarksheetBuilder,
     * promotion eligibility display, both added in later phases).
     */
    public static function forPercent(float $percent): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('min_percent', '<=', $percent)
            ->where('max_percent', '>=', $percent)
            ->orderByDesc('min_percent')
            ->first();
    }
}
