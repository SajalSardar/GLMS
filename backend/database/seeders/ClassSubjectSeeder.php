<?php

namespace Database\Seeders;

use App\Models\ClassSchool;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClassSubjectSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Configures a standard subject set per grade band for every
     * school's offering of every class seeded by ClassSeeder. This is a
     * simplified, single curriculum track — it does not model
     * science/commerce/arts streams for HSC.
     */
    public function run(): void
    {
        ClassSchool::with('schoolClass')->get()->each(function (ClassSchool $assignment) {
            $subjectIds = Subject::whereIn('name', $this->subjectsFor($assignment->schoolClass->numeric_level))->pluck('id');

            DB::table('class_subjects')->where('class_id', $assignment->class_id)->where('school_id', $assignment->school_id)->delete();

            $rows = $subjectIds->map(fn (int $subjectId) => [
                'class_id' => $assignment->class_id,
                'subject_id' => $subjectId,
                'school_id' => $assignment->school_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($rows !== []) {
                DB::table('class_subjects')->insert($rows);
            }
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
