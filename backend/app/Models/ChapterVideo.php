<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterVideo extends Model
{
    use Auditable, SoftDeletes;

    /**
     * In-memory defaults mirroring the migration's column defaults, so a
     * freshly-created instance reflects them without an extra DB round trip.
     */
    protected $attributes = [
        'is_enabled' => true,
        'order_index' => 0,
    ];

    protected $fillable = [
        'chapter_id',
        'youtube_video_id',
        'title',
        'bn_title',
        'description',
        'instructor_name',
        'duration_seconds',
        'learning_objectives',
        'completion_threshold_percent',
        'order_index',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
