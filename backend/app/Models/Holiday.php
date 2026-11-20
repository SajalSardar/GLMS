<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use Auditable, SoftDeletes;

    /**
     * 'weekly': a recurring day-of-week holiday (day_of_week set, dates
     * null). 'government'/'other': a specific dated holiday (dates set,
     * day_of_week null) — see the create migration's own comment.
     */
    public const TYPES = ['weekly', 'government', 'other'];

    /**
     * Same canonical weekly order as ClassRoutine::DAYS_OF_WEEK —
     * day_of_week here is validated against this same list.
     */
    public const DAYS_OF_WEEK = [
        'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
    ];

    protected $fillable = [
        'academic_year_id',
        'type',
        'day_of_week',
        'start_date',
        'end_date',
        'name',
        'bn_name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * True if $date falls on this row's recurring weekday (type=weekly)
     * or within its start_date..end_date range (type=government/other).
     * Inactive rows never match.
     */
    public function coversDate(\DateTimeInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->type === 'weekly') {
            return $this->day_of_week === strtolower($date->format('l'));
        }

        if ($this->start_date === null || $this->end_date === null) {
            return false;
        }

        return $this->start_date->toDateString() <= $date->format('Y-m-d')
            && $this->end_date->toDateString() >= $date->format('Y-m-d');
    }

    /**
     * Whether $date is a holiday under ANY active row — the single
     * lookup a future attendance feature needs (per this feature's own
     * stated purpose), covering both weekly and dated holidays at once.
     *
     * @param  Builder<Holiday>|null  $query  Defaults to all active rows;
     *                                        pass a pre-scoped query (e.g.
     *                                        filtered to one academic_year_id)
     *                                        to narrow it.
     */
    public static function isHoliday(\DateTimeInterface $date, ?Builder $query = null): bool
    {
        $query ??= static::query();

        return $query->where('is_active', true)->get()->contains(fn (Holiday $holiday) => $holiday->coversDate($date));
    }
}
