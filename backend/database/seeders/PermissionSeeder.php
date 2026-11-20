<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Permission catalog covers every resource the API currently exposes.
     * New modules (courses, attendance, results, fees, ...) add their own
     * permissions here as those APIs are built in later phases.
     */
    public function run(): void
    {
        $permissions = [
            ['name' => 'divisions.view', 'label' => 'View divisions'],
            ['name' => 'divisions.create', 'label' => 'Create divisions'],
            ['name' => 'divisions.update', 'label' => 'Update divisions'],
            ['name' => 'divisions.delete', 'label' => 'Delete divisions'],

            ['name' => 'districts.view', 'label' => 'View districts'],
            ['name' => 'districts.create', 'label' => 'Create districts'],
            ['name' => 'districts.update', 'label' => 'Update districts'],
            ['name' => 'districts.delete', 'label' => 'Delete districts'],

            ['name' => 'upazilas.view', 'label' => 'View upazilas'],
            ['name' => 'upazilas.create', 'label' => 'Create upazilas'],
            ['name' => 'upazilas.update', 'label' => 'Update upazilas'],
            ['name' => 'upazilas.delete', 'label' => 'Delete upazilas'],

            ['name' => 'schools.view', 'label' => 'View schools'],
            ['name' => 'schools.create', 'label' => 'Create schools'],
            ['name' => 'schools.update', 'label' => 'Update schools'],
            ['name' => 'schools.delete', 'label' => 'Delete schools'],

            ['name' => 'classes.view', 'label' => 'View classes'],
            ['name' => 'classes.create', 'label' => 'Create classes'],
            ['name' => 'classes.update', 'label' => 'Update classes'],
            ['name' => 'classes.delete', 'label' => 'Delete classes'],

            ['name' => 'class_schools.view', 'label' => 'View class-school assignments'],
            ['name' => 'class_schools.create', 'label' => 'Assign a class to a school'],
            ['name' => 'class_schools.delete', 'label' => 'Unassign a class from a school'],
            ['name' => 'class_schools.updateSubjects', 'label' => 'Configure a school\'s subject list for a class'],

            ['name' => 'subjects.view', 'label' => 'View subjects'],
            ['name' => 'subjects.create', 'label' => 'Create subjects'],
            ['name' => 'subjects.update', 'label' => 'Update subjects'],
            ['name' => 'subjects.delete', 'label' => 'Delete subjects'],

            ['name' => 'books.view', 'label' => 'View books'],
            ['name' => 'books.create', 'label' => 'Create books'],
            ['name' => 'books.update', 'label' => 'Update books'],
            ['name' => 'books.delete', 'label' => 'Delete books'],

            ['name' => 'chapter_materials.view', 'label' => 'View chapter materials'],
            ['name' => 'chapter_materials.create', 'label' => 'Create chapter materials'],
            ['name' => 'chapter_materials.update', 'label' => 'Update chapter materials'],
            ['name' => 'chapter_materials.delete', 'label' => 'Delete chapter materials'],

            ['name' => 'chapter_videos.view', 'label' => 'View chapter videos'],
            ['name' => 'chapter_videos.create', 'label' => 'Create chapter videos'],
            ['name' => 'chapter_videos.update', 'label' => 'Update chapter videos'],
            ['name' => 'chapter_videos.delete', 'label' => 'Delete chapter videos'],

            ['name' => 'chapters.view', 'label' => 'View chapters'],
            ['name' => 'chapters.create', 'label' => 'Create chapters'],
            ['name' => 'chapters.update', 'label' => 'Update chapters'],
            ['name' => 'chapters.delete', 'label' => 'Delete chapters'],

            ['name' => 'questions.view', 'label' => 'View questions'],
            ['name' => 'questions.create', 'label' => 'Create questions'],
            ['name' => 'questions.update', 'label' => 'Update questions'],
            ['name' => 'questions.delete', 'label' => 'Delete questions'],

            ['name' => 'exams.view', 'label' => 'View exams'],
            ['name' => 'exams.create', 'label' => 'Create exams'],
            ['name' => 'exams.update', 'label' => 'Update exams'],
            ['name' => 'exams.delete', 'label' => 'Delete exams'],

            ['name' => 'exam_attempts.view', 'label' => 'View any exam attempt'],
            ['name' => 'exam_attempts.create', 'label' => 'Start, answer, and submit exam attempts'],

            ['name' => 'student_progress.view', 'label' => 'View any student\'s learning dashboard'],

            ['name' => 'users.view', 'label' => 'View users'],
            ['name' => 'users.create', 'label' => 'Create users'],
            ['name' => 'users.update', 'label' => 'Update users'],
            ['name' => 'users.delete', 'label' => 'Delete users'],

            ['name' => 'roles.view', 'label' => 'View roles'],
            ['name' => 'roles.create', 'label' => 'Create roles'],
            ['name' => 'roles.update', 'label' => 'Update roles'],
            ['name' => 'roles.delete', 'label' => 'Delete roles'],

            ['name' => 'permissions.view', 'label' => 'View permissions'],

            ['name' => 'teacher_profiles.view', 'label' => 'View teacher profiles'],
            ['name' => 'teacher_profiles.create', 'label' => 'Create teacher profiles'],
            ['name' => 'teacher_profiles.update', 'label' => 'Update teacher profiles'],
            ['name' => 'teacher_profiles.delete', 'label' => 'Delete teacher profiles'],

            ['name' => 'student_profiles.view', 'label' => 'View student profiles'],
            ['name' => 'student_profiles.create', 'label' => 'Create student profiles'],
            ['name' => 'student_profiles.update', 'label' => 'Update student profiles'],
            ['name' => 'student_profiles.delete', 'label' => 'Delete student profiles'],

            // A student submits their own leave application outside this
            // permission system entirely (StoreStudentLeaveApplicationRequest
            // just checks the actor is a StudentUser, same as how
            // chapter-materials/mine and exam attempt creation bypass
            // Role/Permission) — these two only gate a staff/teacher
            // actor's ability to review other students' applications.
            ['name' => 'student_leave_applications.view', 'label' => 'View student leave applications'],
            ['name' => 'student_leave_applications.update', 'label' => 'Review student leave applications'],

            ['name' => 'guardians.view', 'label' => 'View guardians'],
            ['name' => 'guardians.create', 'label' => 'Create guardians'],
            ['name' => 'guardians.update', 'label' => 'Update guardians'],
            ['name' => 'guardians.delete', 'label' => 'Delete guardians'],

            ['name' => 'academic_years.view', 'label' => 'View academic years'],
            ['name' => 'academic_years.create', 'label' => 'Create academic years'],
            ['name' => 'academic_years.update', 'label' => 'Update academic years'],
            ['name' => 'academic_years.delete', 'label' => 'Delete academic years'],

            ['name' => 'classrooms.view', 'label' => 'View classrooms'],
            ['name' => 'classrooms.create', 'label' => 'Create classrooms'],
            ['name' => 'classrooms.update', 'label' => 'Update classrooms'],
            ['name' => 'classrooms.delete', 'label' => 'Delete classrooms'],

            ['name' => 'class_routines.view', 'label' => 'View class routines'],
            ['name' => 'class_routines.create', 'label' => 'Create class routines'],
            ['name' => 'class_routines.update', 'label' => 'Update class routines'],
            ['name' => 'class_routines.delete', 'label' => 'Delete class routines'],

            ['name' => 'class_versions.view', 'label' => 'View class versions'],
            ['name' => 'class_versions.create', 'label' => 'Create class versions'],
            ['name' => 'class_versions.update', 'label' => 'Update class versions'],
            ['name' => 'class_versions.delete', 'label' => 'Delete class versions'],

            ['name' => 'shifts.view', 'label' => 'View shifts'],
            ['name' => 'shifts.create', 'label' => 'Create shifts'],
            ['name' => 'shifts.update', 'label' => 'Update shifts'],
            ['name' => 'shifts.delete', 'label' => 'Delete shifts'],

            ['name' => 'sections.view', 'label' => 'View sections'],
            ['name' => 'sections.create', 'label' => 'Create sections'],
            ['name' => 'sections.update', 'label' => 'Update sections'],
            ['name' => 'sections.delete', 'label' => 'Delete sections'],
            ['name' => 'sections.override_capacity', 'label' => 'Override section capacity on transfer'],

            ['name' => 'subject_teacher_assignments.view', 'label' => 'View subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.create', 'label' => 'Create subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.update', 'label' => 'Update subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.delete', 'label' => 'Delete subject-teacher assignments'],

            ['name' => 'timetable_entries.view', 'label' => 'View timetable entries (including drafts)'],
            ['name' => 'timetable_entries.create', 'label' => 'Create timetable entries'],
            ['name' => 'timetable_entries.update', 'label' => 'Update timetable entries'],
            ['name' => 'timetable_entries.delete', 'label' => 'Delete timetable entries'],
            ['name' => 'timetable_entries.publish', 'label' => 'Publish or revise published timetable entries'],
            ['name' => 'timetable_entries.generate', 'label' => 'Automatically generate timetable entries for a shift'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
