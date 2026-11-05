<?php

namespace App\Support;

use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Derives the weekly period grid a Shift implies from its configured
 * start/end time, period duration, and breaks. There is no stored
 * period-to-time mapping anywhere else in this codebase — a manual
 * TimetableEntry row carries its own start_time/end_time/period_no
 * supplied directly by the caller; TimetableGenerator needs this grid to
 * know what candidate slots even exist.
 */
class TimetablePeriodGrid
{
    /**
     * Mirrors the `period_no` ceiling already validated by
     * StoreTimetableEntryRequest and UpdateTimetableEntryRequest
     * (`['required', 'integer', 'min:1', 'max:20']`) — the one sane
     * per-day period ceiling established elsewhere in this codebase.
     * Capping the generated grid to the same value keeps generated
     * entries editable through the manual entry endpoints, which would
     * otherwise reject any period_no beyond 20.
     */
    private const MAX_PERIODS_PER_DAY = 20;

    /**
     * @return list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>
     */
    public function build(Shift $shift): array
    {
        if ($shift->period_duration_minutes === null || $shift->period_duration_minutes <= 0 || empty($shift->days_of_week)) {
            return [];
        }

        $freeWindows = $this->freeWindows($shift);
        $slots = [];

        foreach ($shift->days_of_week as $day) {
            $periodNo = 1;

            foreach ($freeWindows as [$windowStart, $windowEnd]) {
                $cursor = $windowStart->copy();

                while ($periodNo <= self::MAX_PERIODS_PER_DAY && $cursor->copy()->addMinutes($shift->period_duration_minutes)->lessThanOrEqualTo($windowEnd)) {
                    $periodEnd = $cursor->copy()->addMinutes($shift->period_duration_minutes);

                    $slots[] = [
                        'day_of_week' => $day,
                        'period_no' => $periodNo,
                        'start_time' => $cursor->format('H:i'),
                        'end_time' => $periodEnd->format('H:i'),
                    ];

                    $periodNo++;
                    $cursor = $periodEnd;
                }

                if ($periodNo > self::MAX_PERIODS_PER_DAY) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * The shift's [start_time, end_time) interval with every configured
     * break interval subtracted out, as a time-ordered list of
     * [start, end] Carbon pairs.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function freeWindows(Shift $shift): array
    {
        $shiftStart = Carbon::parse($shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($shift->end_time->format('H:i'));

        $breaks = collect($shift->breaks ?? [])
            ->map(fn (array $break): array => [Carbon::parse($break['start_time']), Carbon::parse($break['end_time'])])
            ->sortBy(fn (array $break) => $break[0]->format('H:i'))
            ->values()
            ->all();

        $windows = [];
        $cursor = $shiftStart;

        foreach ($breaks as [$breakStart, $breakEnd]) {
            if ($breakStart->greaterThan($cursor)) {
                $windows[] = [$cursor, $breakStart->lessThan($shiftEnd) ? $breakStart : $shiftEnd];
            }

            if ($breakEnd->greaterThan($cursor)) {
                $cursor = $breakEnd;
            }
        }

        if ($cursor->lessThan($shiftEnd)) {
            $windows[] = [$cursor, $shiftEnd];
        }

        return $windows;
    }
}
