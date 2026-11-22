<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Bangladesh schools run a calendar-year academic session (Jan–Dec).
     * academic_years is now national calendar data — the same years apply
     * to every school in Bangladesh. Seeds 2026 through 2050 nationally so
     * future years already exist by the time they're needed, marking the
     * year containing today's date as current. The API only lists years up
     * to the current one, so the not-yet-reached years stay hidden until
     * their turn comes.
     */
    public function run(): void
    {
        $currentYear = (int) now()->format('Y');

        foreach (range(2026, 2050) as $year) {
            AcademicYear::updateOrCreate(
                ['name' => (string) $year],
                [
                    'start_date' => "{$year}-01-01",
                    'end_date' => "{$year}-12-31",
                    'is_current' => $year === $currentYear,
                ],
            );
        }
    }
}
