<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\ClassVersion;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use App\Models\Version;
use Illuminate\Database\Seeder;

class TimetableGenerationDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Configures Class 7 at Debidwar Pilot Government High School
     * (school_id 1) as a ready-to-generate demo for
     * `POST /shifts/{shift}/timetable-entries/generate`: a Shift with a
     * real period grid (45-minute periods, one break), a distinct teacher
     * per subject, and `required_weekly_periods` set on every subject —
     * none of it pre-published, so the generator has real work to do.
     *
     * Class 6 (seeded by ClassRoutineSeeder/SectionBackfillSeeder) is
     * deliberately left alone for this demo: its periods are already
     * published, so every subject would already be fully satisfied and
     * generate() would have nothing left to place.
     *
     * Depends on SectionBackfillSeeder having already created this
     * class's ClassVersion/Shift/Section; a no-op if that hasn't run.
     */
    public function run(): void
    {
        $class = SchoolClass::where('name', 'Class 7')->first();
        $school = School::find(1);

        if (! $class || ! $school) {
            return;
        }

        // class_versions.name/bn_name were dropped in favor of the linked
        // national Version — SectionBackfillSeeder links this seeder's
        // prerequisite ClassVersion to Bangla, so that's what's matched
        // here instead of the old "General" placeholder.
        $banglaVersionId = Version::where('name', 'Bangla')->value('id');
        $version = ClassVersion::where('class_id', $class->id)->where('school_id', $school->id)->where('version_id', $banglaVersionId)->first();
        $shift = $version ? Shift::where('class_version_id', $version->id)->where('name', 'Day Shift')->first() : null;
        $section = $shift ? Section::where('shift_id', $shift->id)->first() : null;

        if (! $version || ! $shift || ! $section) {
            return;
        }

        $shift->update([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
            'period_duration_minutes' => 45,
            'breaks' => [['start_time' => '10:30', 'end_time' => '10:45', 'label' => 'Tiffin']],
        ]);

        $classroom = Classroom::where('school_id', $school->id)->where('name', 'Room 102')->first();
        $section->update(['default_classroom_id' => $classroom?->id]);

        $teacherRoleId = Role::where('name', 'teacher')->value('id');

        // One distinct teacher per subject, each needing a different
        // weekly period count — exercises the generator's
        // most-constrained-first ordering and multi-teacher conflict
        // avoidance, not just a single-subject placement.
        $subjects = [
            'Bangla' => ['periods' => 5, 'teacher' => 'Nasrin Akter', 'email' => 'nasrin.akter@tlap.test'],
            'English' => ['periods' => 5, 'teacher' => 'Shafiqul Islam', 'email' => 'shafiqul.islam@tlap.test'],
            'Mathematics' => ['periods' => 5, 'teacher' => 'Karim Ahmed', 'email' => 'karim.ahmed@tlap.test'],
            'Science' => ['periods' => 4, 'teacher' => 'Farida Yasmin', 'email' => 'farida.yasmin@tlap.test'],
            'Bangladesh and Global Studies' => ['periods' => 3, 'teacher' => 'Rafiqul Hasan', 'email' => 'rafiqul.hasan@tlap.test'],
            'Information and Communication Technology' => ['periods' => 2, 'teacher' => 'Shirin Sultana', 'email' => 'shirin.sultana@tlap.test'],
            'Religious Studies' => ['periods' => 2, 'teacher' => 'Abdul Malek', 'email' => 'abdul.malek@tlap.test'],
        ];

        foreach ($subjects as $subjectName => $config) {
            $subject = Subject::where('name', $subjectName)->first();

            if (! $subject) {
                continue;
            }

            $version->subjects()->syncWithoutDetaching([
                $subject->id => ['required_weekly_periods' => $config['periods']],
            ]);

            $teacher = TeacherUser::updateOrCreate(
                ['email' => $config['email']],
                [
                    'name' => $config['teacher'],
                    'password' => 'password',
                    'role_id' => $teacherRoleId,
                    'school_id' => $school->id,
                    'district_id' => $school->district_id,
                    'email_verified_at' => now(),
                ],
            );

            SubjectTeacherAssignment::updateOrCreate(
                ['section_id' => $section->id, 'subject_id' => $subject->id],
                ['teacher_id' => $teacher->id, 'school_id' => $section->school_id, 'district_id' => $section->district_id],
            );
        }
    }
}
