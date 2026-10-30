<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * One class per grade level for every school seeded by SchoolSeeder,
     * ranged by the school's `level` field.
     */
    public function run(): void
    {
        School::all()->each(function (School $school) {
            foreach ($this->gradesFor($school->level) as $numericLevel => $grade) {
                SchoolClass::updateOrCreate(
                    ['school_id' => $school->id, 'name' => $grade['name'], 'section' => null],
                    ['district_id' => $school->district_id, 'bn_name' => $grade['bn_name'], 'numeric_level' => $numericLevel],
                );
            }
        });
    }

    /**
     * @return array<int, array{name: string, bn_name: string}> numeric_level => grade
     */
    private function gradesFor(string $schoolLevel): array
    {
        $grades = [
            1 => ['name' => 'Class 1', 'bn_name' => 'প্রথম শ্রেণি'],
            2 => ['name' => 'Class 2', 'bn_name' => 'দ্বিতীয় শ্রেণি'],
            3 => ['name' => 'Class 3', 'bn_name' => 'তৃতীয় শ্রেণি'],
            4 => ['name' => 'Class 4', 'bn_name' => 'চতুর্থ শ্রেণি'],
            5 => ['name' => 'Class 5', 'bn_name' => 'পঞ্চম শ্রেণি'],
            6 => ['name' => 'Class 6', 'bn_name' => 'ষষ্ঠ শ্রেণি'],
            7 => ['name' => 'Class 7', 'bn_name' => 'সপ্তম শ্রেণি'],
            8 => ['name' => 'Class 8', 'bn_name' => 'অষ্টম শ্রেণি'],
            9 => ['name' => 'Class 9', 'bn_name' => 'নবম শ্রেণি'],
            10 => ['name' => 'Class 10', 'bn_name' => 'দশম শ্রেণি'],
            11 => ['name' => 'HSC 1st Year', 'bn_name' => 'একাদশ শ্রেণি'],
            12 => ['name' => 'HSC 2nd Year', 'bn_name' => 'দ্বাদশ শ্রেণি'],
        ];

        $levels = match ($schoolLevel) {
            'primary' => [1, 2, 3, 4, 5],
            'secondary' => [6, 7, 8, 9, 10],
            'higher_secondary' => [9, 10, 11, 12],
            'college' => [11, 12],
            default => [],
        };

        return array_intersect_key($grades, array_flip($levels));
    }
}
