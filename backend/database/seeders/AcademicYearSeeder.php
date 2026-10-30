<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Bangladesh schools run a calendar-year academic session (Jan–Dec).
     * Seeds the previous, current, and next year for every school, marking
     * the year containing today's date as current.
     */
    public function run(): void
    {
        $currentYear = (int) now()->format('Y');

        School::all()->each(function (School $school) use ($currentYear) {
            foreach ([$currentYear - 1, $currentYear, $currentYear + 1] as $year) {
                AcademicYear::updateOrCreate(
                    ['school_id' => $school->id, 'name' => (string) $year],
                    [
                        'district_id' => $school->district_id,
                        'start_date' => "{$year}-01-01",
                        'end_date' => "{$year}-12-31",
                        'is_current' => $year === $currentYear,
                    ],
                );
            }
        });
    }
}
