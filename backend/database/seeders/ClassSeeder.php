<?php

namespace Database\Seeders;

use App\Models\ClassSchool;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Each class name is created once, nationally — then every school is
     * assigned to the grade levels its own `level` field implies, via
     * class_schools. The grade-band logic itself is unchanged from
     * before this class became national content.
     */
    public function run(): void
    {
        $classesByLevel = collect($this->grades())->mapWithKeys(
            fn (array $grade, int $numericLevel) => [$numericLevel => SchoolClass::updateOrCreate(
                ['name' => $grade['name']],
                ['bn_name' => $grade['bn_name'], 'numeric_level' => $numericLevel],
            )]
        );

        School::all()->each(function (School $school) use ($classesByLevel) {
            foreach ($this->gradesFor($school->level) as $numericLevel => $grade) {
                ClassSchool::firstOrCreate(
                    ['class_id' => $classesByLevel[$numericLevel]->id, 'school_id' => $school->id],
                    ['district_id' => $school->district_id],
                );
            }
        });
    }

    /**
     * @return array<int, array{name: string, bn_name: string}> numeric_level => grade
     */
    private function grades(): array
    {
        return [
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
    }

    /**
     * @return array<int, array{name: string, bn_name: string}> numeric_level => grade
     */
    private function gradesFor(string $schoolLevel): array
    {
        $levels = match ($schoolLevel) {
            'primary' => [1, 2, 3, 4, 5],
            'secondary' => [6, 7, 8, 9, 10],
            'higher_secondary' => [9, 10, 11, 12],
            'college' => [11, 12],
            default => [],
        };

        return array_intersect_key($this->grades(), array_flip($levels));
    }
}
