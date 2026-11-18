<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassRoutine;
use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SectionBackfillSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Forward-migrates every existing (class, school) offering — now
     * tracked by class_schools, since school_classes itself carries no
     * school_id — into the new Version/Shift/Section structure introduced
     * by docs/Multi-Version-Multi-Shift-Multi-Section-Smart Timetable
     * Management.md, WITHOUT touching class_subjects or class_routines
     * data — those keep working exactly as before. For each (class,
     * school) pair this creates one default ClassVersion ("General"), one
     * default Shift ("Day Shift"), and one Section named "Main" (the old
     * school_classes.section string was dropped outright by the
     * school_classes restructure, not relocated here), then carries that
     * school's configured subjects, subject-teacher pairings, and routine
     * entries into the new tables, and points its enrolled students'
     * student_profiles.section_id at it.
     *
     * Iterates class_schools rather than school_classes: a class can now
     * be adopted by more than one school, and ClassRoutine/StudentProfile
     * rows sharing the same class_id must still be scoped to the one
     * school each assignment is backfilling, not mixed across schools
     * that happen to share the same national class.
     */
    public function run(): void
    {
        // 'subjects' is deliberately NOT eager-loaded here: ClassSchool::subjects()
        // ends in ->wherePivot('school_id', $this->school_id), and Eloquent's
        // eager loader resolves that relation method once on a fresh,
        // unhydrated model to build one batched query — so school_id would
        // bind as null there and the eager path would silently match zero
        // rows for every assignment. See ClassSchoolController::updateSubjects()
        // for the same pitfall documented in this same branch. class_subjects
        // is queried directly below instead, exactly like ClassSubjectSeeder.
        ClassSchool::with('schoolClass')->get()->each(function (ClassSchool $assignment) {
            $class = $assignment->schoolClass;

            if (! $class) {
                // The SchoolClass was soft-deleted; class_schools rows
                // aren't cascade-deleted alongside it (SoftDeletes has no
                // DB cascade), so this assignment is now orphaned.
                return;
            }

            $schoolId = $assignment->school_id;
            $districtId = $assignment->district_id;

            $academicYearId = AcademicYear::where('school_id', $schoolId)->where('is_current', true)->value('id');

            $version = ClassVersion::firstOrCreate(
                ['class_id' => $class->id, 'school_id' => $schoolId, 'name' => 'General'],
                ['district_id' => $districtId, 'is_active' => true],
            );

            $routines = ClassRoutine::where('class_id', $class->id)->where('school_id', $schoolId)->get();

            // Derived from the days the old class_routines actually uses,
            // falling back to the standard Bangladesh school week (Sunday
            // through Thursday) when there's no existing routine data.
            $daysOfWeek = $routines->pluck('day_of_week')->unique()->values()->all();

            $shift = Shift::firstOrCreate(
                ['class_version_id' => $version->id, 'name' => 'Day Shift'],
                [
                    'school_id' => $schoolId,
                    'district_id' => $districtId,
                    'academic_year_id' => $academicYearId,
                    'start_time' => '08:00',
                    'end_time' => '13:00',
                    'days_of_week' => $daysOfWeek ?: ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
                    'is_active' => true,
                ],
            );

            $section = Section::firstOrCreate(
                ['shift_id' => $shift->id, 'name' => 'Main'],
                ['school_id' => $schoolId, 'district_id' => $districtId, 'is_active' => true],
            );

            // Weekly period count per subject, derived from how many
            // distinct (day, period) slots the old class_routines already
            // uses — left null (unconfigured) when there's no existing data.
            $weeklyCounts = $routines->groupBy('subject_id')->map->count();

            $subjectIds = DB::table('class_subjects')
                ->where('class_id', $class->id)
                ->where('school_id', $schoolId)
                ->pluck('subject_id');

            $subjectSync = $subjectIds->mapWithKeys(fn (int $subjectId) => [
                $subjectId => ['required_weekly_periods' => $weeklyCounts->get($subjectId)],
            ]);
            $version->subjects()->syncWithoutDetaching($subjectSync->all());

            // subject_teacher_assignments.teacher_id is required, so only
            // routine rows that already have a teacher carry forward.
            $routines->whereNotNull('teacher_id')
                ->unique(fn (ClassRoutine $routine) => "{$routine->subject_id}:{$routine->teacher_id}")
                ->each(function (ClassRoutine $routine) use ($section) {
                    SubjectTeacherAssignment::firstOrCreate(
                        ['section_id' => $section->id, 'subject_id' => $routine->subject_id],
                        ['teacher_id' => $routine->teacher_id, 'school_id' => $section->school_id, 'district_id' => $section->district_id],
                    );
                });

            $routines->whereNotNull('teacher_id')->each(function (ClassRoutine $routine) use ($section, $academicYearId) {
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

            StudentProfile::where('class_id', $class->id)->where('school_id', $schoolId)->update(['section_id' => $section->id]);
        });
    }
}
