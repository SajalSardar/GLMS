<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chapter extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'book_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'order_index',
        'chapter_number',
        'bn_chapter_number',
        'description',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ChapterMaterial::class)->orderBy('order_index');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(ChapterVideo::class)->orderBy('order_index');
    }
}
