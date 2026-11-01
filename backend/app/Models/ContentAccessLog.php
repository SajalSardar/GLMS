<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'book_id',
        'chapter_id',
        'chapter_material_id',
        'user_id',
        'action',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function chapterMaterial(): BelongsTo
    {
        return $this->belongsTo(ChapterMaterial::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
