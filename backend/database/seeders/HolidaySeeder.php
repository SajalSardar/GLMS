<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The two weekly holidays (Friday/Saturday — Bangladesh's standard
     * school weekend, matching Shift.days_of_week's own Sunday–Thursday
     * convention) carry no academic_year_id, since they apply every
     * year. The government holidays below are deliberately limited to
     * Bangladesh's FIXED-date national holidays only (no lunar-calendar
     * ones like Eid, which shift every year and would need a real
     * calendar source to seed correctly) — seeded against the current
     * academic year so there's real demo data for the calendar UI.
     */
    public function run(): void
    {
        foreach (['friday', 'saturday'] as $day) {
            Holiday::updateOrCreate(
                ['type' => 'weekly', 'day_of_week' => $day],
                ['name' => ucfirst($day), 'bn_name' => null, 'is_active' => true],
            );
        }

        $academicYear = AcademicYear::where('is_current', true)->first();

        if ($academicYear === null) {
            return;
        }

        $year = $academicYear->name;

        $governmentHolidays = [
            ['date' => "{$year}-02-21", 'name' => 'International Mother Language Day', 'bn_name' => 'আন্তর্জাতিক মাতৃভাষা দিবস'],
            ['date' => "{$year}-03-26", 'name' => 'Independence Day', 'bn_name' => 'স্বাধীনতা দিবস'],
            ['date' => "{$year}-04-14", 'name' => 'Bengali New Year', 'bn_name' => 'পহেলা বৈশাখ'],
            ['date' => "{$year}-05-01", 'name' => 'May Day', 'bn_name' => 'মে দিবস'],
            ['date' => "{$year}-08-15", 'name' => 'National Mourning Day', 'bn_name' => 'জাতীয় শোক দিবস'],
            ['date' => "{$year}-12-16", 'name' => 'Victory Day', 'bn_name' => 'বিজয় দিবস'],
            ['date' => "{$year}-12-25", 'name' => 'Christmas Day', 'bn_name' => 'বড়দিন'],
        ];

        foreach ($governmentHolidays as $holiday) {
            Holiday::updateOrCreate(
                ['type' => 'government', 'start_date' => $holiday['date'], 'name' => $holiday['name']],
                [
                    'academic_year_id' => $academicYear->id,
                    'end_date' => $holiday['date'],
                    'bn_name' => $holiday['bn_name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
