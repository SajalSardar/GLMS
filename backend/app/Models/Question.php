<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    /**
     * In-memory defaults mirroring the migration's column defaults, so a
     * freshly-created instance reflects them without an extra DB round
     * trip.
     */
    protected $attributes = [
        'marks' => 1,
        'difficulty' => 'medium',
        'order_index' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'chapter_id',
        'school_id',
        'district_id',
        'question_type',
        'question_text',
        'bn_question_text',
        'image_path',
        'image_disk',
        'marks',
        'difficulty',
        'order_index',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order_index');
    }
}
