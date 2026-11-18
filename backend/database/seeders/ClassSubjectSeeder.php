<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class ClassSubjectSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Configures a standard subject set per grade band for every class
     * seeded by ClassSeeder. This is a simplified, single curriculum
     * track — it does not model science/commerce/arts streams for HSC.
     */
    public function run(): void
    {
        SchoolClass::all()->each(function (SchoolClass $class) {
            $subjectIds = Subject::whereIn('name', $this->subjectsFor($class->numeric_level))->pluck('id');

            // class_subjects.school_id is now NOT NULL (see
            // 2026_11_19_100100_add_school_id_to_class_subjects_table.php)
            // — this class's own still-present school_id column (not
            // cut over until Task 3) is the only per-school context
            // available here, so each row is attributed to it.
            $class->subjects()->sync($subjectIds->mapWithKeys(fn (int $subjectId) => [
                $subjectId => ['school_id' => $class->school_id],
            ]));
        });
    }

    /**
     * @return array<int, string>
     */
    private function subjectsFor(?int $numericLevel): array
    {
        return match (true) {
            $numericLevel === null => [],
            $numericLevel <= 5 => ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh and Global Studies', 'Religious Studies'],
            $numericLevel <= 10 => ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh and Global Studies', 'Information and Communication Technology', 'Religious Studies'],
            default => ['Bangla', 'English', 'Information and Communication Technology', 'Physics', 'Chemistry', 'Biology', 'Higher Mathematics'],
        };
    }
}
