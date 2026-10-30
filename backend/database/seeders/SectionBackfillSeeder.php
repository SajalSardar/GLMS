<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassRoutine;
use App\Models\ClassVersion;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use Illuminate\Database\Seeder;

class SectionBackfillSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Forward-migrates every existing SchoolClass into the new
     * Version/Shift/Section structure introduced by
     * docs/Multi-Version-Multi-Shift-Multi-Section-Smart Timetable
     * Management.md, WITHOUT touching the old school_classes.section
     * string, class_subjects, or class_routines data — those keep working
     * exactly as before. For each class this creates one default
     * ClassVersion ("General"), one default Shift ("Day Shift"), and one
     * Section named after the class's existing flat section value (or
     * "Main" if it had none), then carries its configured subjects,
     * subject-teacher pairings, and routine entries into the new tables,
     * and points its enrolled students' student_profiles.section_id at it.
     */
    public function run(): void
    {
        SchoolClass::with(['subjects', 'routines', 'studentProfiles'])->get()->each(function (SchoolClass $class) {
            $academicYearId = AcademicYear::where('school_id', $class->school_id)->where('is_current', true)->value('id');

            $version = ClassVersion::firstOrCreate(
                ['class_id' => $class->id, 'name' => 'General'],
                ['school_id' => $class->school_id, 'district_id' => $class->district_id, 'is_active' => true],
            );

            // Derived from the days the old class_routines actually uses,
            // falling back to the standard Bangladesh school week (Sunday
            // through Thursday) when there's no existing routine data.
            $daysOfWeek = $class->routines->pluck('day_of_week')->unique()->values()->all();

            $shift = Shift::firstOrCreate(
                ['class_version_id' => $version->id, 'name' => 'Day Shift'],
                [
                    'school_id' => $class->school_id,
                    'district_id' => $class->district_id,
                    'academic_year_id' => $academicYearId,
                    'start_time' => '08:00',
                    'end_time' => '13:00',
                    'days_of_week' => $daysOfWeek ?: ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
                    'is_active' => true,
                ],
            );

            $section = Section::firstOrCreate(
                ['shift_id' => $shift->id, 'name' => $class->section ?? 'Main'],
                ['school_id' => $class->school_id, 'district_id' => $class->district_id, 'is_active' => true],
            );

            // Weekly period count per subject, derived from how many
            // distinct (day, period) slots the old class_routines already
            // uses — left null (unconfigured) when there's no existing data.
            $weeklyCounts = $class->routines->groupBy('subject_id')->map->count();

            $subjectSync = $class->subjects->mapWithKeys(fn ($subject) => [
                $subject->id => ['required_weekly_periods' => $weeklyCounts->get($subject->id)],
            ]);
            $version->subjects()->syncWithoutDetaching($subjectSync->all());

            // subject_teacher_assignments.teacher_id is required, so only
            // routine rows that already have a teacher carry forward.
            $class->routines->whereNotNull('teacher_id')
                ->unique(fn (ClassRoutine $routine) => "{$routine->subject_id}:{$routine->teacher_id}")
                ->each(function (ClassRoutine $routine) use ($section) {
                    SubjectTeacherAssignment::firstOrCreate(
                        ['section_id' => $section->id, 'subject_id' => $routine->subject_id],
                        ['teacher_id' => $routine->teacher_id, 'school_id' => $section->school_id, 'district_id' => $section->district_id],
                    );
                });

            $class->routines->whereNotNull('teacher_id')->each(function (ClassRoutine $routine) use ($section, $academicYearId) {
                TimetableEntry::firstOrCreate(
                    ['section_id' => $section->id, 'day_of_week' => $routine->day_of_week, 'period_no' => $routine->period_no],
                    [
                        'subject_id' => $routine->subject_id,
                        'teacher_id' => $routine->teacher_id,
                        'classroom_id' => $routine->classroom_id,
                        'academic_year_id' => $academicYearId,
                        'school_id' => $section->school_id,
                        'district_id' => $section->district_id,
                        'start_time' => $routine->start_time,
                        'end_time' => $routine->end_time,
                        'status' => 'published',
                        'published_at' => now(),
                    ],
                );
            });

            StudentProfile::whereKey($class->studentProfiles->pluck('id'))->update(['section_id' => $section->id]);
        });
    }
}
