<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimetableEntry extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    public const STATUSES = ['draft', 'published'];

    protected $fillable = [
        'section_id',
        'subject_id',
        'teacher_id',
        'classroom_id',
        'academic_year_id',
        'school_id',
        'district_id',
        'day_of_week',
        'period_no',
        'start_time',
        'end_time',
        'status',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'published_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Create a new entry at (section_id, day_of_week, period_no), or — if
     * a soft-deleted row already occupies that slot (the DB unique index
     * on these three columns does not exclude trashed rows, so a plain
     * insert would violate it) — restore and overwrite that row instead.
     * Always lands as a fresh draft: status/published_at/published_by in
     * $attributes (if present) are ignored and forced to
     * 'draft'/null/null.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createOrRestore(array $attributes): self
    {
        $draftAttributes = [...$attributes, 'status' => 'draft', 'published_at' => null, 'published_by' => null];

        $entry = static::onlyTrashed()
            ->where('section_id', $attributes['section_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->first();

        if ($entry) {
            $entry->restore();
            $entry->update($draftAttributes);

            return $entry;
        }

        return static::create($draftAttributes);
    }
}
