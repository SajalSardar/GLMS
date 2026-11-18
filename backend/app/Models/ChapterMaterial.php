<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChapterMaterial extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'chapter_id',
        'type',
        'title',
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'uploaded_by_id',
        'order_index',
        'status',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
