<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use SoftDeletes, TenantScoped;

    protected $fillable = [
        'class_version_id',
        'subject_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'order_index',
        'description',
        'is_active',
        'pdf_original_filename',
        'pdf_stored_path',
        'pdf_disk',
        'pdf_mime_type',
        'pdf_size_bytes',
        'pdf_uploaded_by_id',
        'pdf_uploaded_at',
        'pdf_status',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pdf_uploaded_at' => 'datetime',
        ];
    }

    public function hasAvailablePdf(): bool
    {
        return $this->pdf_stored_path !== null && $this->pdf_status === 'active';
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order_index');
    }
}
