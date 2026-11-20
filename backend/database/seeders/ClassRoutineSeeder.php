<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\ClassRoutine;
use App\Models\ClassVersion;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\TeacherUser;
use Illuminate\Database\Seeder;

class ClassRoutineSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * A simple 5-period, Sunday–Thursday routine for the sample student's
     * class (Class 6, Debidwar Pilot Government High School), using the
     * subjects ClassSubjectSeeder already configured for that class.
     *
     * school_classes.name is national now, so "Class 6" is looked up by
     * name alone rather than by (school_id 1, name) — the routine's own
     * school_id/district_id come from School::find(1) directly, since
     * SchoolClass no longer carries either.
     *
     * Each row also carries section_id/shift_id/class_version_id so
     * ClassRoutineController::mine() can match it against the sample
     * student's own profile scope. This seeder runs before
     * SectionBackfillSeeder (see DatabaseSeeder), so the ClassVersion/
     * Shift/Section chain is resolved here with the exact same
     * firstOrCreate keys SectionBackfillSeeder uses for this same (class,
     * school) pair — whichever seeder runs first creates it, the other
     * just finds it, so there's no duplication either way.
     */
    public function run(): void
    {
        $class = SchoolClass::where('name', 'Class 6')->first();
        $school = School::find(1);
        $teacher = TeacherUser::where('email', 'teacher@tlap.test')->first();
        $classroom = Classroom::where('school_id', 1)->where('name', 'Room 101')->first();
        $academicYear = AcademicYear::where('is_current', true)->first();

        if (! $class || ! $school) {
            return;
        }

        $version = ClassVersion::firstOrCreate(
            ['class_id' => $class->id, 'school_id' => $school->id, 'name' => 'General'],
            ['district_id' => $school->district_id, 'is_active' => true],
        );

        $shift = Shift::firstOrCreate(
            ['class_version_id' => $version->id, 'name' => 'Day Shift'],
            [
                'school_id' => $school->id,
                'district_id' => $school->district_id,
                'academic_year_id' => $academicYear?->id,
                'start_time' => '08:00',
                'end_time' => '13:00',
                'days_of_week' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
                'is_active' => true,
            ],
        );

        $section = Section::firstOrCreate(
            ['shift_id' => $shift->id, 'name' => 'Main'],
            ['school_id' => $school->id, 'district_id' => $school->district_id, 'is_active' => true],
        );

        $periods = [
            ['period_no' => 1, 'subject' => 'Bangla', 'start_time' => '08:00', 'end_time' => '08:45'],
            ['period_no' => 2, 'subject' => 'English', 'start_time' => '08:45', 'end_time' => '09:30'],
            ['period_no' => 3, 'subject' => 'Mathematics', 'start_time' => '09:30', 'end_time' => '10:15'],
            ['period_no' => 4, 'subject' => 'Science', 'start_time' => '10:30', 'end_time' => '11:15'],
            ['period_no' => 5, 'subject' => 'Information and Communication Technology', 'start_time' => '11:15', 'end_time' => '12:00'],
        ];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
            foreach ($periods as $period) {
                $subject = Subject::where('name', $period['subject'])->first();

                if (! $subject) {
                    continue;
                }

                ClassRoutine::updateOrCreate(
                    ['class_id' => $class->id, 'school_id' => $school->id, 'day_of_week' => $day, 'period_no' => $period['period_no']],
                    [
                        'district_id' => $school->district_id,
                        'section_id' => $section->id,
                        'shift_id' => $shift->id,
                        'class_version_id' => $version->id,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacher?->id,
                        'classroom_id' => $classroom?->id,
                        'academic_year_id' => $academicYear?->id,
                        'start_time' => $period['start_time'],
                        'end_time' => $period['end_time'],
                    ],
                );
            }
        }
    }
}
