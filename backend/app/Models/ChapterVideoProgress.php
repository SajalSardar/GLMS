<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChapterVideoProgress extends Model
{
    protected $fillable = [
        'chapter_video_id',
        'user_type',
        'user_id',
        'status',
        'max_position_seconds',
        'started_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'not_started',
        'max_position_seconds' => 0,
    ];

    protected function casts(): array
    {
        return [
            'max_position_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function chapterVideo(): BelongsTo
    {
        return $this->belongsTo(ChapterVideo::class);
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }
}
