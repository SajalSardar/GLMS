<?php

namespace Tests\Unit;

use App\Models\Shift;
use App\Support\TimetablePeriodGrid;
use Tests\TestCase;

class TimetablePeriodGridTest extends TestCase
{
    private TimetablePeriodGrid $grid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grid = new TimetablePeriodGrid;
    }

    public function test_builds_sequential_periods_with_no_breaks(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $slots = $this->grid->build($shift);

        $this->assertCount(6, $slots);
        $this->assertSame(['day_of_week' => 'saturday', 'period_no' => 1, 'start_time' => '08:00', 'end_time' => '08:50'], $slots[0]);
        $this->assertSame(['day_of_week' => 'saturday', 'period_no' => 6, 'start_time' => '12:10', 'end_time' => '13:00'], $slots[5]);
    }

    public function test_period_numbers_continue_sequentially_across_a_break(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
            'breaks' => [['start_time' => '10:00', 'end_time' => '10:20', 'label' => 'Tiffin']],
        ]);

        $slots = $this->grid->build($shift);

        // [08:00,10:00) -> 2 full periods, 20min remainder dropped.
        // [10:20,13:00) -> 3 full periods, 10min remainder dropped.
        $this->assertCount(5, $slots);
        $this->assertSame([1, 2, 3, 4, 5], array_column($slots, 'period_no'));
        $this->assertSame('08:00', $slots[0]['start_time']);
        $this->assertSame('09:40', $slots[1]['end_time']);
        $this->assertSame('10:20', $slots[2]['start_time']);
        $this->assertSame('12:50', $slots[4]['end_time']);
    }

    public function test_trailing_remainder_shorter_than_one_period_is_dropped(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '08:49',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }

    public function test_every_day_gets_its_own_full_grid(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '09:00',
            'days_of_week' => ['saturday', 'sunday'],
            'period_duration_minutes' => 30,
        ]);

        $slots = $this->grid->build($shift);

        $this->assertCount(4, $slots);
        $this->assertSame(['saturday', 'saturday', 'sunday', 'sunday'], array_column($slots, 'day_of_week'));
        $this->assertSame([1, 2, 1, 2], array_column($slots, 'period_no'));
    }

    public function test_returns_empty_when_period_duration_is_not_configured(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }

    public function test_returns_empty_when_no_days_are_configured(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => [],
            'period_duration_minutes' => 50,
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }
}
